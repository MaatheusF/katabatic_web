<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Entity\Aeroporto;
use App\Entity\Voo;
use App\Repository\AeronaveRepository;
use App\Repository\AeroportoRepository;
use App\Repository\PilotRepository;
use App\Repository\VooRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Area logada: Logbook, Frota e Bases numa unica pagina (as tres "views"
 * trocam de visibilidade via JS, igual ao mockup original).
 *
 * Autenticacao ainda e mock (ver LoginController): exige so que a sessao
 * tenha um piloto guardado, sem Security component nem User entity de
 * verdade nesta tela. **Atualizado (backend real):** Logbook (`Voo`) e
 * Frota (`Aeronave`) já são backend real - ver "Backend: voos e
 * telemetria" e "Backend: mapa ao vivo e histórico da frota" no README.
 *
 * **Atualizado: Bases religada ao catálogo `Aeroporto`.** A lista de
 * "Estações avançadas" de cada base (`bases().stations`) agora vem de
 * `AeroportoRepository::findPostosAvancadosDe()` — um aeroporto
 * cadastrado em `/aeroportos` e marcado como posto avançado de uma base
 * aparece aqui sem deploy. Distância (`dist`) é calculada de verdade
 * (Haversine a partir das coordenadas da base no catálogo), não mais um
 * número fixo.
 *
 * **Atualizado: clima real no boletim de cada base.** `bases()` não
 * inventa mais vento/temperatura/visibilidade/nuvens — devolve só
 * `lat`/`lon` de cada base e posto avançado (agregados em `basesWx`/
 * `stationsWx` dentro de `index()`), e é `portal.js` quem busca o clima
 * de verdade na Open-Meteo, no navegador, do mesmo jeito que o Mapa ao
 * vivo já fazia (`mapa-ao-vivo.js`, `loadWeather()`) — mesma API
 * pública gratuita, mesmo padrão de try/catch silencioso se ela cair.
 * Ver docblock de `bases()` pra por que "Teto" virou "Nuvens baixas"
 * nesse boletim.
 *
 * **Atualizado: bases sazonais.** `bases()` cresceu de duas entradas
 * fixas (`north`/`south`) pra uma lista de seis — PAFA/SCCI originais
 * mais SLLP (La Paz, Bolívia), VNKT (Catmandu, Nepal), WAJW (Wamena,
 * Nova Guiné) e VQPR (Paro, Butão), locais extremos de propósito (ver
 * `app:importar-bases-sazonais` e README "Bases sazonais"). A base do
 * Nepal é Catmandu (VNKT), não Lukla — Lukla (VNLK) é só destino, sem
 * infraestrutura pra basear frota, então virou posto avançado de VNKT
 * em vez de base (correção feita em conversa, ver docblock de
 * `App\Entity\Aeroporto`). Cada uma das quatro bases novas ganhou 3
 * postos avançados de verdade da própria região. BGSF (Groenlândia) não
 * vira uma sétima entrada aqui — é posto avançado isolado de PAFA, então
 * já aparece sozinho dentro de `postosAvancados($aeroportos, 'PAFA')`,
 * mesmo mecanismo de sempre.
 *
 * **Atualizado: Pousos e Condições extremas — duas views novas, mesmo
 * mecanismo de Frota/Bases.** `pousosViewModel()`/`condicoesViewModel()`
 * abaixo agregam, entre TODOS os voos do piloto com telemetria (não só
 * o mais recente), o toque de cada pouso e os eventos `warn`/`bad` de
 * cada voo — dado que `TelemetryDeriver` já calculava por voo, mas que
 * só existia preso dentro do relatório individual (`/voo`), sem visão
 * agregada nenhuma. Renderizadas em Twig puro (`{% for %}` direto,
 * como "Bases" já fazia) em vez do motor de filtro/ordenação/paginação
 * em JS que o Logbook usa — são listas de leitura, sem filtro nesta
 * primeira fatia.
 */
