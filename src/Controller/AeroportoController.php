<?php

namespace App\Controller;

use App\Entity\Aeroporto;
use App\Repository\AeroportoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cadastro de aeroportos — substitui o catálogo fixo
 * `public/assets/data/airports.json` (11 entradas hardcoded, editável só
 * mexendo no arquivo e fazendo deploy) por uma tabela cadastrável direto
 * pelo site. Ver `App\Entity\Aeroporto`.
 *
 * **Restrito a admin** (`index()`/`criar()`/`marcarPosto()`), mesmo guard
 * de `SolicitacoesController` (`$pilot['admin']` na sessão — ver
 * `ensureAdmin()`): aeroportos são dado de referência compartilhado por
 * toda a frota (mapa ao vivo, agendamento, histórico, resolução de
 * pouso alternativo), então um cadastro errado (ICAO ou coordenada
 * trocada) afeta todo mundo, não só quem cadastrou — decisão tomada em
 * conversa, ver README.
 *
 * **Atualizado: importação global (OurAirports) muda o que cada rota
 * devolve.** Ver README, "Backend: importação global de aeroportos". Com
 * o catálogo saltando de uma dúzia pra milhares de linhas
 * (`ImportarAeroportosOurairportsCommand`), três rotas mudaram de
 * comportamento:
 *
 * - `catalogo()` (pública, qualquer piloto logado) agora devolve só
 *   bases + postos avançados (`AeroportoRepository::
 *   findCatalogoReferenciaArray()`), não mais o catálogo inteiro — um
 *   JSON de centenas de KB a MB embutido em toda página de mapa deixou
 *   de ser viável. `aeronave.js`/`mapa-ao-vivo.js` não mudaram nada (o
 *   formato é o mesmo, só ficou menor); `agendamento.js` parou de
 *   consumir esta rota (ver `buscar()` abaixo).
 * - `buscar()` (nova, pública) é como qualquer tela alcança um ICAO fora
 *   desse conjunto pequeno — busca sob demanda, nunca despeja tudo de
 *   uma vez. Alimenta o combobox de origem/destino do Agendamento e a
 *   busca da tela admin aqui embaixo.
 * - `index()` (admin) só embute bases + postos avançados de cara pelo
 *   mesmo motivo de `catalogo()` — a tabela sem paginação não aguentaria
 *   milhares de linhas. `marcarPosto()` (nova) é como um admin promove
 *   um ICAO qualquer do catálogo grande (achado via a busca da própria
 *   tela) a posto avançado — antes disso só dava pra marcar isso no
 *   momento do cadastro (`criar()`), o que deixou de bastar quando a
 *   maioria dos aeroportos passou a chegar via import em massa, não
 *   cadastro manual.
 *
 * `postoAvancadoDe` ('PAFA'/'SCCI'/vazio) continua só rótulo — não
 * libera essa pista como base de aeronave
 * (`NovaAeronaveController::BASES_VALIDAS` continua fixo), só aparece
 * como nota nos popups do mapa e na aba Bases do Portal.
 *
 * **`icaoOficial` no JSON de todo endpoint aqui** (`criar()`, `buscar()`,
 * `marcarPosto()`) distingue um ICAO de verdade de um código local/FAA/
 * gps sem ICAO oficial — só existe pras pistas sem ICAO das regiões de
 * missão trazidas por `ImportarAeroportosOurairportsCommand` (ver
 * `App\Entity\Aeroporto` e README). **`criar()` continua exigindo ICAO
 * real** (mesmo regex de sempre) — cadastro manual nunca cria um
 * aeroporto com `icaoOficial=false`, só o import em massa faz isso, de
 * propósito (evita um admin digitar um código inventado sem querer).
 *
 * **Atualizado: heading da pista principal.** `criar()` aceita um
 * `pistaPrincipalHeadingMag` opcional (0-359) no cadastro novo, e
 * `pistaPrincipal()` (nova rota) marca/limpa esse campo num aeroporto
 * já existente — mesmo padrão estreito de `marcarPosto()`. Alimenta
 * `TelemetryDeriver::recomputeWindcComHeadingDePista()`: quando o
 * aeroporto de pouso real de um voo tem essa informação, o través do
 * relatório usa a pista de verdade em vez da aproximação padrão (ver
 * `AcarsIngestaoController::ingerir()` e `Aeroporto::$pistaPrincipalHeadingMag`).
 */
class AeroportoController extends AbstractController
{
    private const BASES_VALIDAS = ['PAFA', 'SCCI'];

