<?php

namespace App\Controller;

use App\Repository\AeronaveRepository;
use App\Repository\AeroportoRepository;
use App\Repository\PosicaoAoVivoRepository;
use App\Repository\TipoAeronaveRepository;
use App\Repository\VooRepository;
use App\Service\MetarClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Home institucional publica.
 *
 * **Atualizado: parte dos dados virou real.** `bases()`/`crewCriteria()`
 * continuam mock (texto autoral, sem tabela por trás — ver docblock de
 * `bases()`). `board` (METAR), `fleet`, `recentFlights` e o mapa da
 * seção #mapa agora vêm de fonte real (`MetarClient`/`Aeronave`/`Voo`/
 * `PosicaoAoVivo`) — ver docblock de cada método abaixo.
 *
 * **Atualizado: ícone de helicóptero no marcador.** `liveFlightsPublicos()`
 * agora inclui `categoria` (`TipoAeronaveRepository::findCategoriasPorNome()`,
 * casamento fraco por `Aeronave::$tipo`) — `home.js` usa isso pra desenhar
 * o ícone certo (avião vs. helicóptero) no mapa da home. Mesmo padrão
 * aplicado em `MapaAoVivoController`. Pedido em conversa: "para aeronaves
 * do tipo helicóptero, no mapa da frota ou mapa ao vivo, precisamos exibir
 * o ícone de um helicóptero e não um aviãozinho".
 */
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        AeronaveRepository $aeronaves,
        VooRepository $voos,
        AeroportoRepository $aeroportos,
        MetarClient $metarClient,
        CacheInterface $cache,
        #[Autowire('%env(CARTO_API_KEY)%')] string $cartoApiKey,
    ): Response {
        $fleetTypes = $this->fleetTypes($aeronaves);
        $totalAeronaves = array_sum(array_column($fleetTypes, 'count'));

        return $this->render('home/index.html.twig', [
            'board' => $this->stationBoard($metarClient, $cache),
            'figures' => $this->companyFigures($totalAeronaves),
            'bases' => $this->bases(),
            'fleet' => $fleetTypes,
            'recentFlights' => $this->recentFlights($voos, $aeronaves, $aeroportos),
            'crewCriteria' => $this->crewCriteria(),
            'posicoesUrl' => $this->generateUrl('app_home_mapa_posicoes'),
            'cartoApiKey' => $cartoApiKey,
        ]);
    }

    /**
     * Posição ao vivo, em JSON, pro mapa da home — homólogo público de
     * `MapaAoVivoController::posicoes()`, mas SEM exigir sessão (a home
     * é a vitrine pública; `/mapa-ao-vivo` é ferramenta de piloto
     * logado). Ver `liveFlightsPublicos()` pra por que só entra quem
     * já tem ping real (nada de replay simulado aqui).
     */
    #[Route('/mapa-inicio/posicoes', name: 'app_home_mapa_posicoes', methods: ['GET'])]
    public function mapaPosicoes(AeronaveRepository $aeronaves, VooRepository $voos, PosicaoAoVivoRepository $posicoes, TipoAeronaveRepository $tipos): JsonResponse
    {
        return $this->json($this->liveFlightsPublicos($aeronaves, $voos, $posicoes, $tipos->findCategoriasPorNome()));
    }

    /**
     * Subconjunto público de `MapaAoVivoController::liveFlights()`:
     * só aeronaves "Em voo" com um ping real (`PosicaoAoVivo`) —
     * diferente do mapa interno, NÃO cai pro replay simulado
     * (`findMaisRecenteComTelemetriaByAeronaveReg()` sobre o arco
     * origem→destino) quando falta ping. Reproduzir aquele replay
     * aqui exigiria o mesmo pipeline de telemetria gravada
     * (`flights.json`) só pra uma vitrine pública — e mostrar só
     * posição real, sem fallback, é a leitura mais honesta pra quem
     * nunca logou: "aeronaves em voo" na home é literal.
     *
     * Campos também são um subconjunto (sem `onGround`/`iasKt`/
     * `atualizadaEm`, que a home não usa) — nunca expõe nada que
     * `MapaAoVivoController` já não exponha sem login (reg, modelo,
     * callsign e rota já eram públicos na própria home antes disso).
     *
     * @param array<string, string> $categoriasPorTipo `TipoAeronave::$nome` → `categoria` (`TipoAeronaveRepository::findCategoriasPorNome()`) — casamento fraco por `Aeronave::$tipo`, igual ao usado em `PortalController`/`MapaAoVivoController`. Alimenta o ícone (avião vs. helicóptero) no mapa da home.
     *
     * @return list<array{reg: string, modelo: string, callsign: string, origem: string, destino: string, lat: float, lon: float, altFt: ?int, hdgTrue: ?float, gsKt: ?int, categoria: ?string}>
     */
    private function liveFlightsPublicos(AeronaveRepository $aeronaves, VooRepository $voos, PosicaoAoVivoRepository $posicoes, array $categoriasPorTipo): array
    {
        $emVoo = $aeronaves->findAllEmVoo();
        $posicoesPorReg = $posicoes->findByAeronaves($emVoo);

        $out = [];
        foreach ($emVoo as $a) {
            $ping = $posicoesPorReg[$a->getReg()] ?? null;
            if (null === $ping) {
                continue;
            }
            $voo = $voos->findMaisRecenteComTelemetriaByAeronaveReg($a->getReg());
            $out[] = [
                'reg' => $a->getReg(),
                'modelo' => $a->getTipo(),
                'callsign' => $voo?->getCallsign() ?? $a->getReg(),
                'origem' => $voo?->getOrigem() ?? $a->getBase(),
                'destino' => $voo?->getDestino() ?? $a->getPosIcao(),
                'lat' => $ping->getLat(),
                'lon' => $ping->getLon(),
                'altFt' => $ping->getAltFt(),
                'hdgTrue' => $ping->getHdgTrue(),
                'gsKt' => $ping->getGsKt(),
                'categoria' => $categoriasPorTipo[$a->getTipo()] ?? null,
            ];
        }

        return $out;
    }

    /**
     * ICAO/nome/fuso de cada base — usado só por `stationBoard()` (nome
     * e fuso já existiam duplicados em `ferramentas.js` pra outra
     * calculadora; aqui é a versão PHP, pro cálculo de hora local
     * acontecer no servidor).
     *
     * @var list<array{icao: string, nameKey: string, nameFallback: string, tz: string}>
     */
    private const STATION_BOARD_BASES = [
        ['icao' => 'PAFA', 'nameKey' => 'board.pafa', 'nameFallback' => 'Fairbanks, Alasca', 'tz' => 'America/Anchorage'],
        ['icao' => 'SCCI', 'nameKey' => 'board.scci', 'nameFallback' => 'Punta Arenas, Chile', 'tz' => 'America/Punta_Arenas'],
        ['icao' => 'SLLP', 'nameKey' => 'board.sllp', 'nameFallback' => 'La Paz, Bolívia', 'tz' => 'America/La_Paz'],
        ['icao' => 'VNKT', 'nameKey' => 'board.vnkt', 'nameFallback' => 'Catmandu, Nepal', 'tz' => 'Asia/Kathmandu'],
        ['icao' => 'WAJW', 'nameKey' => 'board.wajw', 'nameFallback' => 'Wamena, Nova Guiné', 'tz' => 'Asia/Jayapura'],
        ['icao' => 'VQPR', 'nameKey' => 'board.vqpr', 'nameFallback' => 'Paro, Butão', 'tz' => 'Asia/Thimphu'],
    ];

    /**
     * **Atualizado: METAR real.** Era 100% decorativo (horário fixo +
     * vento/temp/vis/teto inventados à mão, calculados contra um único
     * instante de referência só pra não se contradizerem). Agora:
     *
     * - `time` é a hora local DE VERDADE de cada base agora mesmo
     *   (`DateTimeZone` do PHP, com DST resolvido pelo próprio tzdata —
     *   nada mais fixo/hardcoded).
     * - `metar` é o METAR mais recente publicado pra cada ICAO, via
     *   `MetarClient` (mesmo serviço que já alimenta o relatório de voo,
     *   `TelemetriaVooBuilder`) — cru, sem o prefixo do ICAO (já mostrado
     *   ao lado) e SEM parsing pra wind/temp/vis/teto separados: menos
     *   código pra interpretar errado um formato que varia por estação,
     *   e é literalmente como um piloto de verdade lê um METAR. `null`
     *   quando a estação não publica (comum em pista de bush sem
     *   estação oficial) ou a busca falha — o template mostra um aviso
     *   em vez de inventar número.
     * - `metarGusty` é só um `bool` (grupo `Gxx KT` no texto) pra
     *   destacar a linha inteira quando há rajada — sinal visual barato,
     *   sem precisar decompor o METAR.
     *
     * Cada busca passa por `cache.app` (10 min) — sem isso, toda visita
     * à home pública dispararia até 6 chamadas HTTP síncronas pro
     * aviationweather.gov, e METAR real não muda a cada segundo mesmo.
     *
     * @return list<array{icao: string, nameKey: string, nameFallback: string, time: string, metar: ?string, metarGusty: bool}>
     */
    private function stationBoard(MetarClient $metarClient, CacheInterface $cache): array
    {
        $out = [];
        foreach (self::STATION_BOARD_BASES as $base) {
            $agora = new \DateTimeImmutable('now', new \DateTimeZone($base['tz']));

            $raw = $cache->get('home.metar.'.$base['icao'], function (ItemInterface $item) use ($metarClient, $base) {
                $item->expiresAfter(600);

                return $metarClient->buscarMaisRecente($base['icao']);
            });
            $metar = null;
            $gusty = false;
            if (null !== $raw) {
                $metar = trim(preg_replace('/^'.preg_quote($base['icao'], '/').'\s+/', '', $raw));
                $gusty = 1 === preg_match('/\bG\d{2,3}KT\b/', $metar);
            }

            $out[] = [
                'icao' => $base['icao'],
                'nameKey' => $base['nameKey'],
                'nameFallback' => $base['nameFallback'],
                'time' => $agora->format('H:i T'),
                'metar' => $metar,
                'metarGusty' => $gusty,
            ];
        }

        return $out;
    }

    /**
     * `fig.fleet` agora é a contagem real de `Aeronave` (ver
     * `fleetTypes()`) — as outras três permanecem decorativas
     * (`fig.flights`/`fig.vatsim` não têm coluna equivalente ainda;
     * `fig.bases` é a mesma contagem fixa que `bases()` já é).
     *
     * @return list<array{value: string, labelKey: string}>
     */
    private function companyFigures(int $totalAeronaves): array
    {
        return [
            ['value' => '23', 'labelKey' => 'fig.flights', 'labelFallback' => 'Voos nos últimos 90 dias'],
            ['value' => '100%', 'labelKey' => 'fig.vatsim', 'labelFallback' => 'Operados na rede VATSIM'],
            ['value' => (string) $totalAeronaves, 'labelKey' => 'fig.fleet', 'labelFallback' => 'Aeronaves na frota'],
            ['value' => '6', 'labelKey' => 'fig.bases', 'labelFallback' => 'Bases operacionais'],
        ];
    }

    /**
     * **Atualizado: 6 bases.** Virou uma lista simples (era um array fixo
     * `north`/`south` de duas chaves, de quando só existiam PAFA/SCCI) -
     * mesma mudança que `PortalController::bases()` já passou. PAFA e
     * SCCI mantêm foto de verdade (`photo`/`photoAlt`); as 4 novas ainda
     * não têm foto - `photo` fica `null` e o template usa o placeholder
     * padrão de `.shot-frame[data-hint]` (mesmo mecanismo que a seção
     * Frota já usa pros cartões sem foto) em vez de inventar uma imagem.
     *
     * @return list<array{icao: string, tagKey: string, tagFallback: string, titleKey: string, titleFallback: string, bodyKey: string, bodyFallback: string, field: string, destinations: int, avgLeg: string, basedAircraft: int, stations: list<array{icao: string, name: string}>, photo: ?string, photoAlt: ?string, capKey: ?string, capFallback: ?string}>
     */
    private function bases(): array
    {
        return [
            [
                'icao' => 'PAFA',
                'tagKey' => 'bases.pafa.tag', 'tagFallback' => 'KBT Norte · matrícula N',
                'titleKey' => 'bases.pafa.title', 'titleFallback' => 'Fairbanks — interior e Ártico',
                'bodyKey' => 'bases.pafa.body', 'bodyFallback' => 'Porta de entrada do Ártico. Daqui saem os voos de suprimento para campos sem estrada e as pernas de pesquisa acima do Círculo Polar. O interior soma o que a costa não tem: passes de montanha, aproximação em vale e frio que degrada desempenho antes de qualquer outra coisa.',
                'field' => 'PAFA', 'destinations' => 11, 'avgLeg' => '147 nm', 'basedAircraft' => 3,
                'stations' => [
                    ['icao' => 'PABT', 'name' => 'Bettles'],
                    ['icao' => 'PFYU', 'name' => 'Fort Yukon'],
                    ['icao' => 'PAKP', 'name' => 'Anaktuvuk Pass'],
                    ['icao' => 'PASC', 'name' => 'Deadhorse'],
                    ['icao' => 'PAOT', 'name' => 'Kotzebue'],
                ],
                'photo' => '/assets/img/home/base-norte.jpg',
                'photoAlt' => 'Caravan N208KB numa pista de cascalho em Bettles, Alasca, com montanhas nevadas ao fundo',
                'capKey' => 'cap.pafa', 'capFallback' => 'N208KB em Bettles',
            ],
            [
                'icao' => 'SCCI',
                'tagKey' => 'bases.scci.tag', 'tagFallback' => 'KBT Sul · matrícula CC',
                'titleKey' => 'bases.scci.title', 'titleFallback' => 'Punta Arenas — Magalhães e Patagônia',
                'bodyKey' => 'bases.scci.body', 'bodyFallback' => 'Apoio a estações de pesquisa, travessia de fiordes e transporte de pessoal técnico. É aqui que o vento catabático que dá nome à empresa aparece de verdade: rajadas descendo da cordilheira que mudam a aproximação nos últimos 500 pés.',
                'field' => 'SCCI', 'destinations' => 9, 'avgLeg' => '134 nm', 'basedAircraft' => 3,
                'stations' => [
                    ['icao' => 'SCNT', 'name' => 'Puerto Natales'],
                    ['icao' => 'SCGZ', 'name' => 'Puerto Williams'],
                    ['icao' => 'SCFM', 'name' => 'Porvenir'],
                    ['icao' => 'SCBA', 'name' => 'Balmaceda'],
                ],
                'photo' => '/assets/img/home/base-sul.jpg',
                'photoAlt' => 'Twin Otter CC-KBA em final para SCNT, sobrevoando um fiorde patagônico',
                'capKey' => null, 'capFallback' => null,
            ],
            [
                'icao' => 'SLLP',
                'tagKey' => 'bases.sllp.tag', 'tagFallback' => 'KBT Andes · matrícula CP',
                'titleKey' => 'bases.sllp.title', 'titleFallback' => 'La Paz — o teto do Altiplano',
                'bodyKey' => 'bases.sllp.body', 'bodyFallback' => 'A altitude aqui não é cenário, é a operação: a quase 4 100 m, o ar rarefeito encurta a pista efetiva e reescreve as V-speeds em quase todo pouso. Serve o Altiplano boliviano com o Illimani sempre no horizonte, onde a margem de performance já nasce apertada.',
                'field' => 'SLLP', 'destinations' => 6, 'avgLeg' => '58 nm', 'basedAircraft' => 2,
                'stations' => [
                    ['icao' => 'SLCN', 'name' => 'Charaña'],
                    ['icao' => 'SLVA', 'name' => 'Sica Sica'],
                    ['icao' => 'SLUY', 'name' => 'Uyuni'],
                ],
                'photo' => null,
                'photoAlt' => null,
                'capKey' => null, 'capFallback' => null,
            ],
            [
                'icao' => 'VNKT',
                'tagKey' => 'bases.vnkt.tag', 'tagFallback' => 'KBT Himalaia · matrícula 9N',
                'titleKey' => 'bases.vnkt.title', 'titleFallback' => 'Catmandu — portal do Himalaia',
                'bodyKey' => 'bases.vnkt.body', 'bodyFallback' => 'Hub de verdade pras pistas de altitude da região: Lukla, Jomsom e Pokhara operam a partir daqui, nunca o contrário. Aproximação em vale, sem chance de arremetida em boa parte das pistas vizinhas — o julgamento de tempo pesa tanto quanto a pilotagem.',
                'field' => 'VNKT', 'destinations' => 7, 'avgLeg' => '41 nm', 'basedAircraft' => 2,
                'stations' => [
                    ['icao' => 'VNLK', 'name' => 'Lukla'],
                    ['icao' => 'VNJS', 'name' => 'Jomsom'],
                    ['icao' => 'VNPK', 'name' => 'Pokhara'],
                ],
                'photo' => null,
                'photoAlt' => null,
                'capKey' => null, 'capFallback' => null,
            ],
            [
                'icao' => 'WAJW',
                'tagKey' => 'bases.wajw.tag', 'tagFallback' => 'KBT Papua · matrícula PK',
                'titleKey' => 'bases.wajw.title', 'titleFallback' => 'Wamena — o vale cercado',
                'bodyKey' => 'bases.wajw.body', 'bodyFallback' => 'O Vale do Baliem fica isolado por picos de mais de 4 000 m — sem estrada de fora pra dentro, só ar. Aproximação visual é regra, não exceção, e o tempo fecha rápido o bastante pra virar o fator decisivo do voo, não só uma condição de fundo.',
                'field' => 'WAJW', 'destinations' => 5, 'avgLeg' => '64 nm', 'basedAircraft' => 2,
                'stations' => [
                    ['icao' => 'WAJB', 'name' => 'Bokondini'],
                    ['icao' => 'WAJM', 'name' => 'Mulia'],
                ],
                'photo' => null,
                'photoAlt' => null,
                'capKey' => null, 'capFallback' => null,
            ],
            [
                'icao' => 'VQPR',
                'tagKey' => 'bases.vqpr.tag', 'tagFallback' => 'KBT Himalaia · Butão · matrícula A5',
                'titleKey' => 'bases.vqpr.title', 'titleFallback' => 'Paro — a aproximação mais estreita do mundo',
                'bodyKey' => 'bases.vqpr.body', 'bodyFallback' => 'O vale de Paro é cercado por picos de até 5 500 m, e não existe aproximação por instrumentos que sirva — é manobra visual entre encostas, com o vento definindo o curso final quase virada por virada. Uma das operações mais respeitadas do simulador, com razão.',
                'field' => 'VQPR', 'destinations' => 3, 'avgLeg' => '92 nm', 'basedAircraft' => 1,
                'stations' => [
                    ['icao' => 'VQBT', 'name' => 'Bumthang'],
                    ['icao' => 'VQTY', 'name' => 'Trashigang'],
                    ['icao' => 'VQGP', 'name' => 'Gelephu'],
                ],
                'photo' => null,
                'photoAlt' => null,
                'capKey' => null, 'capFallback' => null,
            ],
        ];
    }

    /**
     * **Atualizado: virou lista de TIPOS, não de matrículas.** Era um
     * array mock de 13 aeronaves individuais (uma por matrícula, com
     * cartão de foto cada) — a seção #frota agora só quer "os tipos de
     * aeronave voados" (pedido explícito: enxugar a home), então esta
     * função lê a frota real (`Aeronave`, mesma tabela que
     * `AeronaveController`/`MapaAoVivoController` já usam) e agrupa por
     * `tipo`. Ordena por quantidade (o tipo mais comum na frota primeiro)
     * e, em empate, por nome — sem depender da ordem de cadastro.
     *
     * @return list<array{tipo: string, count: int, bases: list<string>, horas: int}>
     */
    private function fleetTypes(AeronaveRepository $aeronaves): array
    {
        $porTipo = [];
        foreach ($aeronaves->findAllOrderedByBaseAndReg() as $a) {
            $tipo = $a->getTipo();
            $porTipo[$tipo] ??= ['tipo' => $tipo, 'count' => 0, 'bases' => [], 'horas' => 0];
            ++$porTipo[$tipo]['count'];
            $porTipo[$tipo]['horas'] += $a->getHoras();
            if (!\in_array($a->getBase(), $porTipo[$tipo]['bases'], true)) {
                $porTipo[$tipo]['bases'][] = $a->getBase();
            }
        }

        $lista = array_values($porTipo);
        foreach ($lista as &$linha) {
            sort($linha['bases']);
        }
        unset($linha);
        usort($lista, static fn (array $x, array $y) => $y['count'] <=> $x['count'] ?: strcmp($x['tipo'], $y['tipo']));

        return $lista;
    }

    /**
     * **Atualizado: dados reais.** Era um array mock de 4 voos fixos —
     * agora lê os 4 voos com telemetria mais recentes de toda a rede
     * (`VooRepository::findRecentesComTelemetria()`, sem filtro de
     * piloto, só `valido`). `type` e `fromToLabel` completam com o que
     * o banco souber (`Aeronave::$tipo`, `Aeroporto::$cidade`) e caem
     * pro ICAO puro quando a aeronave/aeroporto não existe mais no
     * catálogo — nunca quebra a home por um dado ausente.
     *
     * @return list<array{call: string, tagKey: string, tagFallback: string, from: string, to: string, fromToLabel: string, reg: string, type: string, date: string, duration: string, diff: int, level: string}>
     */
    private function recentFlights(VooRepository $voos, AeronaveRepository $aeronaves, AeroportoRepository $aeroportos): array
    {
        $tagsPorOperacao = [
            'Pesquisa' => ['op.research', 'Pesquisa'],
            'Carga' => ['op.cargo', 'Carga'],
            'Pessoal' => ['op.crew', 'Pessoal'],
            'Reposicionamento' => ['op.ferry', 'Reposicionamento'],
            'Medvec' => ['op.medevac', 'Medvec'],
        ];

        $out = [];
        foreach ($voos->findRecentesComTelemetria(4) as $voo) {
            [$tagKey, $tagFallback] = $tagsPorOperacao[$voo->getTipoOperacao()] ?? ['op.other', $voo->getTipoOperacao()];
            $aeronave = $aeronaves->findOneByReg($voo->getAeronaveReg());
            $origemAp = $aeroportos->findOneByIcao($voo->getOrigem());
            $destinoAp = $aeroportos->findOneByIcao($voo->getDestino());

            $out[] = [
                'call' => $voo->getCallsign(),
                'tagKey' => $tagKey, 'tagFallback' => $tagFallback,
                'from' => $voo->getOrigem(), 'to' => $voo->getDestino(),
                'fromToLabel' => ($origemAp?->getCidade() ?? $voo->getOrigem()).' → '.($destinoAp?->getCidade() ?? $voo->getDestino()),
                'reg' => $voo->getAeronaveReg(),
                'type' => $aeronave?->getTipo() ?? $voo->getAeronaveReg(),
                'date' => $voo->getStartedAt()->format('d/m'),
                'duration' => \sprintf('%d h %02d', intdiv($voo->getTempoMin(), 60), $voo->getTempoMin() % 60),
                'diff' => $voo->getDificuldade(),
                'level' => match (true) {
                    $voo->getDificuldade() >= 70 => 'high',
                    $voo->getDificuldade() >= 40 => 'mid',
                    default => 'low',
                },
            ];
        }

        return $out;
    }

    /**
     * @return list<array{key: string}>
     */
    private function crewCriteria(): array
    {
        return [
            ['num' => '01', 'key' => 'crew.c1', 'fallback' => '<strong>Conta VATSIM ativa.</strong> A avaliação e todos os voos acontecem na rede.'],
            ['num' => '02', 'key' => 'crew.c2', 'fallback' => '<strong>Três pernas com o ACARS.</strong> Em qualquer uma das nossas seis bases, à sua escolha.'],
            ['num' => '03', 'key' => 'crew.c3', 'fallback' => '<strong>Sem slew e sem aceleração de tempo.</strong> Detectados automaticamente; invalidam o voo.'],
            ['num' => '04', 'key' => 'crew.c4', 'fallback' => '<strong>Pouso dentro de -400 fpm</strong> e sem exceder o limite de G da aeronave.'],
            ['num' => '05', 'key' => 'crew.c5', 'fallback' => '<strong>Relatório de missão escrito.</strong> O que o sensor não mede, você conta.'],
        ];
    }
}
