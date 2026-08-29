<?php

namespace App\Controller;

use App\Entity\Voo;
use App\Repository\AeronaveRepository;
use App\Repository\PilotRepository;
use App\Repository\VooRepository;
use App\Service\FotoVooUploader;
use App\Service\PlanoVooUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Relatório de voo. A página em si continua quase toda renderizada no
 * cliente (voo.js lê a telemetria e desenha cabeçalho, fases, mapa,
 * gráficos sincronizados, pouso, composição do índice e eventos) — o
 * que mudou nesta fatia é de onde essa telemetria vem.
 *
 * Antes: `public/assets/data/flights.json`, um arquivo estático
 * público (qualquer um com a URL via qualquer pilota). Agora:
 * `telemetria()` abaixo, que devolve exatamente o mesmo formato de
 * array (voo.js não mudou uma linha), só que consultado do banco e
 * filtrado pelo piloto logado — ver `App\Entity\Voo` e
 * `VooRepository::findComTelemetriaForPilot()`.
 *
 * **Atualizado: marcar um voo como acidentado, ou excluir de vez.** Um
 * voo registrado por engano (acidente no meio do trajeto, sessão ACARS
 * corrompida, quem voou decide que aquela perna não devia contar) pode
 * ser marcado como inválido pelo próprio piloto — ver
 * `marcarAcidentado()` abaixo — sem sair do Logbook:
 * `App\Entity\Voo::$status` vira `acidentado`, ele some das
 * contagens/estatísticas (`VooRepository::countsByPilot()`) mas
 * continua visível, com um selo, tanto no relatório quanto no
 * histórico da aeronave. **De novo (o hard-delete que essa fatia tinha
 * originalmente, ver git log, voltou como uma segunda ação):**
 * `excluir()` apaga o voo de vez, marcado como acidentado ou não —
 * pra quando um voo não deveria existir de jeito nenhum (gravação de
 * teste, duplicata, dado totalmente errado), não só "não contar".
 * Ambas devolvem a aeronave pra origem daquela perna (desfazem o que
 * `AcarsIngestaoController::ingerir()` fez no fechamento), então só
 * são permitidas no voo mais recente de cada aeronave — ver docblock
 * de cada método pra por quê.
 *
 * **Atualizado: pouso alternativo (diversão).** `telemetria()` agora
 * inclui `destino_real` no blob — `null` na grande maioria dos voos,
 * preenchido só quando `AcarsIngestaoController::ingerir()` detecta que
 * o pouso de verdade divergiu do `destino` declarado no plano de voo
 * (ver `App\Entity\Voo::$destinoReal`). `voo.js` mostra um selo "Pouso
 * alternativo" no cabeçalho quando presente.
 *
 * **Atualizado: fotos do voo.** Galeria anexada pelo próprio piloto ao
 * relatório — ver `adicionarFotos()`/`removerFoto()` abaixo,
 * `App\Entity\Voo::getFotos()`/`setFotos()` e `App\Service\
 * FotoVooUploader` (processamento/armazenamento em disco, sem
 * dependência nova). Mesmo guard de posse de `relato()`. `excluir()`
 * ganhou uma limpeza extra: apaga a pasta de fotos em disco junto com
 * a linha do voo, pra não deixar arquivo órfão pra trás.
 *
 * **Atualizado: referências externas de verdade.** Os 5 links de
 * "Referências externas" (sempre mock, `href="#"`) viraram reais, cada
 * um de um jeito diferente — ver `salvarSimbrief()`,
 * `telemetriaCsv()`, `trackGpx()` abaixo e README ("Referências
 * externas"). SimBrief é o único onde o link é colado pelo próprio
 * piloto (ver docblock de `App\Entity\Voo::getSimbriefLink()`); os
 * outros são construídos a partir de dado que já temos (CID do
 * piloto, usuário do AvioDeck em `Pilot::$aviodeckUsername`, ou a
 * própria telemetria gravada, no caso do CSV/GPX).
 *
 * **Atualizado: PDF do plano de voo anexado (além do link).** O
 * SimBrief não expõe API pública estável pra buscar o OFP de um voo
 * específico (ver docblock de `salvarSimbrief()`/README), então o link
 * colado acima nunca vira automaticamente um arquivo — pra quem quer o
 * PDF de verdade disponível dentro do relatório (não só um link pra
 * fora, que pode expirar ou exigir login no SimBrief), o piloto agora
 * também pode anexar o arquivo em si — ver `adicionarPlanoVoo()`/
 * `removerPlanoVoo()` abaixo e `App\Entity\Voo::getPlanoVooPdf()`/
 * `setPlanoVooPdf()`. Um só PDF por voo (não uma galeria, como as
 * fotos): anexar um novo substitui o anterior. Mesma pasta em disco e
 * mesmo guard de posse das fotos — ver `App\Service\PlanoVooUploader`
 * e `arquivosVooDir()` (renomeado de `fotosDir()`, agora compartilhado
 * pelos dois tipos de anexo).
 *
 * **Atualizado: CARTO Basemaps exige API key.** `index()` injeta
 * `cartoApiKey` (env `CARTO_API_KEY`) igual a
 * `MapaAoVivoController::index()` — ver docblock de lá pro porquê (a
 * marca d'água "API KEY REQUIRED" que o CARTO desenha sem a chave). O
 * mapa desta tela (`voo.js`) usa o mesmo tile provider.
 *
 * **Atualizado: METAR de pouso + campos de `dados` que nunca chegavam
 * aqui.** `telemetria()` só devolvia a chave `telemetria` de dentro de
 * `Voo::$dados` — tudo que `TelemetriaVooBuilder::build()` monta um
 * nível acima (METAR de origem, carga estimada, modelo da aeronave,
 * ocorrências resumidas) ficava gravado no banco sem nunca virar
 * resposta HTTP nenhuma, então `voo.js` nunca teve como mostrar. Agora
 * `telemetria()` também mescla essas chaves (mais `tipo_operacao` e
 * `aeronave_reg`, colunas de verdade que também nunca tinham sido
 * expostas). `TelemetriaVooBuilder::build()` também passou a buscar o
 * METAR do aeroporto de pouso REAL (`$posIcao`, considerando diversão),
 * não só o da origem — ver `App\Service\MetarClient`.
 */
class VooController extends AbstractController
{
    /** Limite de fotos por voo — evita que a galeria de um único voo cresça sem fim. */
    private const MAX_FOTOS_POR_VOO = 12;

    #[Route('/voo', name: 'app_voo', methods: ['GET'])]
    public function index(Request $request, #[Autowire('%env(CARTO_API_KEY)%')] string $cartoApiKey): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('voo/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $pilot,
            'flightsUrl' => $this->generateUrl('app_voo_telemetria'),
            'cartoApiKey' => $cartoApiKey,
        ]);
    }

    #[Route('/voo/telemetria', name: 'app_voo_telemetria', methods: ['GET'])]
    public function telemetria(Request $request, PilotRepository $pilots, VooRepository $voos): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json([], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json([]);
        }

        $flights = array_map(
            function ($voo) {
                $telemetria = $voo->getTelemetria();
                // pilot_report é editável de verdade agora (ver relato()
                // abaixo) - nunca vem gravado dentro do blob de telemetria
                // em si (esse é só o que o ACARS mandou), então é sempre
                // sobrescrito aqui com o valor atual da coluna. Mesma
                // lógica pra 'status' (marcarAcidentado() abaixo) e
                // 'destino_real' (App\Entity\Voo::$destinoReal, gravado por
                // AcarsIngestaoController::ingerir() quando detecta pouso
                // alternativo) - nenhum dos dois vem no payload do ACARS,
                // são colunas de verdade, não parte do blob original.
                $telemetria['pilot_report'] = $voo->getPilotReport();
                $telemetria['status'] = $voo->getStatus();
                $telemetria['destino_real'] = $voo->getDestinoReal();
                $telemetria['fotos'] = $this->fotosViewModel($voo);
                $telemetria['simbrief_link'] = $voo->getSimbriefLink();
                $telemetria['plano_voo'] = $this->planoVooViewModel($voo);

                // Colunas de verdade que nunca vieram nesta resposta -
                // `voo.js` só lia a chave `telemetria` de dentro de
                // `dados`, então tudo que `TelemetriaVooBuilder::build()`
                // monta um nível acima (metar/carga/modelo/ocorrências)
                // ficava gravado no banco sem nunca chegar no relatório.
                // Ver README, auditoria dos dados de voo integrados.
                $dados = $voo->getDados();
                $telemetria['tipo_operacao'] = $voo->getTipoOperacao();
                $telemetria['aeronave_reg'] = $voo->getAeronaveReg();
                // Ver App\Entity\Voo::$categoriaAeronave - pedido em
                // conversa: "marcar o voo quando ele é feito com asa fixa
                // e asa rotativa". voo.js mostra um selo "Helicóptero"
                // no cabeçalho quando é o caso.
                $telemetria['categoria_aeronave'] = $voo->getCategoriaAeronave();
                $telemetria['modelo'] = $dados['modelo'] ?? null;
                $telemetria['metar'] = $dados['metar'] ?? null;
                $telemetria['metar_pouso'] = $dados['metarPouso'] ?? null;
                $telemetria['carga'] = $dados['carga'] ?? null;
                $telemetria['ocorrencias'] = $dados['ocorrencias'] ?? [];

                return $telemetria;
            },
            $voos->findComTelemetriaForPilot($pilot)
        );

        return $this->json($flights);
    }

    #[Route('/voo/{codigo}/relato', name: 'app_voo_relato', methods: ['POST'])]
    public function relato(string $codigo, Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRepository $voos): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto não encontrado.'], 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->json(['error' => 'Voo não encontrado.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $relato = trim((string) ($data['relato'] ?? ''));

        $voo->setPilotReport('' !== $relato ? $relato : null);
        $em->flush();

        return $this->json(['pilot_report' => $voo->getPilotReport()]);
    }

    /**
     * Salva (ou limpa) o link do plano de voo no SimBrief pra este voo
     * — colado pelo próprio piloto, não derivado de nada cadastrado no
     * perfil (ver docblock de `App\Entity\Voo::getSimbriefLink()` pra
     * por quê). Mesmo guard de posse de `relato()`.
     *
     * Validação rasa de propósito: só confere que é uma URL http(s) num
     * domínio do SimBrief (`simbrief.com`) — o bastante pra não deixar
     * gravar (e depois exibir como link clicável em "Referências
     * externas") qualquer URL arbitrária colada por engano, sem tentar
     * validar que o link aponta pro OFP certo (isso o piloto garante).
     */
    #[Route('/voo/{codigo}/simbrief', name: 'app_voo_simbrief', methods: ['POST'])]
    public function salvarSimbrief(string $codigo, Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRepository $voos): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto não encontrado.'], 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->json(['error' => 'Voo não encontrado.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $link = trim((string) ($data['link'] ?? ''));

        if ('' !== $link) {
            $host = parse_url($link, \PHP_URL_HOST);
            $scheme = parse_url($link, \PHP_URL_SCHEME);
            $hostOk = null !== $host && (bool) preg_match('/(^|\.)simbrief\.com$/i', $host);
            if (!in_array($scheme, ['http', 'https'], true) || !$hostOk) {
                return $this->json(['error' => 'Cole um link do simbrief.com (o mesmo que o SimBrief te deu ao gerar o plano).'], 422);
            }
        }

        $voo->setSimbriefLink('' !== $link ? $link : null);
        $em->flush();

        return $this->json(['simbrief_link' => $voo->getSimbriefLink()]);
    }

    /**
     * Exporta a telemetria bruta deste voo em CSV — uma linha por
     * segundo (mesma cadência de `track`/`prof`, os dois derivados do
     * mesmo array de amostras no `TelemetryDeriver`, sempre do mesmo
     * tamanho e com os mesmos `t_s`), com as colunas de `env` (cadência
     * mais esparsa, ~0,1 Hz) preenchidas pelo último valor conhecido —
     * mesma lógica de "último valor até aqui" que `voo.js` já usa no
     * hover do debrief (`at()`, ver `renderCharts()`).
     *
     * Mesmo guard de posse de `relato()` — é GET, mas o Logbook
     * continua privado por piloto (ver docblock de `telemetria()`
     * acima), então precisa do mesmo `findOneByCodigoForPilot()`.
     */
    #[Route('/voo/{codigo}/telemetria.csv', name: 'app_voo_telemetria_csv', methods: ['GET'])]
    public function telemetriaCsv(string $codigo, Request $request, PilotRepository $pilots, VooRepository $voos): Response
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return new Response('Sessão expirada.', 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return new Response('Piloto não encontrado.', 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo || !$voo->hasTelemetria()) {
            return new Response('Voo não encontrado.', 404);
        }

        $telemetria = $voo->getTelemetria();
        $track = $telemetria['track'] ?? [];
        $prof = $telemetria['prof'] ?? [];
        $env = $telemetria['env'] ?? [];

        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, ['t_s', 'lat', 'lon', 'alt_ft', 'ias_kt', 'tas_kt', 'gs_kt', 'vs_fpm', 'g_max', 'acc_y_rms', 'on_ground', 'oat_c', 'wind_kt', 'wind_dir', 'precip_rate', 'in_cloud', 'ice_pct', 'fuel_lb']);

        foreach ($track as $i => $ponto) {
            $p = $prof[$i] ?? null;
            $e = $this->ultimoEnvAte($env, $ponto[0]);
            fputcsv($buffer, [
                $ponto[0], $ponto[1], $ponto[2],
                $p[1] ?? '', $p[2] ?? '', $p[8] ?? '', $p[6] ?? '', $p[3] ?? '', $p[4] ?? '', $p[5] ?? '', null !== $p ? ((bool) $p[7] ? 1 : 0) : '',
                $e[1] ?? '', $e[5] ?? '', $e[6] ?? '', $e[2] ?? '', null !== $e ? ((bool) $e[3] ? 1 : 0) : '', $e[4] ?? '',
                $e[7] ?? '',
            ]);
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer);
        fclose($buffer);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            $codigo.'-telemetria.csv'
        ));

        return $response;
    }

    /**
     * @param list<array{0: int, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: mixed, 6: mixed, 7: mixed}> $env
     *
     * @return array|null último elemento de `env` com `t_s` <= `$tS`, ou null se nenhum
     */
    private function ultimoEnvAte(array $env, int $tS): ?array
    {
        $ultimo = null;
        foreach ($env as $e) {
            if ($e[0] > $tS) {
                break;
            }
            $ultimo = $e;
        }

        return $ultimo;
    }

    /**
     * Exporta o traço GPS deste voo como GPX 1.1 — track única, um
     * `<trkpt>` por amostra de `track`, altitude vinda de `prof` (mesmo
     * índice, ver docblock de `telemetriaCsv()`) convertida de pés pra
     * metros (GPX exige metros). Mesmo guard de posse.
     */
    #[Route('/voo/{codigo}/track.gpx', name: 'app_voo_track_gpx', methods: ['GET'])]
    public function trackGpx(string $codigo, Request $request, PilotRepository $pilots, VooRepository $voos): Response
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return new Response('Sessão expirada.', 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return new Response('Piloto não encontrado.', 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo || !$voo->hasTelemetria()) {
            return new Response('Voo não encontrado.', 404);
        }

        $telemetria = $voo->getTelemetria();
        $track = $telemetria['track'] ?? [];
        $prof = $telemetria['prof'] ?? [];

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $gpx = $doc->createElementNS('http://www.topografix.com/GPX/1/1', 'gpx');
        $gpx->setAttribute('version', '1.1');
        $gpx->setAttribute('creator', 'Katabatic');
        $doc->appendChild($gpx);

        $trk = $doc->createElement('trk');
        $gpx->appendChild($trk);
        $name = $doc->createElement('name', htmlspecialchars($codigo.' — '.($telemetria['orig'] ?? '?').' → '.($telemetria['dest'] ?? '?'), \ENT_XML1));
        $trk->appendChild($name);
        $trkseg = $doc->createElement('trkseg');
        $trk->appendChild($trkseg);

        foreach ($track as $i => $ponto) {
            [$tS, $lat, $lon] = $ponto;
            if (null === $lat || null === $lon) {
                continue;
            }
            $trkpt = $doc->createElement('trkpt');
            $trkpt->setAttribute('lat', (string) $lat);
            $trkpt->setAttribute('lon', (string) $lon);

            $altFt = $prof[$i][1] ?? null;
            if (null !== $altFt) {
                $trkpt->appendChild($doc->createElement('ele', (string) round($altFt * 0.3048, 1)));
            }
            $trkpt->appendChild($doc->createElement('time', $voo->getStartedAt()->modify("+{$tS} seconds")->format('Y-m-d\TH:i:s\Z')));

            $trkseg->appendChild($trkpt);
        }

        $response = new Response($doc->saveXML());
        $response->headers->set('Content-Type', 'application/gpx+xml; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            $codigo.'-track.gpx'
        ));

        return $response;
    }

    /**
     * Adiciona uma ou mais fotos à galeria do relatório deste voo —
     * campo multipart `fotos[]` (ver `voo.js`). Mesmo guard de posse de
     * `relato()`.
     *
     * **Envio parcial, de propósito:** se o lote tem 5 fotos e uma
     * falha (formato inválido, corrompida, etc.), as outras 4 ainda
     * são salvas — a resposta sempre traz a galeria atualizada mais um
     * `error` com a última falha, se houve alguma. Rejeitar o lote
     * inteiro por causa de um arquivo ruim seria pior experiência do
     * que perder só aquele.
     */
    #[Route('/voo/{codigo}/fotos', name: 'app_voo_fotos_adicionar', methods: ['POST'])]
    public function adicionarFotos(string $codigo, Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRepository $voos, FotoVooUploader $uploader): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto não encontrado.'], 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->json(['error' => 'Voo não encontrado.'], 404);
        }

        $enviados = $request->files->all()['fotos'] ?? [];
        if (!\is_array($enviados)) {
            $enviados = [$enviados];
        }
        if ([] === $enviados) {
            return $this->json(['error' => 'Nenhuma foto enviada.'], 400);
        }

        $fotos = $voo->getFotos();
        if (\count($fotos) + \count($enviados) > self::MAX_FOTOS_POR_VOO) {
            return $this->json(['error' => sprintf('Limite de %d fotos por voo — este voo já tem %d.', self::MAX_FOTOS_POR_VOO, \count($fotos))], 409);
        }

        $novas = [];
        $erro = null;
        foreach ($enviados as $arquivoEnviado) {
            if (!$arquivoEnviado instanceof UploadedFile) {
                continue;
            }
            $resultado = $uploader->processar($arquivoEnviado, $this->arquivosVooDir($codigo));
            if (null === $resultado['arquivo']) {
                $erro = $resultado['erro'];
                continue;
            }
            $novas[] = ['arquivo' => $resultado['arquivo'], 'enviadoEm' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)];
        }

        if ([] !== $novas) {
            $voo->setFotos([...$fotos, ...$novas]);
            $em->flush();
        }

        $status = null !== $erro && [] === $novas ? 422 : 200;

        return $this->json(['fotos' => $this->fotosViewModel($voo), 'error' => $erro], $status);
    }

    /**
     * Remove uma foto da galeria — corpo JSON `{"arquivo": "..."}` com
     * o nome exatamente como veio de `fotosViewModel()` (nunca um
     * caminho arbitrário: só é removido se já estiver na lista
     * gravada deste voo, ver checagem abaixo). Mesmo guard de posse de
     * `relato()`.
     */
    #[Route('/voo/{codigo}/fotos/remover', name: 'app_voo_fotos_remover', methods: ['POST'])]
    public function removerFoto(string $codigo, Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRepository $voos, FotoVooUploader $uploader): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto não encontrado.'], 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->json(['error' => 'Voo não encontrado.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $arquivo = (string) ($data['arquivo'] ?? '');

        $fotos = $voo->getFotos();
        $restantes = array_values(array_filter($fotos, static fn (array $f): bool => $f['arquivo'] !== $arquivo));
        if (\count($restantes) === \count($fotos)) {
            return $this->json(['error' => 'Foto não encontrada.'], 404);
        }

        $uploader->remover($this->arquivosVooDir($codigo), $arquivo);
        $voo->setFotos($restantes);
        $em->flush();

        return $this->json(['fotos' => $this->fotosViewModel($voo)]);
    }

    /**
     * Anexa (ou substitui) o PDF do plano de voo deste voo — campo
     * multipart `planoVoo` (ver `voo.js`). Mesmo guard de posse de
     * `relato()`. Diferente de `adicionarFotos()`: é um único arquivo,
     * não uma galeria — anexar um novo PDF apaga e substitui o
     * anterior, se houver, em vez de acumular.
     */
    #[Route('/voo/{codigo}/plano-voo', name: 'app_voo_plano_voo_adicionar', methods: ['POST'])]
    public function adicionarPlanoVoo(string $codigo, Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRepository $voos, PlanoVooUploader $uploader): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto não encontrado.'], 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->json(['error' => 'Voo não encontrado.'], 404);
        }

        $arquivoEnviado = $request->files->get('planoVoo');
        if (!$arquivoEnviado instanceof UploadedFile) {
            return $this->json(['error' => 'Nenhum arquivo enviado.'], 400);
        }

        $resultado = $uploader->processar($arquivoEnviado, $this->arquivosVooDir($codigo));
        if (null === $resultado['arquivo']) {
            return $this->json(['error' => $resultado['erro']], 422);
        }

        // Um só PDF por voo - se já tinha um, apaga o arquivo velho do
        // disco antes de gravar a referência do novo (senão o antigo
        // ficaria órfão, sem nenhuma linha apontando pra ele).
        $anterior = $voo->getPlanoVooPdf();
        if (null !== $anterior) {
            $uploader->remover($this->arquivosVooDir($codigo), $anterior['arquivo']);
        }

        $voo->setPlanoVooPdf([
            'arquivo' => $resultado['arquivo'],
            'nomeOriginal' => $resultado['nomeOriginal'],
            'enviadoEm' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
        $em->flush();

        return $this->json(['plano_voo' => $this->planoVooViewModel($voo)]);
    }

    /**
     * Remove o PDF do plano de voo deste voo, se houver. Mesmo guard
     * de posse de `relato()`.
     */
    #[Route('/voo/{codigo}/plano-voo/remover', name: 'app_voo_plano_voo_remover', methods: ['POST'])]
    public function removerPlanoVoo(string $codigo, Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRepository $voos, PlanoVooUploader $uploader): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto não encontrado.'], 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->json(['error' => 'Voo não encontrado.'], 404);
        }

        $atual = $voo->getPlanoVooPdf();
        if (null === $atual) {
            return $this->json(['error' => 'Nenhum PDF anexado a este voo.'], 404);
        }

        $uploader->remover($this->arquivosVooDir($codigo), $atual['arquivo']);
        $voo->setPlanoVooPdf(null);
        $em->flush();

        return $this->json(['plano_voo' => null]);
    }

    /**
     * Pasta em disco onde ficam TODOS os anexos deste voo — fotos
     * (`App\Service\FotoVooUploader`) e o PDF do plano de voo
     * (`App\Service\PlanoVooUploader`) dividem a mesma pasta por
     * `codigo` de propósito: é por isso que `excluir()` abaixo apaga
     * os dois tipos de anexo com uma única chamada
     * (`FotoVooUploader::removerPasta()`), sem precisar saber o que
     * tem lá dentro. Renomeado de `fotosDir()` quando o PDF passou a
     * morar aqui também.
     */
    private function arquivosVooDir(string $codigo): string
    {
        return $this->getParameter('kernel.project_dir').'/public/uploads/voos/'.$codigo;
    }

    /**
     * @return list<array{arquivo: string, url: string, enviadoEm: ?string}>
     */
    private function fotosViewModel(Voo $voo): array
    {
        $codigo = $voo->getCodigo();

        return array_map(
            static fn (array $f): array => [
                'arquivo' => $f['arquivo'],
                'url' => '/uploads/voos/'.$codigo.'/'.$f['arquivo'],
                'enviadoEm' => $f['enviadoEm'] ?? null,
            ],
            $voo->getFotos()
        );
    }

    /**
     * @return array{arquivo: string, url: string, nomeOriginal: ?string, enviadoEm: ?string}|null
     */
    private function planoVooViewModel(Voo $voo): ?array
    {
        $planoVoo = $voo->getPlanoVooPdf();
        if (null === $planoVoo) {
            return null;
        }

        return [
            'arquivo' => $planoVoo['arquivo'],
            'url' => '/uploads/voos/'.$voo->getCodigo().'/'.$planoVoo['arquivo'],
            'nomeOriginal' => $planoVoo['nomeOriginal'] ?? null,
            'enviadoEm' => $planoVoo['enviadoEm'] ?? null,
        ];
    }

    /**
     * Marca um voo como acidentado (não apaga mais — ver docblock da
     * classe) e desfaz o efeito colateral que
     * `AcarsIngestaoController::ingerir()` teve na aeronave: soma de horas
     * (subtrai de volta o mesmo arredondamento) e posição (`posIcao` volta
     * pra `origem` do próprio voo, não pra onde ele "devia" ter ido).
     *
     * **Só permite marcar o voo mais recente com telemetria da
     * aeronave** (ver `VooRepository::findMaisRecenteComTelemetriaByAeronaveReg()`,
     * que não filtra por `status` — continua olhando a data, não se o
     * voo já foi marcado). Sem essa checagem, marcar uma perna no meio
     * da história e devolver `posIcao` pra origem dela sobrescreveria a
     * posição de verdade da aeronave se pernas mais novas já tiverem
     * acontecido depois — a aeronave "voltaria" pra um ponto que não é
     * mais onde ela está. Pra marcar uma sequência inteira, é de trás
     * pra frente, uma perna de cada vez.
     *
     * Mesmo guard de posse de `relato()` (só o piloto que voou pode
     * marcar) — sem exigir papel "admin", mesmo espírito de self-service
     * do relato.
     */
    #[Route('/voo/{codigo}/acidentado', name: 'app_voo_acidentado', methods: ['POST'])]
    public function marcarAcidentado(string $codigo, Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRepository $voos, AeronaveRepository $aeronaves): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto não encontrado.'], 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->json(['error' => 'Voo não encontrado.'], 404);
        }

        if ($voo->isAcidentado()) {
            return $this->json(['error' => 'Este voo já foi marcado como acidentado.'], 409);
        }

        $maisRecente = $voos->findMaisRecenteComTelemetriaByAeronaveReg($voo->getAeronaveReg());
        if (null === $maisRecente || $maisRecente->getId() !== $voo->getId()) {
            return $this->json(['error' => 'Só dá pra marcar o voo mais recente dessa aeronave — marque primeiro os voos mais novos dela, na ordem inversa.'], 409);
        }

        // Aeronave sempre deveria existir (o ingerir() original exigiu
        // isso pra criar o voo) - o guard aqui é só defensivo, não
        // bloqueia a marcação do voo em si se a matrícula sumiu da frota
        // por algum outro caminho.
        $aeronave = $aeronaves->findOneByReg($voo->getAeronaveReg());
        if (null !== $aeronave) {
            $aeronave->setHoras(max(0, $aeronave->getHoras() - (int) round($voo->getTempoMin() / 60)));
            $aeronave->setPosIcao($voo->getOrigem());
        }

        $voo->marcarAcidentado();
        $em->flush();

        return $this->json(['codigo' => $codigo, 'status' => $voo->getStatus()]);
    }

    /**
     * Apaga um voo de vez — diferente de `marcarAcidentado()` (que só
     * marca e mantém a linha), aqui a linha some do banco mesmo,
     * telemetria inteira incluída, sem tombstone/auditoria. Pro caso em
     * que a marcação não basta: uma gravação de teste que foi enviada
     * por engano, uma duplicata, um voo que simplesmente não devia
     * existir — não só "não contar" pras estatísticas.
     *
     * **Mesmo guard de `marcarAcidentado()`: só o voo mais recente com
     * telemetria da aeronave** (ver
     * `VooRepository::findMaisRecenteComTelemetriaByAeronaveReg()`, que
     * não filtra por `status` — um voo já acidentado ainda conta como
     * "mais recente" se nenhum voo novo aconteceu depois dele). Mesmo
     * motivo do guard de lá: excluir uma perna no meio da história e
     * devolver `posIcao` pra origem dela corromperia a posição de
     * verdade se pernas mais novas já tiverem acontecido depois.
     *
     * Se o voo **já estava marcado acidentado**, o efeito colateral na
     * aeronave (horas/posição) já foi aplicado quando foi marcado — não
     * repete aqui (subtrairia as horas duas vezes). Só aplica de novo
     * se o voo ainda estava `valido` (nunca passou por
     * `marcarAcidentado()`).
     *
     * **Atualizado: fotos (e, depois, o PDF do plano de voo — mesma
     * pasta).** Antes de apagar a linha, apaga também a pasta de
     * anexos deste voo em disco (`FotoVooUploader::removerPasta()`) —
     * sem isso, os arquivos ficariam órfãos (a referência dentro de
     * `dados` some junto com a linha, mas ninguém mais apagaria o
     * arquivo em `public/uploads/voos/{codigo}/`). Uma única chamada
     * limpa os dois tipos de anexo, já que dividem a mesma pasta — ver
     * `arquivosVooDir()`.
     */
    #[Route('/voo/{codigo}/excluir', name: 'app_voo_excluir', methods: ['POST'])]
    public function excluir(string $codigo, Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRepository $voos, AeronaveRepository $aeronaves, FotoVooUploader $uploader): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto não encontrado.'], 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->json(['error' => 'Voo não encontrado.'], 404);
        }

        $maisRecente = $voos->findMaisRecenteComTelemetriaByAeronaveReg($voo->getAeronaveReg());
        if (null === $maisRecente || $maisRecente->getId() !== $voo->getId()) {
            return $this->json(['error' => 'Só dá pra excluir o voo mais recente dessa aeronave — exclua primeiro os voos mais novos dela, na ordem inversa.'], 409);
        }

        if (!$voo->isAcidentado()) {
            // Aeronave sempre deveria existir (o ingerir() original
            // exigiu isso pra criar o voo) - o guard aqui é só
            // defensivo, não bloqueia a exclusão do voo em si se a
            // matrícula sumiu da frota por algum outro caminho.
            $aeronave = $aeronaves->findOneByReg($voo->getAeronaveReg());
            if (null !== $aeronave) {
                $aeronave->setHoras(max(0, $aeronave->getHoras() - (int) round($voo->getTempoMin() / 60)));
                $aeronave->setPosIcao($voo->getOrigem());
            }
        }

        $uploader->removerPasta($this->arquivosVooDir($codigo));

        $em->remove($voo);
        $em->flush();

        return $this->json(['codigo' => $codigo]);
    }
}