    #[Route('/aeroportos', name: 'app_aeroportos', methods: ['GET'])]
    public function index(Request $request, AeroportoRepository $aeroportos): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }
        if (empty($pilot['admin'])) {
            return $this->redirectToRoute('app_portal');
        }

        return $this->render('aeroporto/index.html.twig', [
            'activeView' => 'aeroportos',
            'pilot' => $pilot,
            // So bases + postos avancados de cara (pequeno, estavel) -
            // ver docblock da classe. `totalAeroportos` da contexto pro
            // admin ("ha X no catalogo, use a busca pra achar um deles")
            // sem precisar embutir todo mundo.
            'aeroportos' => array_map(fn (Aeroporto $a) => $this->viewModel($a), $aeroportos->findBasesEPostosAvancados()),
            'totalAeroportos' => $aeroportos->countAll(),
            'buscaUrl' => $this->generateUrl('app_aeroportos_buscar'),
        ]);
    }

    #[Route('/aeroportos', name: 'app_aeroportos_criar', methods: ['POST'])]
    public function criar(Request $request, EntityManagerInterface $em, AeroportoRepository $aeroportos): JsonResponse
    {
        if (null !== $err = $this->ensureAdmin($request)) {
            return $err;
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $icao = strtoupper(trim((string) ($data['icao'] ?? '')));
        $nome = trim((string) ($data['nome'] ?? ''));
        $cidade = trim((string) ($data['cidade'] ?? ''));
        $lat = $data['lat'] ?? null;
        $lon = $data['lon'] ?? null;
        $postoAvancadoDe = trim((string) ($data['postoAvancadoDe'] ?? ''));
        $pistaHeadingRaw = $data['pistaPrincipalHeadingMag'] ?? null;

        $errors = [];
        if (!preg_match('/^[A-Z0-9]{3,8}$/', $icao)) {
            $errors[] = 'ICAO inválido — use de 3 a 8 letras/números (ex.: PAFA).';
        } elseif ($aeroportos->existsByIcao($icao)) {
            $errors[] = sprintf('Já existe um aeroporto cadastrado com o ICAO %s.', $icao);
        }
        if ('' === $nome) {
            $errors[] = 'Informe o nome do aeroporto.';
        }
        if ('' === $cidade) {
            $errors[] = 'Informe a cidade.';
        }
        if (!is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90) {
            $errors[] = 'Latitude inválida — precisa estar entre -90 e 90.';
        }
        if (!is_numeric($lon) || (float) $lon < -180 || (float) $lon > 180) {
            $errors[] = 'Longitude inválida — precisa estar entre -180 e 180.';
        }
        if ('' !== $postoAvancadoDe && !in_array($postoAvancadoDe, self::BASES_VALIDAS, true)) {
            $errors[] = 'Posto avançado precisa ser de uma base válida (PAFA ou SCCI), ou deixado em branco.';
        }
        $pistaHeading = null;
        if (null !== $pistaHeadingRaw && '' !== $pistaHeadingRaw) {
            if (!is_numeric($pistaHeadingRaw) || (float) $pistaHeadingRaw < 0 || (float) $pistaHeadingRaw > 359) {
                $errors[] = 'Heading da pista principal inválido — use de 0 a 359, ou deixe em branco.';
            } else {
                $pistaHeading = (int) round((float) $pistaHeadingRaw);
            }
        }

        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        $aeroporto = new Aeroporto($icao, $nome, $cidade, (float) $lat, (float) $lon);
        if ('' !== $postoAvancadoDe) {
            $aeroporto->setPostoAvancadoDe($postoAvancadoDe);
        }
        if (null !== $pistaHeading) {
            $aeroporto->setPistaPrincipalHeadingMag($pistaHeading);
        }

        $em->persist($aeroporto);
        $em->flush();

        return $this->json($this->viewModel($aeroporto), 201);
    }

    /**
     * Catálogo de referência público (qualquer piloto logado) — ver
     * docblock da classe. Só bases + postos avançados desde a importação
     * global; `aeronave.js`/`mapa-ao-vivo.js` continuam fazendo `fetch()`
     * nisto do mesmo jeito que faziam em `airports.json` (o formato não
     * mudou, só ficou menor) - `agendamento.js` parou de usar esta rota,
     * usa `buscar()` abaixo.
     */
    #[Route('/aeroportos/catalogo', name: 'app_aeroportos_catalogo', methods: ['GET'])]
    public function catalogo(Request $request, AeroportoRepository $aeroportos): JsonResponse
    {
        if (null === $request->getSession()->get('pilot')) {
            return $this->json([], 401);
        }

        return $this->json($aeroportos->findCatalogoReferenciaArray());
    }

    /**
     * Busca no catálogo inteiro (qualquer piloto logado) - por ICAO
     * (prefixo) ou nome/cidade (substring), ver
     * `AeroportoRepository::buscar()`. `?q=` com menos de 2 caracteres
     * devolve vazio de propósito (evita disparar busca a cada tecla
     * antes de ter algo útil pra filtrar, e evita devolver um pedaço
     * grande do catálogo pra uma letra só). Alimenta o combobox de
     * origem/destino do Agendamento (`agendamento.js`) e a busca da tela
     * admin aqui (`aeroporto.js`).
     *
     * Corpo da resposta: lista de `{icao, nome, cidade, lat, lon, postoAvancadoDe, icaoOficial}` (mesmo `viewModel()` de `criar()`).
     */
    #[Route('/aeroportos/buscar', name: 'app_aeroportos_buscar', methods: ['GET'])]
    public function buscar(Request $request, AeroportoRepository $aeroportos): JsonResponse
    {
        if (null === $request->getSession()->get('pilot')) {
            return $this->json([], 401);
        }

        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(fn (Aeroporto $a) => $this->viewModel($a), $aeroportos->buscar($q)));
    }

    /**
     * Marca (ou desmarca) um aeroporto JÁ CADASTRADO como posto avançado
     * de uma base - admin-only. Não existia antes da importação global:
     * quando todo aeroporto chegava por cadastro manual em `criar()`,
     * marcar o posto avançado no mesmo formulário bastava. Com milhares
     * de linhas chegando via `ImportarAeroportosOurairportsCommand` (sem
     * `postoAvancadoDe`, de propósito - ver docblock do comando), o
     * fluxo normal virou "achar o ICAO pela busca da tela admin, depois
     * marcar" - esta rota é o "depois marcar".
     *
     * `{icao}` vem da URL (não do corpo) pra rota ficar RESTful-ish e
     * fácil de chamar de `aeroporto.js` direto a partir de uma linha de
     * resultado de busca, sem montar um payload maior que precisa.
     */
    #[Route('/aeroportos/{icao}/posto-avancado', name: 'app_aeroportos_marcar_posto', methods: ['POST'])]
    public function marcarPosto(string $icao, Request $request, EntityManagerInterface $em, AeroportoRepository $aeroportos): JsonResponse
    {
        if (null !== $err = $this->ensureAdmin($request)) {
            return $err;
        }

        $aeroporto = $aeroportos->findOneByIcao($icao);
        if (null === $aeroporto) {
            return $this->json(['error' => sprintf('Nenhum aeroporto cadastrado com o ICAO %s.', strtoupper($icao))], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }
        $postoAvancadoDe = $data['postoAvancadoDe'] ?? null;
        $postoAvancadoDe = null === $postoAvancadoDe ? null : trim((string) $postoAvancadoDe);

        if (null !== $postoAvancadoDe && '' !== $postoAvancadoDe && !in_array($postoAvancadoDe, self::BASES_VALIDAS, true)) {
            return $this->json(['error' => 'Posto avançado precisa ser de uma base válida (PAFA ou SCCI), ou null pra desmarcar.'], 422);
        }

        $aeroporto->setPostoAvancadoDe('' === $postoAvancadoDe ? null : $postoAvancadoDe);
        $em->flush();

        return $this->json($this->viewModel($aeroporto));
    }

    /**
     * Marca (ou limpa) o heading magnético da pista principal de um
     * aeroporto JÁ CADASTRADO — admin-only, mesmo espírito de
     * `marcarPosto()` acima (ação estreita sobre um ICAO que já existe,
     * em vez de reabrir o formulário de cadastro inteiro). Usado por
     * `TelemetryDeriver::recomputeWindcComHeadingDePista()`, chamado de
     * `AcarsIngestaoController::ingerir()` — ver `Aeroporto::$pistaPrincipalHeadingMag`.
     */
    #[Route('/aeroportos/{icao}/pista-principal', name: 'app_aeroportos_pista_principal', methods: ['POST'])]
    public function pistaPrincipal(string $icao, Request $request, EntityManagerInterface $em, AeroportoRepository $aeroportos): JsonResponse
    {
        if (null !== $err = $this->ensureAdmin($request)) {
            return $err;
        }

        $aeroporto = $aeroportos->findOneByIcao($icao);
        if (null === $aeroporto) {
            return $this->json(['error' => sprintf('Nenhum aeroporto cadastrado com o ICAO %s.', strtoupper($icao))], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }
        $raw = $data['pistaPrincipalHeadingMag'] ?? null;

        if (null === $raw || '' === $raw) {
            $aeroporto->setPistaPrincipalHeadingMag(null);
        } elseif (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 359) {
            return $this->json(['error' => 'Heading inválido — use de 0 a 359, ou null pra limpar.'], 422);
        } else {
            $aeroporto->setPistaPrincipalHeadingMag((int) round((float) $raw));
        }

        $em->flush();

        return $this->json($this->viewModel($aeroporto));
    }

    /**
     * Mesmo guard de `SolicitacoesController::ensureAdmin()` — sessão +
     * papel admin, devolvendo JSON em vez de redirecionar (esta rota é
     * chamada via `fetch` por `aeroporto.js`, não navegação de página).
     */
    private function ensureAdmin(Request $request): ?JsonResponse
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }
        if (empty($pilot['admin'])) {
            return $this->json(['error' => 'Ação restrita a administradores.'], 403);
        }

        return null;
    }

    /**
     * @return array{icao: string, nome: string, cidade: string, lat: float, lon: float, postoAvancadoDe: ?string, icaoOficial: bool, pistaPrincipalHeadingMag: ?int}
     */
    private function viewModel(Aeroporto $a): array
    {
        return [
            'icao' => $a->getIcao(),
            'nome' => $a->getNome(),
            'cidade' => $a->getCidade(),
            'lat' => $a->getLat(),
            'lon' => $a->getLon(),
            'postoAvancadoDe' => $a->getPostoAvancadoDe(),
            'icaoOficial' => $a->isIcaoOficial(),
            'pistaPrincipalHeadingMag' => $a->getPistaPrincipalHeadingMag(),
        ];
    }
}
