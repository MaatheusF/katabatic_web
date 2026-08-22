<?php

namespace App\Controller\Api;

use App\Entity\Voo;
use App\Event\AeronavePosicaoAtualizadaEvent;
use App\Repository\AeronaveRepository;
use App\Repository\AeroportoRepository;
use App\Repository\PilotRepository;
use App\Repository\PosicaoAoVivoRepository;
use App\Repository\TipoAeronaveRepository;
use App\Repository\VooRepository;
use App\Service\MetarClient;
use App\Service\TelemetryDeriver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Ingestão ACARS — MVP, agora com posição em tempo real (fase 3).
 *
 * **O que isto NÃO é.** Não é o cliente/servidor completo do contrato
 * v1.1 (`docs/payload-telemetria-acars.md`) — não existe sessão
 * aberta/fechada de verdade, streaming de lotes em grupos A-F, fila
 * SQLite com reenvio, gzip, GRIB, METAR ou validação VATSIM. Decisão
 * tomada em conversa: começar pelo menor pedaço que já popula dado real
 * (Logbook, horas da frota, histórico de aeronave — fase 1), depois
 * ligar o status "Em voo" em tempo real (fase 2) e, agora, a posição em
 * si (fase 3) — ver README, "Backend: posição em tempo real (ACARS fase
 * 3)".
 *
 * **Três rotas, três momentos do voo:**
 *
 * - `POST .../voos/iniciar` — chamada quando a gravação **começa**.
 *   Marca `Aeronave::status = 'Em voo'` e guarda `emVooDesde` +
 *   `ultimoPingEm` (ver `Aeronave::getStatusEfetivo()`) — não cria nada
 *   em `voo`, porque ainda não há telemetria nenhuma pra derivar.
 *   Puramente informativa pro Mapa ao vivo; falha dela nunca aborta a
 *   gravação no cliente.
 * - `POST .../voos/posicao` — chamada periodicamente (a cada 10-15 s,
 *   proposto) **enquanto** o voo está em andamento. Grava a posição
 *   mais recente em `App\Entity\PosicaoAoVivo` (upsert, uma linha por
 *   aeronave — ver docblock da entidade) e atualiza
 *   `Aeronave::ultimoPingEm`. Exige que `iniciar` já tenha marcado a
 *   aeronave como `'Em voo'` — não promove sozinha (mantém uma única
 *   fonte pra "o voo começou"). Payload mínimo de propósito (só o Grupo
 *   A do contrato) — telemetria completa continua só em `.../voos`.
 * - `POST .../voos` — chamada quando a gravação **termina**. Deriva a
 *   telemetria inteira, persiste o `Voo` e devolve a aeronave pra
 *   `'Disponível'` na posição de destino — independente de `iniciar`/
 *   `posicao` terem sido chamadas com sucesso antes (as três são
 *   deliberadamente desacopladas: se `iniciar`/`posicao` falharam por
 *   causa de rede ruim durante o voo, o voo em si ainda é gravado
 *   certinho no final).
 *
 * **Atualizado: pouso alternativo (diversão).** `destino` no payload é
 * só o que o piloto **declarou** no plano de voo — antes desta fatia,
 * `ingerir()` confiava nele cegamente pra atualizar `Aeronave::posIcao`,
 * mesmo já tendo a telemetria real gravada mostrando onde a aeronave
 * pousou de verdade. Agora resolve o aeroporto real mais próximo do
 * ponto de pouso (`AeroportoRepository::findNearest()`, ver
 * `pousoRealIcao()`) e, quando ele diverge do `destino` declarado,
 * corrige a posição da aeronave pro real e grava `Voo::$destinoReal` —
 * ver docblock de `pousoRealIcao()` pro que acontece quando nenhum
 * aeroporto cadastrado está perto o bastante do pouso.
 *
 * As três são independentes de propósito: um POST de fechamento sem
 * `iniciar`/`posicao` correspondentes ainda funciona 100%; uma sessão
 * sem fechamento (PC do piloto travou) só deixa a aeronave "Em voo" até
 * `Aeronave::getStatusEfetivo()` se autocorrigir sozinha — em minutos
 * se havia heartbeat de posição, em horas se só `iniciar` foi chamada
 * (ver docblock da entidade) — não precisa de nenhum job/cron rodando
 * pra isso.
 *
 * **Autenticação.** Token único fixo (`ACARS_TOKEN` no `.env`), não por
 * piloto — quem chama informa `pilot_cid` no corpo, e é isso que decide
 * de quem é o voo. Revisar pra token por piloto quando houver mais de
 * um piloto voando com ACARS ao mesmo tempo (mesmo guard manual que o
 * resto do app usa — `access_control` continua vazio de propósito, ver
 * `config/packages/security.yaml`).
 *
 * **Idempotência.** `codigo` (nome da pasta de gravação do script,
 * `AAAAMMDD_HHMMSS_CALLSIGN`) é a chave em `.../voos`: um POST repetido
 * (retry manual depois de falha de rede) encontra o voo já criado e
 * devolve ele em vez de duplicar. `.../voos/posicao` não precisa dessa
 * chave — é sempre um upsert pela matrícula, então repetir/perder um
 * ping nunca duplica nada.
 *
 * A derivação de telemetria em si (fases, dificuldade, distância...)
 * mora em `App\Service\TelemetryDeriver` — este controller só valida,
 * resolve `Pilot`/`Aeronave` e persiste.
 *
 * **Atualizado: quatro enriquecimentos de realismo em `ingerir()`,
 * nenhum exigindo mudança no script de captura** (ver docblock de cada
 * um no corpo do método): peso real de decolagem vs. MTOW do tipo
 * (`ident.weight_lb`, já mandado pelo script mas nunca lido até então),
 * carga estimada por subtração (substitui o texto fixo "Não informada
 * pelo ACARS" quando dá pra calcular), METAR real da origem
 * (`App\Service\MetarClient`, substitui o `null` fixo) e través
 * recalculado contra o heading de pista real quando o aeroporto de
 * pouso tem essa informação cadastrada (`Aeroporto::$pistaPrincipalHeadingMag`,
 * `TelemetryDeriver::recomputeWindcComHeadingDePista()`). Todos "melhor
 * esforço": nenhum bloqueia a gravação do voo se faltar dado.
 */
class AcarsIngestaoController extends AbstractController
{
    private const TIPOS_VALIDOS = ['Carga', 'Pesquisa', 'Pessoal', 'Reposicionamento'];

    public function __construct(
        #[Autowire('%env(ACARS_TOKEN)%')] private readonly string $token,
    ) {
    }

    /**
     * Chamada no início da gravação — só sinaliza "esta aeronave está
     * voando agora" pro Mapa ao vivo, sem tocar em `voo`. Não exige
     * `codigo` único de negócio nenhum (não cria linha nenhuma) — se
     * chamada de novo pra mesma aeronave, só reescreve `emVooDesde` com
     * o horário mais recente, sem problema.
     */
    #[Route('/api/acars/v1/voos/iniciar', name: 'app_api_acars_iniciar', methods: ['POST'])]
    public function iniciar(Request $request, EntityManagerInterface $em, PilotRepository $pilots, AeronaveRepository $aeronaves): JsonResponse
    {
        $auth = $request->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ') || !hash_equals($this->token, substr($auth, 7))) {
            return $this->json(['error' => 'Token inválido.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || JSON_ERROR_NONE !== json_last_error()) {
            return $this->json(['error' => 'Payload não é JSON válido.'], 400);
        }

        $pilotCid = trim((string) ($data['pilot_cid'] ?? ''));
        $aeronaveReg = strtoupper(trim((string) ($data['aeronave_reg'] ?? '')));
        $startedAtRaw = (string) ($data['started_at'] ?? '');

        $errors = [];
        if ('' === $pilotCid) {
            $errors[] = 'Faltou "pilot_cid".';
        }
        if ('' === $aeronaveReg) {
            $errors[] = 'Faltou "aeronave_reg".';
        }
        if ('' === $startedAtRaw) {
            $errors[] = 'Faltou "started_at".';
        }
        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        if (null === $pilots->findOneByCid($pilotCid)) {
            return $this->json(['errors' => [sprintf('Piloto com CID "%s" não encontrado.', $pilotCid)]], 422);
        }

        $aeronave = $aeronaves->findOneByReg($aeronaveReg);
        if (null === $aeronave) {
            return $this->json(['errors' => [sprintf('Aeronave "%s" não cadastrada na frota — cadastre em Nova aeronave antes.', $aeronaveReg)]], 422);
        }

        try {
            $startedAt = new \DateTimeImmutable($startedAtRaw);
        } catch (\Throwable $e) {
            return $this->json(['errors' => ['"started_at" inválido: '.$e->getMessage()]], 422);
        }

        $aeronave->setStatus('Em voo');
        $aeronave->setEmVooDesde($startedAt);
        // Também conta como o primeiro heartbeat — sem esperar o primeiro
        // ping de posição pra `getStatusEfetivo()` já ter uma referência
        // "recente" (senão ficaria alguns segundos só na janela antiga de
        // `EM_VOO_MAX_HORAS` até o cliente mandar o 1º `.../voos/posicao`).
        $aeronave->setUltimoPingEm($startedAt);
        $em->flush();

        return $this->json(['aeronave' => $aeronaveReg, 'status' => 'Em voo'], 200);
    }

    /**
     * Heartbeat de posição — chamado periodicamente enquanto o voo está
     * em andamento (ver docblock da classe). Não cria nem altera nada em
     * `voo`; só atualiza "onde a aeronave está agora" pro Mapa ao vivo
     * consultar via polling (`GET /mapa-ao-vivo/posicoes`).
     *
     * Payload mínimo — só o Grupo A do contrato completo
     * (`docs/payload-telemetria-acars.md`): `lat`, `lon` obrigatórios;
     * `alt_ft`, `hdg_true`, `gs_kt`, `ias_kt`, `vs_fpm`, `on_ground`
     * opcionais (o cliente manda o que tiver — nenhum deles afeta a
     * validação, só empobrece o popup/HUD no mapa se faltar).
     */
    #[Route('/api/acars/v1/voos/posicao', name: 'app_api_acars_posicao', methods: ['POST'])]
    public function posicao(
        Request $request,
        EntityManagerInterface $em,
        PilotRepository $pilots,
        AeronaveRepository $aeronaves,
        PosicaoAoVivoRepository $posicoes,
        EventDispatcherInterface $eventDispatcher,
    ): JsonResponse {
        $auth = $request->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ') || !hash_equals($this->token, substr($auth, 7))) {
            return $this->json(['error' => 'Token inválido.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || JSON_ERROR_NONE !== json_last_error()) {
            return $this->json(['error' => 'Payload não é JSON válido.'], 400);
        }

        $pilotCid = trim((string) ($data['pilot_cid'] ?? ''));
        $aeronaveReg = strtoupper(trim((string) ($data['aeronave_reg'] ?? '')));
        $atRaw = (string) ($data['at'] ?? '');
        $lat = $data['lat'] ?? null;
        $lon = $data['lon'] ?? null;

        $errors = [];
        if ('' === $pilotCid) {
            $errors[] = 'Faltou "pilot_cid".';
        }
        if ('' === $aeronaveReg) {
            $errors[] = 'Faltou "aeronave_reg".';
        }
        if (!is_numeric($lat) || !is_numeric($lon)) {
            $errors[] = 'Faltou "lat"/"lon" (ou não são numéricos).';
        } elseif ((float) $lat < -90 || (float) $lat > 90 || (float) $lon < -180 || (float) $lon > 180) {
            // Ninguém confia no cliente (princípio 3 do contrato) - mesmo
            // sendo só um heartbeat de posição, uma coordenada fora do
            // globo é sinal de payload corrompido, não de dado ruim mas
            // válido.
            $errors[] = '"lat"/"lon" fora do intervalo válido.';
        }
        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        if (null === $pilots->findOneByCid($pilotCid)) {
            return $this->json(['errors' => [sprintf('Piloto com CID "%s" não encontrado.', $pilotCid)]], 422);
        }

        $aeronave = $aeronaves->findOneByReg($aeronaveReg);
        if (null === $aeronave) {
            return $this->json(['errors' => [sprintf('Aeronave "%s" não cadastrada na frota — cadastre em Nova aeronave antes.', $aeronaveReg)]], 422);
        }

        // Exige "iniciar" antes - mantém uma única fonte pra "o voo
        // começou" em vez do heartbeat de posição também poder promover
        // a aeronave sozinho (ver docblock da classe).
        if ('Em voo' !== $aeronave->getStatus()) {
            return $this->json(['errors' => ['Aeronave não está "Em voo" - chame "iniciar" antes de mandar posição.']], 422);
        }

        $agora = new \DateTimeImmutable();
        try {
            $at = '' !== $atRaw ? new \DateTimeImmutable($atRaw) : $agora;
        } catch (\Throwable $e) {
            return $this->json(['errors' => ['"at" inválido: '.$e->getMessage()]], 422);
        }

        $posicao = $posicoes->upsert($aeronave);
        $posicao->setLat((float) $lat);
        $posicao->setLon((float) $lon);
        $posicao->setAltFt(is_numeric($data['alt_ft'] ?? null) ? (int) round((float) $data['alt_ft']) : null);
        $posicao->setHdgTrue(is_numeric($data['hdg_true'] ?? null) ? (float) $data['hdg_true'] : null);
        $posicao->setGsKt(is_numeric($data['gs_kt'] ?? null) ? (int) round((float) $data['gs_kt']) : null);
        $posicao->setIasKt(is_numeric($data['ias_kt'] ?? null) ? (int) round((float) $data['ias_kt']) : null);
        $posicao->setVsFpm(is_numeric($data['vs_fpm'] ?? null) ? (int) round((float) $data['vs_fpm']) : null);
        $posicao->setOnGround(is_bool($data['on_ground'] ?? null) ? $data['on_ground'] : null);
        $posicao->setRegistradaEm($at);
        $posicao->setRecebidaEm($agora);

        $aeronave->setUltimoPingEm($agora);

        $em->flush();

        // Seam pronta pra um push futuro (Mercure/WebSocket) sem tocar
        // aqui de novo - ver docblock de AeronavePosicaoAtualizadaEvent.
        // Nenhum listener registrado hoje: o Mapa ao vivo aprende disso
        // por polling, não por este evento.
        $eventDispatcher->dispatch(new AeronavePosicaoAtualizadaEvent($aeronave, $posicao));

        return $this->json(['aeronave' => $aeronaveReg, 'status' => 'ok'], 200);
    }

    #[Route('/api/acars/v1/voos', name: 'app_api_acars_voos', methods: ['POST'])]
    public function ingerir(
        Request $request,
        EntityManagerInterface $em,
        PilotRepository $pilots,
        AeronaveRepository $aeronaves,
        AeroportoRepository $aeroportos,
        VooRepository $voos,
        TipoAeronaveRepository $tiposAeronave,
        TelemetryDeriver $deriver,
        MetarClient $metarClient,
    ): JsonResponse {
        $auth = $request->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ') || !hash_equals($this->token, substr($auth, 7))) {
            return $this->json(['error' => 'Token inválido.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || JSON_ERROR_NONE !== json_last_error()) {
            return $this->json(['error' => 'Payload não é JSON válido.'], 400);
        }

        $codigo = trim((string) ($data['codigo'] ?? ''));
        $pilotCid = trim((string) ($data['pilot_cid'] ?? ''));
        $callsign = trim((string) ($data['callsign'] ?? ''));
        $tipoOperacao = (string) ($data['tipo_operacao'] ?? '');
        $origem = strtoupper(trim((string) ($data['origem'] ?? '')));
        $destino = strtoupper(trim((string) ($data['destino'] ?? '')));
        $aeronaveReg = strtoupper(trim((string) ($data['aeronave_reg'] ?? '')));
        $startedAtRaw = (string) ($data['started_at'] ?? '');
        $samples = is_array($data['samples'] ?? null) ? $data['samples'] : [];

        $errors = [];
        if ('' === $codigo) {
            $errors[] = 'Faltou "codigo".';
        }
        if ('' === $pilotCid) {
            $errors[] = 'Faltou "pilot_cid".';
        }
        if ('' === $callsign) {
            $errors[] = 'Faltou "callsign".';
        }
        if (!in_array($tipoOperacao, self::TIPOS_VALIDOS, true)) {
            $errors[] = sprintf('"tipo_operacao" precisa ser um de: %s.', implode(', ', self::TIPOS_VALIDOS));
        }
        if ('' === $origem || '' === $destino) {
            $errors[] = 'Faltou "origem" ou "destino".';
        }
        if ('' === $aeronaveReg) {
            $errors[] = 'Faltou "aeronave_reg".';
        }
        if ('' === $startedAtRaw) {
            $errors[] = 'Faltou "started_at".';
        }
        if (!$samples) {
            $errors[] = '"samples" veio vazio — nada pra derivar.';
        }
        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        // idempotência: retry do cliente depois de falha de rede não pode
        // duplicar o voo - devolve o que já existe em vez de criar de novo.
        $existente = $voos->findOneByCodigo($codigo);
        if (null !== $existente) {
            return $this->json(['codigo' => $existente->getCodigo(), 'voo_id' => $existente->getId(), 'duplicado' => true], 200);
        }

        $pilot = $pilots->findOneByCid($pilotCid);
        if (null === $pilot) {
            return $this->json(['errors' => [sprintf('Piloto com CID "%s" não encontrado.', $pilotCid)]], 422);
        }

        $aeronave = $aeronaves->findOneByReg($aeronaveReg);
        if (null === $aeronave) {
            return $this->json(['errors' => [sprintf('Aeronave "%s" não cadastrada na frota — cadastre em Nova aeronave antes.', $aeronaveReg)]], 422);
        }

        try {
            $startedAt = new \DateTimeImmutable($startedAtRaw);
            $derivado = $deriver->derive($data, $aeronave->getLimiteG());
        } catch (\Throwable $e) {
            return $this->json(['errors' => ['Payload de telemetria inválido: '.$e->getMessage()]], 422);
        }

        $tempoMin = max(1, (int) round($derivado['dur'] / 60));

        $voo = new Voo($pilot, $callsign, $tipoOperacao, $origem, $destino, $aeronaveReg, $startedAt, $tempoMin, $derivado['dificuldade']);
        $voo->setCodigo($codigo);

        $telemetria = $derivado['telemetria'];

        // Pouso alternativo (diversão): resolve onde a aeronave pousou de
        // verdade (telemetria), não onde o plano de voo dizia que ela ia
        // pousar - ver pousoRealIcao() e docblock da classe. Resolvido
        // ANTES de montar `dados` (diferente de antes desta fatia) porque
        // o través com heading de pista real, logo abaixo, precisa saber
        // qual é o aeroporto de pouso pra procurar o heading cadastrado
        // nele. `$posIcao` é sempre o que vira Aeronave::posIcao lá
        // embaixo; $destino em si (a rota declarada) nunca muda, é o que
        // o Logbook sempre mostrou.
        $posIcao = $destino;
        $real = $this->pousoRealIcao($telemetria, $aeroportos);
        if (null !== $real && $real !== $destino) {
            $posIcao = $real;
        }

        $tipoAeronave = $tiposAeronave->findOneByNome($aeronave->getTipo());

        // Peso real de decolagem vs. MTOW do tipo. `ident.weight_lb` já é
        // mandado pelo script de captura (TOTAL WEIGHT, lido uma vez no
        // início da sessão - ver docs/payload-telemetria-acars.md, seção
        // 3.1) mas nunca foi lido no servidor até esta fatia; MTOW vem do
        // TipoAeronave cadastrado em /tipos-aeronave, quando existir.
        // Ambos ficam `null` (tile some no relatório, ver voo.js) quando
        // faltar qualquer um dos dois - nunca um número inventado.
        $identData = is_array($data['ident'] ?? null) ? $data['ident'] : [];
        $pesoDecolagemLb = is_numeric($identData['weight_lb'] ?? null) ? (int) round((float) $identData['weight_lb']) : null;
        $fuelInicialLb = is_numeric($identData['fuel_lb'] ?? null) ? (float) $identData['fuel_lb'] : null;

        $telemetria['pesoDecolagemLb'] = $pesoDecolagemLb;
        $telemetria['pesoMaxDecolagemLb'] = $tipoAeronave?->getPesoMaxDecolagemLb();

        // Través com heading de pista real: só quando o aeroporto de
        // pouso (real, resolvido acima) tem um heading cadastrado (ver
        // Aeroporto::$pistaPrincipalHeadingMag) - senão fica como sempre
        // esteve (aproximado pelo heading da aeronave no toque). windc já
        // vinha arredondado por TelemetryDeriver::derive(); recalculamos
        // do zero em vez de tentar "corrigir" o valor arredondado.
        $telemetria['windcFonte'] = 'aeronave';
        $aeroportoPouso = $aeroportos->findOneByIcao($posIcao);
        $headingPista = $aeroportoPouso?->getPistaPrincipalHeadingMag();
        if (null !== $headingPista) {
            $windcPista = $deriver->recomputeWindcComHeadingDePista($telemetria, (float) $headingPista);
            if (null !== $windcPista) {
                $telemetria['windc'] = round($windcPista);
                $telemetria['windcFonte'] = 'pista';
            }
        }

        $condTag = match ($telemetria['wx']) {
            'Neve' => 'bad',
            'Chuva' => 'warn',
            default => 'ok',
        };
        $ocorrencias = array_values(array_filter(array_map(
            static fn (array $ev) => in_array($ev[4], ['bad', 'warn'], true) && '' !== $ev[2]
                ? ['label' => $ev[2], 'tag' => $ev[4]]
                : null,
            $telemetria['events']
        )));

        // Carga/payload estimada por subtração (peso total - peso vazio
        // do tipo - combustível inicial) em vez de pedida ao piloto -
        // funciona com o payload que o script já manda hoje, sem exigir
        // nenhum campo novo nele. "Estimada" de propósito no texto: é
        // peso total menos o que já sabemos, não uma medição direta de
        // carga (bagagem pesada mal distribuída, por exemplo, não muda o
        // número, só o CG - que esta conta nem tenta calcular).
        $pesoVazioLb = $tipoAeronave?->getPesoVazioLb();
        if (null !== $pesoDecolagemLb && null !== $pesoVazioLb) {
            $cargaEstimadaLb = max(0, (int) round($pesoDecolagemLb - $pesoVazioLb - ($fuelInicialLb ?? 0.0)));
            $carga = sprintf('~%d lb (estimado: peso total − peso vazio − combustível)', $cargaEstimadaLb);
        } elseif (null !== $pesoDecolagemLb) {
            $carga = 'Peso vazio do tipo não cadastrado — carga não estimada';
        } else {
            $carga = 'Não informada pelo ACARS';
        }

        // METAR real da origem (aproximação: mais recente publicado no
        // momento em que este POST é processado, não o METAR histórico
        // de verdade do horário do voo - ver docblock de MetarClient).
        // Melhor esforço: uma falha aqui nunca impede o voo de ser
        // gravado, só deixa o campo com o texto de fallback de sempre.
        try {
            $metarOrigem = $metarClient->buscarMaisRecente($origem);
        } catch (\Throwable) {
            $metarOrigem = null;
        }

        $voo->setDados([
            'rota' => $origem.' → '.$destino,
            'modelo' => $aeronave->getTipo(),
            'cond' => $telemetria['wx'],
            'condTag' => $condTag,
            'ocorrencias' => $ocorrencias,
            'dist' => $telemetria['dist'],
            'combustivelKg' => round($telemetria['fuel'] * 0.453592, 1),
            'carga' => $carga,
            'tempoSoloMin' => (int) round($telemetria['ground_s'] / 60),
            'tempoArMin' => (int) round($telemetria['air_s'] / 60),
            'metar' => $metarOrigem ?? 'Não disponível',
            'pilotReport' => null,
            'telemetria' => $telemetria,
        ]);

        if (null !== $real && $real !== $destino) {
            $voo->setDestinoReal($real);
        }

        $em->persist($voo);

        // Pousou: some horas, atualiza posição (real, ver acima) e
        // devolve a aeronave pra "Disponível" - independente de
        // "iniciar" ter sido chamada com sucesso antes (ver docblock da
        // classe, as duas rotas são deliberadamente desacopladas).
        $aeronave->setHoras($aeronave->getHoras() + (int) round($tempoMin / 60));
        $aeronave->setPosIcao($posIcao);
        $aeronave->setStatus('Disponível');
        $aeronave->setEmVooDesde(null);
        $aeronave->setUltimoPingEm(null);

        $em->flush();

        return $this->json(['codigo' => $codigo, 'voo_id' => $voo->getId()], 201);
    }

    /**
     * ICAO do aeroporto cadastrado mais próximo de onde a aeronave
     * pousou de verdade — `null` quando não dá pra saber (sem ponto de
     * pouso utilizável) ou quando nenhum aeroporto cadastrado está perto
     * o bastante (`AeroportoRepository::RAIO_PADRAO_KM`): nesses casos
     * `ingerir()` mantém o comportamento de sempre (confia no `destino`
     * declarado) — mais seguro do que "corrigir" a posição da aeronave
     * pra um aeroporto que pode não ser o de verdade.
     *
     * Prioriza o evento `touchdown` (`$telemetria['td']['lat']/['lon']`,
     * ver `TelemetryDeriver::deriveEvents()`) por ser o ponto exato do
     * toque; cai pra última amostra de `$telemetria['track']` quando não
     * há evento de toque utilizável (cliente antigo, ou pouso sem o
     * evento chegar) — ainda dentro do raio de qualquer aeródromo
     * normal, mesmo com a imprecisão de ser só a amostra de 1 Hz mais
     * próxima do fim da gravação, não o toque exato.
     *
     * `$data['td_lat']/['td_lon']` viram `0.0` (não `null`) em
     * `deriveEvents()` quando o cliente não manda um evento `touchdown`
     * com essas coordenadas — `0.0, 0.0` (só existe no meio do Oceano
     * Atlântico) não é um ponto de pouso de verdade nesta rede
     * (Alasca/Patagônia), então é tratado como "sem coordenada de toque"
     * e cai pro fallback da última amostra.
     *
     * @param array<string, mixed> $telemetria
     */
    private function pousoRealIcao(array $telemetria, AeroportoRepository $aeroportos): ?string
    {
        $lat = null;
        $lon = null;

        $td = $telemetria['td'] ?? null;
        if (is_array($td) && (($td['lat'] ?? 0.0) !== 0.0 || ($td['lon'] ?? 0.0) !== 0.0)) {
            $lat = (float) $td['lat'];
            $lon = (float) $td['lon'];
        } else {
            $track = $telemetria['track'] ?? [];
            $ultima = is_array($track) && [] !== $track ? end($track) : null;
            if (is_array($ultima) && isset($ultima[1], $ultima[2])) {
                $lat = (float) $ultima[1];
                $lon = (float) $ultima[2];
            }
        }

        if (null === $lat || null === $lon) {
            return null;
        }

        return $aeroportos->findNearest($lat, $lon)?->getIcao();
    }
}