class PortalController extends AbstractController
{
    #[Route('/portal', name: 'app_portal', methods: ['GET'])]
    public function index(Request $request, PilotRepository $pilots, VooRepository $voos, AeronaveRepository $aeronaves, AeroportoRepository $aeroportos): Response
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->redirectToRoute('app_login');
        }

        $pilotEntity = $pilots->findOneByCid($sessionPilot['cid']);
        $voosDoPiloto = null !== $pilotEntity ? $voos->findAllForPilot($pilotEntity) : [];
        $logbook = array_map(fn (Voo $v) => $this->logbookViewModel($v), $voosDoPiloto);
        $fleet = array_map(
            fn (Aeronave $a) => $this->fleetViewModel($a, $voos),
            $aeronaves->findAllOrderedByBaseAndReg()
        );
        $bases = $this->bases($aeroportos);

        return $this->render('portal/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $sessionPilot,
            'logbookSummary' => $this->logbookSummary($logbook),
            'fleetSummary' => $this->fleetSummary($fleet),
            'logbook' => $logbook,
            'fleet' => $fleet,
            'bases' => $bases,
            'basesWx' => $this->basesWx($bases),
            'stationsWx' => $this->stationsWx($bases),
            'pousos' => $this->pousosViewModel($voosDoPiloto),
            'condicoes' => $this->condicoesViewModel($voosDoPiloto),
            'nowIso' => '2026-08-19T12:00:00Z',
        ]);
    }

    /**
     * Todo toque com telemetria registrada (`telemetria.td`), mais
     * recente primeiro — `$voos` já vem nessa ordem de
     * `VooRepository::findAllForPilot()`, então só filtrar preserva a
     * ordem, sem precisar reordenar. Só voos com telemetria de verdade
     * têm `td` (ver `Voo::hasTelemetria()`) — voos narrativos (sem
     * ACARS) simplesmente não aparecem aqui, mesma lógica de sempre pra
     * "o que é clicável no Logbook".
     *
     * Mesmos limiares de classificação que `TelemetryDeriver::
     * deriveEvents()` já usa pro selo do evento "Toque no solo" dentro
     * do relatório individual (`HARD_LANDING_FPM = 450`, `300` pro
     * meio-termo) — mantém a mesma régua em vez de inventar uma nova
     * só pra esta lista agregada.
     *
     * @param list<Voo> $voos
     *
     * @return list<array<string, mixed>>
     */
    private function pousosViewModel(array $voos): array
    {
        $out = [];
        foreach ($voos as $v) {
            $telemetria = $v->getTelemetria();
            $td = \is_array($telemetria) ? ($telemetria['td'] ?? null) : null;
            if (!\is_array($td) || !isset($td['vs'])) {
                continue;
            }

            $vs = (float) $td['vs'];
            $dados = $v->getDados();
            $out[] = [
                'data' => $v->getStartedAt()->format('Y-m-d'),
                'hora' => $v->getStartedAt()->format('H:i').'Z',
                'callsign' => $v->getCallsign(),
                'origem' => $v->getOrigem(),
                'pouso' => $v->getDestinoReal() ?? $v->getDestino(),
                'diversao' => $v->hasPousoAlternativo(),
                'aeronave' => $v->getAeronaveReg(),
                'modelo' => $dados['modelo'] ?? null,
                'vs' => (int) round($vs),
                'pitch' => isset($td['pitch']) ? round((float) $td['pitch'], 1) : null,
                'bank' => isset($td['bank']) ? round((float) $td['bank'], 1) : null,
                // Vem prontos em PT de `TelemetryDeriver::attachSurfaceAtTouchdown()`
                // (SURFACE_TYPES/SURFACE_CONDITIONS) - `null` em voos
                // gravados antes deste refinamento (sem retroalimentação).
                'superficie' => $td['surface_type_label'] ?? null,
                'condicaoSuperficie' => $td['surface_cond_label'] ?? null,
                'classificacao' => $vs >= 450 ? 'duro' : ($vs >= 300 ? 'normal' : 'suave'),
                'flightId' => $v->getCodigo(),
                'acidentado' => $v->isAcidentado(),
            ];
        }

        return $out;
    }

    /**
     * Todo evento com severidade `warn`/`bad` de toda a telemetria do
     * piloto, mais recente primeiro — "condições extremas" no sentido
     * que `TelemetryDeriver::deriveEvents()` já classifica assim (gelo,
     * toque duro, excedência de G/overspeed/stall, sim_rate/slew
     * suspeitos...), só que agregado entre voos em vez de preso dentro
     * do relatório de cada um (onde já aparecem, ver "Registro de
     * eventos" em `voo.js`).
     *
     * `telemetria.events` vem no formato `[t_s, tipo, rótulo, extra,
     * severidade]` (offset em segundos desde o início do voo) — convertido
     * aqui pra data/hora absoluta só pra dar pra ordenar/exibir entre
     * voos diferentes; `$v->getStartedAt()` + offset é aproximado ao
     * segundo, o bastante pra uma lista, não pretende ser mais preciso
     * que isso.
     *
     * @param list<Voo> $voos
     *
     * @return list<array<string, mixed>>
     */
    private function condicoesViewModel(array $voos): array
    {
        $out = [];
        foreach ($voos as $v) {
            $telemetria = $v->getTelemetria();
            $events = \is_array($telemetria) ? ($telemetria['events'] ?? []) : [];
            if (!\is_array($events)) {
                continue;
            }

            foreach ($events as $ev) {
                $sev = $ev[4] ?? '';
                if (!\in_array($sev, ['warn', 'bad'], true)) {
                    continue;
                }

                $momento = $v->getStartedAt()->modify('+'.((int) ($ev[0] ?? 0)).' seconds');
                $out[] = [
                    'momento' => $momento,
                    'data' => $momento->format('Y-m-d'),
                    'hora' => $momento->format('H:i').'Z',
                    'callsign' => $v->getCallsign(),
                    'rota' => $v->getOrigem().' → '.($v->getDestinoReal() ?? $v->getDestino()),
                    'rotulo' => (string) ($ev[2] ?? ''),
                    'detalhe' => (string) ($ev[3] ?? ''),
                    'severidade' => $sev,
                    'flightId' => $v->getCodigo(),
                    'acidentado' => $v->isAcidentado(),
                ];
            }
        }

        // Eventos vêm ordenados dentro de cada voo (ver `withOffsets()`/
        // `usort()` em `TelemetryDeriver::derive()`), mas entre voos
        // diferentes só a ordem de `$voos` (mais recente primeiro)
        // garantia isso - com vários eventos por voo, precisa reordenar
        // pelo momento absoluto de verdade pra não intercalar errado.
        usort($out, static fn (array $a, array $b) => $b['momento'] <=> $a['momento']);

        return array_map(static function (array $row): array {
            unset($row['momento']);

            return $row;
        }, $out);
    }

    /**
     * Mesmo formato que `portal.js` já esperava do array mock antigo -
     * ver docblock de `App\Entity\Voo` pra onde cada campo mora.
     *
     * @return array<string, mixed>
     */
    private function logbookViewModel(Voo $v): array
    {
        $dados = $v->getDados();
        $h = $v->getTempoMin() / 60;
        $tempo = sprintf('%d:%02d', (int) floor($h), $v->getTempoMin() % 60);

        return [
            'data' => $v->getStartedAt()->format('Y-m-d'),
            'hora' => $v->getStartedAt()->format('H:i').'Z',
            'callsign' => $v->getCallsign(),
            'tipo' => $v->getTipoOperacao(),
            'origem' => $v->getOrigem(),
            'destino' => $v->getDestino(),
            'rota' => $dados['rota'],
            'aeronave' => $v->getAeronaveReg(),
            'modelo' => $dados['modelo'],
            'tempo' => $tempo,
            'tempoMin' => $v->getTempoMin(),
            'cond' => $dados['cond'],
            'condTag' => $dados['condTag'],
            'ocorrencias' => $dados['ocorrencias'],
            'dif' => $v->getDificuldade(),
            'flightId' => $v->getCodigo(),
            'dist' => $dados['dist'],
            'combustivelKg' => $dados['combustivelKg'],
            'carga' => $dados['carga'],
            'tempoSoloMin' => $dados['tempoSoloMin'],
            'tempoArMin' => $dados['tempoArMin'],
            'metar' => $dados['metar'],
        ];
    }

    /**
     * Calculado a partir do Logbook de verdade (`$logbook`, já no formato
     * de `logbookViewModel()`) em vez de vir fixo.
     *
     * **Atualizado: removido `vatsimPct`.** Existia um quarto número aqui
     * ("Na rede VATSIM"), sempre fixo em 100 — não existe (ainda) nenhuma
     * fonte pra validar cada voo contra o datafeed real da VATSIM (ver
     * README, "Próximos passos"), então era um número inventado, não uma
     * métrica de verdade. Removido em vez de deixar um "100%" enganoso;
     * volta quando a validação cruzada existir de verdade.
     *
     * @param list<array<string, mixed>> $logbook
     *
     * @return array{hours: string, flights: int, avgDifficulty: int}
     */
    private function logbookSummary(array $logbook): array
    {
        $flights = count($logbook);
        if (0 === $flights) {
            return ['hours' => '0,0', 'flights' => 0, 'avgDifficulty' => 0];
        }

        $totalMin = array_sum(array_column($logbook, 'tempoMin'));
        $avgDif = (int) round(array_sum(array_column($logbook, 'dif')) / $flights);
        $hours = sprintf('%d,%d', intdiv($totalMin, 60), intdiv(($totalMin % 60) * 10, 60));

        return ['hours' => $hours, 'flights' => $flights, 'avgDifficulty' => $avgDif];
    }

    /**
     * Calculado a partir da frota de verdade (`$fleet`, já no formato de
     * `fleetViewModel()`) em vez de vir fixo.
     *
     * @param list<array<string, mixed>> $fleet
     *
     * @return array{count: int, totalHours: string, inFlight: int, away: int}
     */
    private function fleetSummary(array $fleet): array
    {
        $totalHoras = array_sum(array_column($fleet, 'horas'));

        return [
            'count' => count($fleet),
            'totalHours' => number_format($totalHoras, 0, ',', ' '),
            'inFlight' => count(array_filter($fleet, fn ($a) => 'Em voo' === $a['status'])),
            'away' => count(array_filter($fleet, fn ($a) => 'Fora de base' === $a['status'])),
        ];
    }

    /**
     * Mesmo formato que `portal.js` já esperava do array mock antigo -
     * `ultimo` (data do voo mais recente dessa matrícula, `dd/mm`) é a
     * única coisa que não vem direto de `Aeronave` - busca o Logbook
     * dessa matrícula (`VooRepository::findAllByAeronaveReg()`, já
     * ordenado mais recente primeiro) e usa a primeira linha, se
     * existir.
     *
     * `observacoes` (`Aeronave::$observacoes`) passou a vir junto a
     * pedido — a docblock da entidade ainda diz "não aparece no site
     * público", mas a Frota do Portal só é visível a piloto logado
     * (mesmo guard de sessão de `index()`), então não é público de
     * verdade; string vazia/`null` faz o card/linha simplesmente omitir
     * o campo (`portal.js`), sem placeholder tipo "—".
     *
     * @return array<string, mixed>
     */
    private function fleetViewModel(Aeronave $a, VooRepository $voos): array
    {
        $ultimoVoo = $voos->findAllByAeronaveReg($a->getReg())[0] ?? null;

        return [
            'reg' => $a->getReg(),
            'tipo' => $a->getTipo(),
            'status' => $a->getStatusEfetivo(),
            'statusTag' => $a->getStatusTag(),
            'base' => $a->getBase(),
            'pos' => $a->getPosIcao(),
            'horas' => $a->getHoras(),
            'ultimo' => null !== $ultimoVoo ? $ultimoVoo->getStartedAt()->format('d/m') : '—',
            'observacoes' => $a->getObservacoes(),
        ];
    }

    /**
     * A view Bases nao e filtrada/ordenada no cliente, entao aqui vira
     * loop direto no Twig (nao precisa virar JSON) — só o `nome`/`tag`/
     * `blurb` de cada base e a lista de postos avançados (`stations`,
     * via `postosAvancados()`) vêm prontos daqui; `lat`/`lon` também vão
     * junto, mas só pra `index()` montar `basesWx` (ver abaixo), não pro
     * Twig usar diretamente.
     *
     * **Atualizado: bases sazonais.** Virou uma lista simples (era um
     * array fixo `north`/`south` de duas chaves) pra caber as quatro
     * bases novas sem inventar mais nomes de eixo geográfico - o Twig
     * (`portal/index.html.twig`, view Bases) itera direto em cima disso
     * agora (`{% for base in bases %}`), sem depender de `.north`/
     * `.south`. Ordem de exibição é a mesma da definição aqui embaixo
     * (PAFA, SCCI, depois as quatro sazonais na ordem que entraram no
     * pedido).
     *
     * **Atualizado: clima real (Open-Meteo), não mais vento/temperatura/
     * visibilidade/teto inventados.** Esta função não devolve mais
     * nenhum desses quatro campos — `portal.js` busca o clima de
     * verdade no navegador (`loadBasesWeather()`), mesmo padrão que
     * `mapa-ao-vivo.js` já usa (`loadWeather()`), e escreve direto nos
     * elementos `#base-wind-{icao}`/`#base-temp-{icao}`/
     * `#base-vis-{icao}`/`#base-cloud-{icao}` do template. "Teto" virou
     * "Nuvens baixas" (`cloud_cover_low`, % de cobertura de nuvem baixa)
     * porque a API de previsão da Open-Meteo não tem nenhuma variável de
     * altura de teto/base de nuvem (`cloud_base`) — só cobertura em %
     * por camada (`cloud_cover_low/mid/high`); em vez de inventar um
     * valor em pés sem fonte nenhuma por trás (o que "teto" sempre foi
     * até aqui), o boletim mostra a métrica real mais próxima do mesmo
     * problema ("o céu está fechando aqui em baixo?"). Visibilidade
     * (`visibility`, metros) só existe como variável horária na Open-
     * Meteo (não em `current`), então `portal.js` busca `hourly` junto e
     * usa a leitura mais próxima da hora atual.
     *
     * @return list<array<string, mixed>>
     */
    private function bases(AeroportoRepository $aeroportos): array
    {
        return [
            [
                'icao' => 'PAFA', 'name' => 'Fairbanks, Alasca', 'tag' => 'KBT Norte',
                'blurb' => 'Interior e Ártico. Suprimento de campos sem estrada e pernas de pesquisa acima do Círculo Polar.',
                ...$this->baseCoords($aeroportos, 'PAFA'),
                'stations' => $this->postosAvancados($aeroportos, 'PAFA'),
            ],
            [
                'icao' => 'SCCI', 'name' => 'Punta Arenas, Chile', 'tag' => 'KBT Sul',
                'blurb' => 'Magalhães e Patagônia. Apoio a estações de pesquisa, travessia de fiordes e transporte técnico.',
                ...$this->baseCoords($aeroportos, 'SCCI'),
                'stations' => $this->postosAvancados($aeroportos, 'SCCI'),
            ],
            [
                'icao' => 'SLLP', 'name' => 'La Paz, Bolívia', 'tag' => 'KBT Andes',
                'blurb' => 'Altiplano boliviano a ~4 061 m. Decolagem e pouso em altitude extrema, ar rarefeito e o Illimani no horizonte.',
                ...$this->baseCoords($aeroportos, 'SLLP'),
                'stations' => $this->postosAvancados($aeroportos, 'SLLP'),
            ],
            [
                'icao' => 'VNKT', 'name' => 'Catmandu, Nepal', 'tag' => 'KBT Himalaia',
                'blurb' => 'Portal do Himalaia. Hub de verdade pras pistas de altitude da região — Lukla, Jomsom e Pokhara operam a partir daqui, nunca o contrário.',
                ...$this->baseCoords($aeroportos, 'VNKT'),
                'stations' => $this->postosAvancados($aeroportos, 'VNKT'),
            ],
            [
                'icao' => 'WAJW', 'name' => 'Wamena, Nova Guiné', 'tag' => 'KBT Papua',
                'blurb' => 'Vale do Baliem, cercado por picos de mais de 4 000 m. Aproximação visual obrigatória e tempo que fecha rápido.',
                ...$this->baseCoords($aeroportos, 'WAJW'),
                'stations' => $this->postosAvancados($aeroportos, 'WAJW'),
            ],
            [
                'icao' => 'VQPR', 'name' => 'Paro, Butão', 'tag' => 'KBT Himalaia · Butão',
                'blurb' => 'Aproximação entre picos de até 5 500 m no vale de Paro — uma das mais desafiadoras do mundo, só de voo visual.',
                ...$this->baseCoords($aeroportos, 'VQPR'),
                'stations' => $this->postosAvancados($aeroportos, 'VQPR'),
            ],
        ];
    }

    /**
     * `lat`/`lon` da base (`null`/`null` se o ICAO ainda não está no
     * catálogo — mesma situação de borda que `postosAvancados()` já
     * trata) - separado numa função pequena só pra não repetir o
     * `findOneByIcao()`+null-check seis vezes dentro de `bases()`.
     *
     * @return array{lat: float|null, lon: float|null}
     */
    private function baseCoords(AeroportoRepository $aeroportos, string $icao): array
    {
        $base = $aeroportos->findOneByIcao($icao);

        return ['lat' => $base?->getLat(), 'lon' => $base?->getLon()];
    }

    /**
     * Postos avançados de uma base, prontos pro template (`portal/
     * index.html.twig`, bloco Bases). Substitui o array fixo que
     * `bases()` tinha antes desta fatia - ver README, "Backend:
     * aeroportos e pouso alternativo (diversão)".
     *
     * - `dist` é calculado de verdade (`AeroportoRepository::haversineKm()`
     *   entre a base e o posto, convertido nm = km × 0,539957) a partir
     *   das coordenadas do catálogo, não mais um número digitado à mão.
     * - `name` reaproveita `Aeroporto::$cidade` sem o sufixo de país/
     *   estado (tudo antes da primeira vírgula) - é o mesmo texto que o
     *   array mock antigo já usava pra essas 9 estações (ex.: "Kotzebue",
     *   não "Kotzebue, Alasca"); `Aeroporto::$nome` (nome oficial do
     *   aeroporto, ex. "Ralph Wien Memorial") fica só no popup do mapa.
     * - `lat`/`lon` vão junto só pra `stationsWx()` montar o payload de
     *   clima em `index()` - o Twig não usa essas duas chaves.
     * - **Atualizado: `dot` agora reflete clima real.** Era fixo em 'ok'
     *   (nenhuma fonte de condição por posto ainda) - agora
     *   `portal.js` busca a Open-Meteo pra cada posto junto das bases
     *   (`stationsWx`) e pinta `#stn-dot-{baseIcao}-{icao}` com a mesma
     *   régua de cor que `mapa-ao-vivo.js` usa por `weather_code`
     *   (ok/névoa+neve="ice"/chuva="warn"/tempestade="bad"). Aqui a
     *   função só devolve `var(--muted)` como cor inicial (cinza neutro
     *   "carregando"), nunca mais um "tudo ok" fabricado sem dado atrás.
     *
     * @return list<array{icao: string, name: string, dist: string, lat: float, lon: float}>
     */
    private function postosAvancados(AeroportoRepository $aeroportos, string $baseIcao): array
    {
        $base = $aeroportos->findOneByIcao($baseIcao);
        if (null === $base) {
            // Base ainda não foi importada/cadastrada no catálogo (ex.:
            // banco novo, `app:importar-aeroportos-legado` não rodou
            // ainda) - sem coordenada da base não dá pra calcular
            // distância, então a lista fica vazia em vez de quebrar.
            return [];
        }

        return array_map(
            function (Aeroporto $posto) use ($base): array {
                $nm = AeroportoRepository::haversineKm($base->getLat(), $base->getLon(), $posto->getLat(), $posto->getLon()) * 0.539957;

                return [
                    'icao' => $posto->getIcao(),
                    'name' => trim(explode(',', $posto->getCidade())[0]),
                    'dist' => sprintf('%d nm', (int) round($nm)),
                    'lat' => $posto->getLat(),
                    'lon' => $posto->getLon(),
                ];
            },
            $aeroportos->findPostosAvancadosDe($baseIcao)
        );
    }

    /**
     * `{icao, lat, lon}` de cada base com coordenada conhecida, pronto
     * pra `window.KATABATIC_BASES_WX` (ver docblock de `bases()` e
     * `portal.js::loadBasesWeather()`). Bases sem coordenada ainda
     * (catálogo incompleto) simplesmente não entram na lista - o
     * boletim delas fica com "—" até o catálogo ter a base cadastrada.
     *
     * @param list<array<string, mixed>> $bases
     *
     * @return list<array{icao: string, lat: float, lon: float}>
     */
    private function basesWx(array $bases): array
    {
        $out = [];
        foreach ($bases as $b) {
            if (null !== $b['lat'] && null !== $b['lon']) {
                $out[] = ['icao' => $b['icao'], 'lat' => $b['lat'], 'lon' => $b['lon']];
            }
        }

        return $out;
    }

    /**
     * `{icao, baseIcao, lat, lon}` de todo posto avançado de toda base,
     * pronto pra `window.KATABATIC_STATIONS_WX` (ver `basesWx()` acima e
     * `portal.js::loadBasesWeather()`). `baseIcao` vai junto porque o dot
     * de cada posto no template é renderizado sob a base dele
     * (`#stn-dot-{baseIcao}-{icao}`, ver `portal/index.html.twig`) - sem
     * isso `portal.js` não sabe qual elemento pintar quando o mesmo ICAO
     * teoricamente aparecesse sob mais de uma base.
     *
     * @param list<array<string, mixed>> $bases
     *
     * @return list<array{icao: string, baseIcao: string, lat: float, lon: float}>
     */
    private function stationsWx(array $bases): array
    {
        $out = [];
        foreach ($bases as $b) {
            foreach ($b['stations'] as $s) {
                $out[] = ['icao' => $s['icao'], 'baseIcao' => $b['icao'], 'lat' => $s['lat'], 'lon' => $s['lon']];
            }
        }

        return $out;
    }
}
