<?php

namespace App\Controller\Api;

use App\Entity\Voo;
use App\Repository\AeronaveRepository;
use App\Repository\PilotRepository;
use App\Repository\VooRepository;
use App\Service\TelemetryDeriver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ingestão ACARS — MVP.
 *
 * **O que isto NÃO é.** Não é o cliente/servidor completo do contrato
 * v1.1 (`docs/payload-telemetria-acars.md`) — não existe streaming de
 * lotes em tempo real, fila SQLite com reenvio, gzip, GRIB, METAR ou
 * validação VATSIM. Decisão tomada em conversa: começar pelo menor
 * pedaço que já popula dado real (Logbook, horas da frota, histórico de
 * aeronave), depois ligar o status "Em voo" em tempo real (esta fase 2)
 * — ver README, "Backend: ingestão ACARS (MVP)".
 *
 * **Duas rotas, dois momentos do voo:**
 *
 * - `POST .../voos/iniciar` — chamada quando a gravação **começa**.
 *   Só marca `Aeronave::status = 'Em voo'` (e guarda `emVooDesde`, ver
 *   `Aeronave::getStatusEfetivo()`) — não cria nada em `voo`, porque
 *   ainda não há telemetria nenhuma pra derivar. Puramente informativa
 *   pro Mapa ao vivo; falha dela nunca aborta a gravação no cliente.
 * - `POST .../voos` — chamada quando a gravação **termina** (fim
 *   original desta fatia). Deriva a telemetria inteira, persiste o
 *   `Voo` e devolve a aeronave pra `'Disponível'` na posição de
 *   destino — independente de `iniciar` ter sido chamada com sucesso
 *   antes (as duas são deliberadamente desacopladas: se `iniciar`
 *   falhou por causa de uma rede ruim no início do voo, o voo em si
 *   ainda é gravado certinho no final).
 *
 * As duas são independentes de propósito: um POST de fechamento sem
 * `iniciar` correspondente ainda funciona 100%; um `iniciar` sem
 * fechamento correspondente (PC do piloto travou) só deixa a aeronave
 * "Em voo" até `Aeronave::getStatusEfetivo()` se autocorrigir depois de
 * `Aeronave::EM_VOO_MAX_HORAS` — não precisa de nenhum job/cron rodando
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
 * `AAAAMMDD_HHMMSS_CALLSIGN`) é a chave: um POST repetido (retry manual
 * depois de falha de rede) encontra o voo já criado e devolve ele em
 * vez de duplicar.
 *
 * A derivação de telemetria em si (fases, dificuldade, distância...)
 * mora em `App\Service\TelemetryDeriver` — este controller só valida,
 * resolve `Pilot`/`Aeronave` e persiste.
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
        $em->flush();

        return $this->json(['aeronave' => $aeronaveReg, 'status' => 'Em voo'], 200);
    }

    #[Route('/api/acars/v1/voos', name: 'app_api_acars_voos', methods: ['POST'])]
    public function ingerir(
        Request $request,
        EntityManagerInterface $em,
        PilotRepository $pilots,
        AeronaveRepository $aeronaves,
        VooRepository $voos,
        TelemetryDeriver $deriver,
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

        $voo->setDados([
            'rota' => $origem.' → '.$destino,
            'modelo' => $aeronave->getTipo(),
            'cond' => $telemetria['wx'],
            'condTag' => $condTag,
            'ocorrencias' => $ocorrencias,
            'dist' => $telemetria['dist'],
            'combustivelKg' => round($telemetria['fuel'] * 0.453592, 1),
            // ACARS ainda não manda carga/peso transportado - ver
            // "Lacunas conhecidas" no README, não inventar um número aqui.
            'carga' => 'Não informada pelo ACARS',
            'tempoSoloMin' => (int) round($telemetria['ground_s'] / 60),
            'tempoArMin' => (int) round($telemetria['air_s'] / 60),
            // METAR não é buscado nesta fatia (ver TelemetryDeriver e README).
            'metar' => null,
            'pilotReport' => null,
            'telemetria' => $telemetria,
        ]);

        $em->persist($voo);

        // Pousou: some horas, atualiza posição e devolve a aeronave pra
        // "Disponível" - independente de "iniciar" ter sido chamada com
        // sucesso antes (ver docblock da classe, as duas rotas são
        // deliberadamente desacopladas).
        $aeronave->setHoras($aeronave->getHoras() + (int) round($tempoMin / 60));
        $aeronave->setPosIcao($destino);
        $aeronave->setStatus('Disponível');
        $aeronave->setEmVooDesde(null);

        $em->flush();

        return $this->json(['codigo' => $codigo, 'voo_id' => $voo->getId()], 201);
    }
}
