<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Entity\Pilot;
use App\Entity\TipoAeronave;
use App\Entity\Voo;
use App\Entity\VooRascunho;
use App\Repository\AeronaveRepository;
use App\Repository\PilotRepository;
use App\Repository\TipoAeronaveRepository;
use App\Repository\VooRascunhoRepository;
use App\Repository\VooRepository;
use App\Service\TelemetriaVooBuilder;
use App\Service\TelemetryDeriver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registro de voo: importar telemetria (upload do `upload_payload.json`
 * gerado pelo script de captura) ou registro manual.
 *
 * **Atualizado (backend real):** a frota do seletor de aeronave agora
 * vem de `App\Entity\Aeronave` (ver "Backend: mapa ao vivo e histórico
 * da frota") em vez de um array mock próprio.
 *
 * **Atualizado: registro manual grava de verdade.** `publicar()` abaixo
 * cria um `Voo` real (ver README, "Novo voo") quando o modo é
 * "Registro manual".
 *
 * **Atualizado: "Importar telemetria" agora tem backend de verdade.**
 * `importarPreview()`/`importarPublicar()` abaixo substituem a simulação
 * que existia antes (nomes de arquivo fixos, `alert()` de "publicado
 * (mock)") por um fluxo real de duas etapas: o piloto seleciona o
 * `upload_payload.json` que `katabatic_capture.py --record` sempre grava
 * na pasta da gravação (mesmo arquivo que o script tentaria mandar
 * sozinho pro `Api\AcarsIngestaoController` se `--server`/`--token`
 * tivessem sido passados — ver docstring do script, "Payload pronto...
 * pra reenviar manualmente depois"), o navegador lê e valida o JSON,
 * manda pra `importarPreview()` calcular duração/distância/temperatura
 * mínima/dificuldade de verdade (via `TelemetryDeriver`, sem persistir
 * nada), e só ao clicar "Publicar" o mesmo payload (mais os campos que o
 * piloto ajustou no formulário) vai pra `importarPublicar()`, que monta
 * e grava o `Voo` via `App\Service\TelemetriaVooBuilder` — a mesma
 * classe que `Api\AcarsIngestaoController::ingerir()` usa pra ingestão
 * ao vivo, garantindo os mesmos quatro enriquecimentos "melhor esforço"
 * (peso vs. MTOW, través com heading de pista, carga estimada, METAR).
 *
 * **Por que `upload_payload.json` e não `samples.csv`/`env.csv`/
 * `events.csv`/`session.json` separados** (como o texto da tela dizia
 * antes de existir backend real): o script de captura já escreve esse
 * único arquivo, sempre, no formato exato que `TelemetryDeriver::derive()`
 * espera (mesmo schema `kb-raw-1` que vai pro ACARS) — reconstruir o
 * mesmo payload no servidor a partir dos CSVs crus (linhas como string,
 * `data` de `events.csv` re-serializado, sem `started_at`/`ident`/
 * `callsign` que só existem no JSON) seria retrabalho frágil pra chegar
 * exatamente onde o script já deixa pronto. `session.json` sozinho NÃO
 * serve pra isso — é só o resumo final (`samples`/`events` ali são
 * contagens, não as listas) — por isso a tela pede especificamente o
 * `upload_payload.json`.
 *
 * **Atualizado: "Salvar rascunho" persiste de verdade.** `App\Entity\
 * VooRascunho` (tabela própria, um rascunho por piloto — ver docblock
 * dela pra por que não é um status novo em `Voo`) guarda o snapshot cru
 * do formulário (`salvarRascunho()` abaixo); `index()` busca o
 * rascunho do piloto ao abrir a tela e devolve pro template, que
 * `novo-voo.js` usa pra pré-preencher o formulário sozinho, sem o
 * piloto precisar clicar em nada. Publicar (nos dois modos) apaga o
 * rascunho — vira um voo de verdade, não faz mais sentido continuar
 * "em rascunho".
 */
class NovoVooController extends AbstractController
{
    /** Mesmo conjunto de `Api\AcarsIngestaoController::TIPOS_VALIDOS` — mantenha os dois em sincronia. */
    private const TIPOS_VALIDOS = ['Carga', 'Pesquisa', 'Pessoal', 'Reposicionamento', 'Medvec'];

    /**
     * Ocorrências que o registro manual aceita, com a mesma severidade que
     * `TelemetryDeriver` usa pras equivalentes detectadas por telemetria
     * (pouso duro/estol/overspeed = 'bad', quique = 'warn') — mantém a cor
     * do selo consistente entre voos manuais e automáticos no Logbook.
     *
     * As três últimas (Autorrotação/LTE/Vortex ring state) são
     * específicas de helicóptero — pedido em conversa, junto da categoria
     * Avião/Helicóptero em `TipoAeronave`. `novo-voo.js` só mostra esses
     * três chips quando a aeronave selecionada é da categoria
     * 'Helicoptero' (ver `KATABATIC_AIRCRAFT[i].categoria` abaixo), pra
     * não poluir o formulário de quem voa asa fixa com ocorrência que não
     * se aplica. **Só entram por registro manual** — `TelemetryDeriver`
     * ainda não tem heurística nenhuma pra detectar essas três a partir de
     * telemetria (diferente das quatro de cima, que vêm de eventos reais
     * do script ACARS); ficam de fora do "índice comparativo entre voos"
     * junto com o resto do registro manual (ver `novovoo.warn.unverified`
     * no template).
     */
    private const OCORRENCIA_TAGS = [
        'Pouso duro' => 'bad',
        'Quique' => 'warn',
        'Overspeed' => 'bad',
        'Estol' => 'bad',
        'Autorrotação' => 'bad',
        'LTE' => 'bad',
        'Vortex ring state' => 'bad',
    ];

    #[Route('/novo-voo', name: 'app_novo_voo', methods: ['GET'])]
    public function index(Request $request, AeronaveRepository $aeronaves, PilotRepository $pilots, VooRascunhoRepository $rascunhos, TipoAeronaveRepository $tipos): Response
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->redirectToRoute('app_login');
        }

        // Rascunho do piloto (se existir) - `null` na grande maioria das
        // visitas (ninguém tinha um formulário em andamento). Resolver o
        // Pilot de verdade só pra isso é uma query a mais, mas mantém
        // index() um GET simples sem side effect nenhum.
        $pilotEntity = $pilots->findOneByCid($sessionPilot['cid']);
        $rascunho = null !== $pilotEntity ? $rascunhos->findOneByPilot($pilotEntity) : null;

        $categoriasPorTipo = $tipos->findCategoriasPorNome();

        return $this->render('novo_voo/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $sessionPilot,
            'aircraft' => array_map(
                fn (Aeronave $a) => $this->aircraftViewModel($a, $categoriasPorTipo),
                $aeronaves->findAllOrderedByBaseAndReg()
            ),
            'rascunho' => $rascunho?->getDados(),
            'rascunhoSalvoEm' => $rascunho?->getUpdatedAt()->format('H:i'),
        ]);
    }

    /**
     * Salva/sobrescreve o rascunho do piloto logado — corpo é o
     * snapshot cru que `novo-voo.js` monta (`collectManualPayload()` +
     * `mode`), gravado como veio, sem validar (rascunho pode e deve
     * estar incompleto; validação de verdade só roda ao publicar).
     */
    #[Route('/novo-voo/rascunho', name: 'app_novo_voo_salvar_rascunho', methods: ['POST'])]
    public function salvarRascunho(Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRascunhoRepository $rascunhos): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login novamente.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto da sessão não encontrado.'], 401);
        }

        $rascunho = $rascunhos->findOneByPilot($pilot);
        if (null === $rascunho) {
            $rascunho = new VooRascunho($pilot, $data);
            $em->persist($rascunho);
        } else {
            $rascunho->setDados($data);
        }
        $em->flush();

        return $this->json(['salvoEm' => $rascunho->getUpdatedAt()->format('H:i')]);
    }

    /**
     * Descarta o rascunho do piloto logado (botão "Descartar rascunho",
     * só aparece em `novo-voo.js` quando um rascunho foi restaurado).
     * Sem corpo/confirmação server-side — a confirmação já acontece no
     * cliente (`window.confirm`), mesmo padrão de outras ações
     * destrutivas simples no site (ver `voo.js`, marcar acidentado).
     */
    #[Route('/novo-voo/rascunho', name: 'app_novo_voo_descartar_rascunho', methods: ['DELETE'])]
    public function descartarRascunho(Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRascunhoRepository $rascunhos): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login novamente.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        $rascunho = null !== $pilot ? $rascunhos->findOneByPilot($pilot) : null;
        if (null !== $rascunho) {
            $em->remove($rascunho);
            $em->flush();
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Apaga o rascunho do piloto (se existir) depois de um voo publicado
     * com sucesso — chamado no fim de `publicar()`/`importarPublicar()`.
     * Voo virou de verdade, o rascunho do formulário não faz mais
     * sentido continuar por perto (se o piloto quiser registrar outro,
     * começa vazio).
     */
    private function limparRascunho(EntityManagerInterface $em, VooRascunhoRepository $rascunhos, ?Pilot $pilot): void
    {
        if (null === $pilot) {
            return;
        }
        $rascunho = $rascunhos->findOneByPilot($pilot);
        if (null !== $rascunho) {
            $em->remove($rascunho);
        }
    }

    /**
     * Publica um voo do "Registro manual" — sem telemetria medida, os
     * campos que só a telemetria sabe (`dist`, `combustivelKg`,
     * `tempoSoloMin`/`tempoArMin`, `metar`) ficam zerados/nulos (ver
     * `AcarsIngestaoController::ingerir()` pro equivalente com
     * telemetria de verdade). `codigo` fica `null` — mesmo estado dos
     * voos históricos sem gravação — é isso que já faz `portal.js`
     * tratar a linha como "sem telemetria" (não clicável pro relatório)
     * e `Voo::hasTelemetria()` devolver `false`, sem precisar de uma
     * flag "verificado" nova.
     */
    #[Route('/novo-voo/publicar', name: 'app_novo_voo_publicar', methods: ['POST'])]
    public function publicar(Request $request, EntityManagerInterface $em, PilotRepository $pilots, AeronaveRepository $aeronaves, TipoAeronaveRepository $tipos, VooRascunhoRepository $rascunhos): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['errors' => ['Sessão expirada — faça login novamente.']], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || JSON_ERROR_NONE !== json_last_error()) {
            return $this->json(['errors' => ['Payload não é JSON válido.']], 400);
        }

        $callsignNum = trim((string) ($data['callsignNum'] ?? ''));
        $tipoOperacao = (string) ($data['tipoOperacao'] ?? '');
        $aeronaveReg = strtoupper(trim((string) ($data['aeronaveReg'] ?? '')));
        $origem = strtoupper(trim((string) ($data['origem'] ?? '')));
        $destino = strtoupper(trim((string) ($data['destino'] ?? '')));
        $dataVoo = (string) ($data['data'] ?? '');
        $hora = (string) ($data['hora'] ?? '');
        $duracao = trim((string) ($data['duracao'] ?? ''));
        $condicao = trim((string) ($data['condicao'] ?? ''));
        $ocorrenciasIn = is_array($data['ocorrencias'] ?? null) ? $data['ocorrencias'] : [];
        $dificuldade = is_numeric($data['dificuldade'] ?? null) ? (int) $data['dificuldade'] : -1;
        $objetivo = trim((string) ($data['objetivo'] ?? ''));
        $relato = trim((string) ($data['relato'] ?? ''));
        $simbrief = trim((string) ($data['simbrief'] ?? ''));
        $visibilidade = (string) ($data['visibilidade'] ?? 'publico');

        $errors = [];
        if ('' === $callsignNum || !preg_match('/^\d{1,3}$/', $callsignNum)) {
            $errors[] = 'Informe o número do callsign (1 a 3 dígitos).';
        }
        if (!in_array($tipoOperacao, self::TIPOS_VALIDOS, true)) {
            $errors[] = sprintf('"Tipo de operação" precisa ser um de: %s.', implode(', ', self::TIPOS_VALIDOS));
        }
        if ('' === $aeronaveReg) {
            $errors[] = 'Selecione a aeronave.';
        }
        if ('' === $origem || '' === $destino || strlen($origem) > 8 || strlen($destino) > 8) {
            $errors[] = 'Preencha origem e destino (até 8 caracteres cada).';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataVoo) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
            $errors[] = 'Data/hora inválidas.';
        }
        $tempoMin = null;
        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $duracao, $m)) {
            $tempoMin = ((int) $m[1]) * 60 + (int) $m[2];
        }
        if (null === $tempoMin || $tempoMin < 1) {
            $errors[] = 'Duração inválida — use o formato H:MM (ex.: 1:20).';
        }
        if ($dificuldade < 0 || $dificuldade > 100) {
            $errors[] = 'Dificuldade estimada precisa estar entre 0 e 100.';
        }

        $aeronave = '' !== $aeronaveReg ? $aeronaves->findOneByReg($aeronaveReg) : null;
        if ('' !== $aeronaveReg && null === $aeronave) {
            $errors[] = sprintf('Aeronave "%s" não encontrada na frota.', $aeronaveReg);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            $errors[] = 'Piloto da sessão não encontrado.';
        }

        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        try {
            $startedAt = new \DateTimeImmutable($dataVoo.'T'.$hora.':00', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $this->json(['errors' => ['Data/hora inválidas.']], 422);
        }

        $condTag = match ($condicao) {
            'Neve' => 'bad',
            'Chuva', 'Nevoeiro', 'Turbulência' => 'warn',
            default => 'ok',
        };

        $ocorrencias = [];
        foreach ($ocorrenciasIn as $label) {
            $label = is_string($label) ? $label : '';
            if (isset(self::OCORRENCIA_TAGS[$label])) {
                $ocorrencias[] = ['label' => $label, 'tag' => self::OCORRENCIA_TAGS[$label]];
            }
        }

        $callsign = 'KBT'.$callsignNum;

        // Categoria da aeronave (asa fixa/rotativa) congelada no voo -
        // ver docblock de Voo::$categoriaAeronave.
        $categoriaAeronave = $tipos->findOneByNome($aeronave->getTipo())?->getCategoria() ?? TipoAeronave::CATEGORIA_AVIAO;

        $voo = new Voo($pilot, $callsign, $tipoOperacao, $origem, $destino, $aeronaveReg, $startedAt, $tempoMin, $dificuldade, $categoriaAeronave);
        $voo->setDados([
            'rota' => $origem.' → '.$destino,
            'modelo' => $aeronave->getTipo(),
            'cond' => '' !== $condicao ? $condicao : 'Claro',
            'condTag' => $condTag,
            'ocorrencias' => $ocorrencias,
            // Sem telemetria medida, estes quatro ficam zerados/nulos —
            // não há como estimar dist/combustível/tempo ar-solo a
            // partir só do que o piloto digitou (ver docblock acima).
            'dist' => 0,
            'combustivelKg' => 0,
            'carga' => 'Não informado (registro manual)',
            'tempoSoloMin' => 0,
            'tempoArMin' => 0,
            'metar' => null,
            'pilotReport' => '' !== $relato ? $relato : null,
            // Capturados no formulário, sem tela nenhuma que os exiba
            // ainda (mesmo tipo de lacuna que "carga" tinha na ingestão
            // ACARS) — guardados pra não perder o que o piloto digitou.
            'objetivo' => '' !== $objetivo ? $objetivo : null,
            'simbrief' => '' !== $simbrief ? $simbrief : null,
            'visibilidade' => in_array($visibilidade, ['publico', 'interno'], true) ? $visibilidade : 'publico',
        ]);

        $em->persist($voo);

        // Mesmo efeito colateral que a ingestão ACARS aplica ao pousar
        // (ver `ingerir()`) — sem isso a Frota/Mapa ao vivo não saberiam
        // que este voo aconteceu.
        $aeronave->setHoras($aeronave->getHoras() + (int) round($tempoMin / 60));
        $aeronave->setPosIcao($destino);
        $aeronave->setStatus('Disponível');
        $aeronave->setEmVooDesde(null);
        $aeronave->setUltimoPingEm(null);

        $this->limparRascunho($em, $rascunhos, $pilot);
        $em->flush();

        return $this->json(['vooId' => $voo->getId(), 'callsign' => $callsign], 201);
    }

    /**
     * Primeira etapa do modo "Importar telemetria": recebe o
     * `upload_payload.json` (já lido/parseado pelo navegador — ver
     * docblock da classe) e devolve um preview calculado de verdade
     * (`TelemetryDeriver::derive()`), sem persistir nada. `aeronaveReg`
     * é opcional — quando o navegador já sabe qual aeronave da frota
     * combina com o `ATC ID` do arquivo (ou o piloto já ajustou o
     * seletor manualmente), manda junto só pra usar o `limiteG` cadastro
     * dela no cálculo de dificuldade; sem isso o preview ainda funciona,
     * só sem esse ajuste fino.
     */
    #[Route('/novo-voo/importar/preview', name: 'app_novo_voo_importar_preview', methods: ['POST'])]
    public function importarPreview(Request $request, AeronaveRepository $aeronaves, TelemetryDeriver $deriver): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['errors' => ['Sessão expirada — faça login novamente.']], 401);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || JSON_ERROR_NONE !== json_last_error()) {
            return $this->json(['errors' => ['Payload não é JSON válido.']], 400);
        }

        [$payload, $error] = $this->validarPayloadImportado($body);
        if (null !== $error) {
            return $this->json(['errors' => [$error]], 422);
        }

        $aeronaveReg = strtoupper(trim((string) ($body['aeronaveReg'] ?? '')));
        $limiteG = '' !== $aeronaveReg ? $aeronaves->findOneByReg($aeronaveReg)?->getLimiteG() : null;

        try {
            $derivado = $deriver->derive($payload, $limiteG);
        } catch (\Throwable $e) {
            return $this->json(['errors' => ['Não foi possível calcular a telemetria: '.$e->getMessage()]], 422);
        }

        $telemetria = $derivado['telemetria'];

        return $this->json([
            'durSeg' => $derivado['dur'],
            'distNm' => $telemetria['dist'],
            'oatMinC' => $telemetria['oat_min'],
            'difficulty' => $derivado['dificuldade'],
        ]);
    }

    /**
     * Segunda etapa do modo "Importar telemetria": recebe de novo o
     * mesmo `upload_payload.json` (o navegador não precisa guardar nada
     * no servidor entre o preview e a publicação — ver docblock da
     * classe) mais os campos que o piloto ajustou/confirmou no
     * formulário, e grava o `Voo` de verdade via `TelemetriaVooBuilder`
     * — mesma validação/idempotência por `codigo` que
     * `Api\AcarsIngestaoController::ingerir()` usa, só que resolvendo o
     * piloto pela sessão (não por `pilot_cid` no corpo, que não é
     * confiável vindo do navegador).
     */
    #[Route('/novo-voo/importar/publicar', name: 'app_novo_voo_importar_publicar', methods: ['POST'])]
    public function importarPublicar(
        Request $request,
        EntityManagerInterface $em,
        PilotRepository $pilots,
        AeronaveRepository $aeronaves,
        TipoAeronaveRepository $tipos,
        VooRepository $voos,
        TelemetriaVooBuilder $builder,
        VooRascunhoRepository $rascunhos,
    ): JsonResponse {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['errors' => ['Sessão expirada — faça login novamente.']], 401);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || JSON_ERROR_NONE !== json_last_error()) {
            return $this->json(['errors' => ['Payload não é JSON válido.']], 400);
        }

        [$payload, $payloadError] = $this->validarPayloadImportado($body);

        $callsignNum = trim((string) ($body['callsignNum'] ?? ''));
        $tipoOperacao = (string) ($body['tipoOperacao'] ?? '');
        $aeronaveReg = strtoupper(trim((string) ($body['aeronaveReg'] ?? '')));
        $origem = strtoupper(trim((string) ($body['origem'] ?? '')));
        $destino = strtoupper(trim((string) ($body['destino'] ?? '')));
        $objetivo = trim((string) ($body['objetivo'] ?? ''));
        $relato = trim((string) ($body['relato'] ?? ''));
        $simbrief = trim((string) ($body['simbrief'] ?? ''));
        $visibilidade = (string) ($body['visibilidade'] ?? 'publico');

        $errors = [];
        if (null !== $payloadError) {
            $errors[] = $payloadError;
        }
        if ('' === $callsignNum || !preg_match('/^\d{1,3}$/', $callsignNum)) {
            $errors[] = 'Informe o número do callsign (1 a 3 dígitos).';
        }
        if (!in_array($tipoOperacao, self::TIPOS_VALIDOS, true)) {
            $errors[] = sprintf('"Tipo de operação" precisa ser um de: %s.', implode(', ', self::TIPOS_VALIDOS));
        }
        if ('' === $aeronaveReg) {
            $errors[] = 'Selecione a aeronave.';
        }
        if ('' === $origem || '' === $destino || strlen($origem) > 8 || strlen($destino) > 8) {
            $errors[] = 'Preencha origem e destino (até 8 caracteres cada).';
        }

        $aeronave = '' !== $aeronaveReg ? $aeronaves->findOneByReg($aeronaveReg) : null;
        if ('' !== $aeronaveReg && null === $aeronave) {
            $errors[] = sprintf('Aeronave "%s" não encontrada na frota.', $aeronaveReg);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            $errors[] = 'Piloto da sessão não encontrado.';
        }

        $startedAt = null;
        if (null !== $payload) {
            try {
                $startedAt = new \DateTimeImmutable((string) ($payload['started_at'] ?? ''));
            } catch (\Throwable) {
                $errors[] = 'Data/hora de início inválida no arquivo importado.';
            }
        }

        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        // idempotência: mesmo "codigo" (pasta de gravação) já publicado
        // antes - reenvio manual do mesmo arquivo (ex.: o piloto clicou
        // publicar duas vezes) devolve o voo existente em vez de duplicar,
        // mesmo princípio de AcarsIngestaoController::ingerir().
        $codigo = trim((string) ($payload['codigo'] ?? ''));
        if ('' !== $codigo) {
            $existente = $voos->findOneByCodigo($codigo);
            if (null !== $existente) {
                return $this->json(['vooId' => $existente->getId(), 'callsign' => $existente->getCallsign(), 'duplicado' => true], 200);
            }
        }

        try {
            $resultado = $builder->build($payload, $aeronave, $origem, $destino);
        } catch (\Throwable $e) {
            return $this->json(['errors' => ['Não foi possível calcular a telemetria: '.$e->getMessage()]], 422);
        }

        $tempoMin = max(1, (int) round($resultado['dur'] / 60));
        $callsign = 'KBT'.$callsignNum;

        // Categoria da aeronave (asa fixa/rotativa) congelada no voo -
        // ver docblock de Voo::$categoriaAeronave.
        $categoriaAeronave = $tipos->findOneByNome($aeronave->getTipo())?->getCategoria() ?? TipoAeronave::CATEGORIA_AVIAO;

        $voo = new Voo($pilot, $callsign, $tipoOperacao, $origem, $destino, $aeronaveReg, $startedAt, $tempoMin, $resultado['dificuldade'], $categoriaAeronave);
        $voo->setCodigo('' !== $codigo ? $codigo : null);

        // Diferente da ingestão ACARS ao vivo (que nunca tem essa tela de
        // formulário), a importação manual reaproveita os mesmos campos
        // "capturados sem tela nenhuma que os exiba ainda" que o registro
        // manual já grava (ver publicar() acima) — sobrescreve o
        // `pilotReport`/`objetivo`/`simbrief`/`visibilidade` que
        // TelemetriaVooBuilder::build() deixa nulo/default.
        $dados = $resultado['dados'];
        $dados['pilotReport'] = '' !== $relato ? $relato : null;
        $dados['objetivo'] = '' !== $objetivo ? $objetivo : null;
        $dados['simbrief'] = '' !== $simbrief ? $simbrief : null;
        $dados['visibilidade'] = in_array($visibilidade, ['publico', 'interno'], true) ? $visibilidade : 'publico';
        $voo->setDados($dados);

        if (null !== $resultado['destinoReal']) {
            $voo->setDestinoReal($resultado['destinoReal']);
        }

        $em->persist($voo);

        // Mesmo efeito colateral que a ingestão ACARS aplica ao pousar
        // (ver TelemetriaVooBuilder/ingerir()) — sem isso a Frota/Mapa ao
        // vivo não saberiam que este voo aconteceu.
        $aeronave->setHoras($aeronave->getHoras() + (int) round($tempoMin / 60));
        $aeronave->setPosIcao($resultado['posIcao']);
        $aeronave->setStatus('Disponível');
        $aeronave->setEmVooDesde(null);
        $aeronave->setUltimoPingEm(null);

        $this->limparRascunho($em, $rascunhos, $pilot);
        $em->flush();

        return $this->json(['vooId' => $voo->getId(), 'callsign' => $callsign], 201);
    }

    /**
     * Validação mínima compartilhada por `importarPreview()`/
     * `importarPublicar()`: confere que `$body['payload']` é um array com
     * o que `TelemetryDeriver::derive()` precisa pra rodar. Não valida
     * `codigo`/`callsign`/`tipo_operacao`/`origem`/`destino`/
     * `aeronave_reg` dentro do payload — esses vêm sempre do formulário
     * (`importarPublicar()`), nunca do arquivo, porque uma gravação feita
     * sem `--server`/`--tipo`/`--origem`/`--destino` (o caso mais comum
     * de precisar desta tela) tem esses campos vazios no JSON de
     * propósito (ver `katabatic_capture.py`).
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} [payload, mensagem de erro]
     */
    private function validarPayloadImportado(array $body): array
    {
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : null;
        if (null === $payload) {
            return [null, 'Arquivo não reconhecido — selecione o "upload_payload.json" gerado pelo script de captura.'];
        }

        $samples = is_array($payload['samples'] ?? null) ? $payload['samples'] : [];
        if (!$samples) {
            return [null, 'O arquivo não tem amostras de telemetria ("samples") — confira se é o "upload_payload.json" e não o "session.json".'];
        }

        if ('' === trim((string) ($payload['started_at'] ?? ''))) {
            return [null, 'O arquivo não tem "started_at" — confira se é o "upload_payload.json" certo.'];
        }

        return [$payload, null];
    }

    /**
     * `categoria` ('Aviao'/'Helicoptero', null se o tipo ainda não tem
     * perfil cadastrado) vem de `$categoriasPorTipo`
     * (`TipoAeronaveRepository::findCategoriasPorNome()`, buscado uma vez
     * só em `index()`) — `novo-voo.js` usa isso pra decidir se mostra os
     * três chips de ocorrência específicos de helicóptero (ver
     * `OCORRENCIA_TAGS` acima).
     *
     * @param array<string, string> $categoriasPorTipo
     *
     * @return array{reg: string, tipo: string, categoria: ?string, base: string, pos: string, status: string, dot: string}
     */
    private function aircraftViewModel(Aeronave $a, array $categoriasPorTipo): array
    {
        $dot = match ($a->getStatusTag()) {
            'warn' => 'var(--accent)',
            'bad' => 'var(--danger)',
            default => 'var(--ok)',
        };

        return [
            'reg' => $a->getReg(),
            'tipo' => $a->getTipo(),
            'categoria' => $categoriasPorTipo[$a->getTipo()] ?? null,
            'base' => $a->getBase(),
            'pos' => $a->getPosIcao(),
            'status' => $a->getStatusEfetivo(),
            'dot' => $dot,
        ];
    }
}
