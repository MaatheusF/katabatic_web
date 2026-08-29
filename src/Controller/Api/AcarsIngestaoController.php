<?php

namespace App\Controller\Api;

use App\Entity\Voo;
use App\Event\AeronavePosicaoAtualizadaEvent;
use App\Entity\TipoAeronave;
use App\Repository\AeronaveRepository;
use App\Repository\PilotRepository;
use App\Repository\PosicaoAoVivoRepository;
use App\Repository\TipoAeronaveRepository;
use App\Repository\VooRepository;
use App\Service\PesquisaAmbienteCaptador;
use App\Service\PesquisaCapturaOrquestrador;
use App\Service\PesquisaRelatorioGerador;
use App\Service\PesquisaVooAggregator;
use App\Service\TelemetriaVooBuilder;
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
 * mora em `App\Service\TelemetryDeriver`; montar o `Voo` a partir dela
 * (pouso alternativo, peso vs. MTOW, través com heading de pista real,
 * carga estimada, METAR) mora em `App\Service\TelemetriaVooBuilder` — ver
 * docblock de lá pro porquê de estar numa classe à parte (compartilhada
 * com a importação manual de `upload_payload.json` em `NovoVooController`).
 * Este controller só valida, resolve `Pilot`/`Aeronave` e persiste.
 */
class AcarsIngestaoController extends AbstractController
{
    private const TIPOS_VALIDOS = ['Carga', 'Pesquisa', 'Pessoal', 'Reposicionamento', 'Medvec'];

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
        // Opcional — campo novo, cliente antigo que não manda continua
        // funcionando igual (só sem captura de pesquisa nesse voo). Ver
        // docblock de `Aeronave::$emVooTipoOperacao`.
        $tipoOperacao = (string) ($data['tipo_operacao'] ?? '');

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
        $aeronave->setEmVooTipoOperacao(\in_array($tipoOperacao, self::TIPOS_VALIDOS, true) ? $tipoOperacao : null);
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
        PesquisaAmbienteCaptador $pesquisaCaptador,
        PesquisaCapturaOrquestrador $pesquisaCapturaOrquestrador,
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

        // Camada de pesquisa meteorológica — melhor esforço, nunca pode
        // falhar este heartbeat (ver docblock de PesquisaAmbienteCaptador).
        // Roda depois do flush acima: a posição em si já está garantida
        // mesmo que a captura de pesquisa dê problema.
        $pesquisaCaptador->capturar($aeronave, (float) $lat, (float) $lon, $posicao->getAltFt(), $agora);
        $pesquisaCapturaOrquestrador->capturarSeNecessario($aeronave, (float) $lat, (float) $lon, $posicao->getAltFt(), $agora);

        return $this->json(['aeronave' => $aeronaveReg, 'status' => 'ok'], 200);
    }

    #[Route('/api/acars/v1/voos', name: 'app_api_acars_voos', methods: ['POST'])]
    public function ingerir(
        Request $request,
        EntityManagerInterface $em,
        PilotRepository $pilots,
        AeronaveRepository $aeronaves,
        VooRepository $voos,
        TipoAeronaveRepository $tipos,
        TelemetriaVooBuilder $builder,
        PesquisaVooAggregator $pesquisaAggregator,
        PesquisaRelatorioGerador $pesquisaRelatorio,
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
            $resultado = $builder->build($data, $aeronave, $origem, $destino);
        } catch (\Throwable $e) {
            return $this->json(['errors' => ['Payload de telemetria inválido: '.$e->getMessage()]], 422);
        }

        $tempoMin = max(1, (int) round($resultado['dur'] / 60));

        // Categoria da aeronave (asa fixa/rotativa) congelada no voo no
        // momento da ingestão - ver docblock de Voo::$categoriaAeronave.
        // 'Aviao' se o tipo dessa aeronave ainda não tem TipoAeronave
        // cadastrado (mesmo default gracioso de FerramentasController).
        $categoriaAeronave = $tipos->findOneByNome($aeronave->getTipo())?->getCategoria() ?? TipoAeronave::CATEGORIA_AVIAO;

        $voo = new Voo($pilot, $callsign, $tipoOperacao, $origem, $destino, $aeronaveReg, $startedAt, $tempoMin, $resultado['dificuldade'], $categoriaAeronave);
        $voo->setCodigo($codigo);

        $dados = $resultado['dados'];
        if ('Pesquisa' === $tipoOperacao) {
            // Congela o resumo das amostras ambiente capturadas ao vivo
            // durante este voo (ver docblock de PesquisaVooAggregator) —
            // `null` quando nenhuma amostra caiu na janela (sessão sem
            // heartbeat de posição, por exemplo), nesse caso a chave
            // simplesmente não é escrita.
            $pesquisa = $pesquisaAggregator->agregar($aeronaveReg, $startedAt, new \DateTimeImmutable());
            if (null !== $pesquisa) {
                // Relatório determinístico (sem IA, decisão tomada em
                // conversa) — congelado junto no mesmo POST de fechamento,
                // nunca recalculado depois. Ver docblock de
                // PesquisaRelatorioGerador.
                $pesquisa['relatorio'] = $pesquisaRelatorio->gerar(
                    $callsign,
                    $aeronaveReg,
                    $aeronave->getTipo(),
                    $origem,
                    $destino,
                    $startedAt,
                    $tempoMin,
                    $pesquisa,
                    \is_array($resultado['dados']['telemetria'] ?? null) ? $resultado['dados']['telemetria'] : null,
                );
                $dados['pesquisa'] = $pesquisa;
            }
        }
        $voo->setDados($dados);

        if (null !== $resultado['destinoReal']) {
            $voo->setDestinoReal($resultado['destinoReal']);
        }

        $em->persist($voo);

        // Pousou: some horas, atualiza posição (real, ver
        // TelemetriaVooBuilder) e devolve a aeronave pra "Disponível" -
        // independente de "iniciar" ter sido chamada com sucesso antes
        // (ver docblock da classe, as duas rotas são deliberadamente
        // desacopladas).
        $aeronave->setHoras($aeronave->getHoras() + (int) round($tempoMin / 60));
        $aeronave->setPosIcao($resultado['posIcao']);
        $aeronave->setStatus('Disponível');
        $aeronave->setEmVooDesde(null);
        $aeronave->setUltimoPingEm(null);
        $aeronave->setEmVooTipoOperacao(null);

        $em->flush();

        return $this->json(['codigo' => $codigo, 'voo_id' => $voo->getId()], 201);
    }
}
