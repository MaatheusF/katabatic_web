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
 * cadastrado em `/aeroportos` e marcado como posto avançado de PAFA ou
 * SCCI aparece aqui sem deploy. Distância (`dist`) é calculada de
 * verdade (Haversine a partir das coordenadas de PAFA/SCCI no
 * catálogo), não mais um número fixo. O que continua mock, de
 * propósito (sem schema de estação/METAR ainda): cabeçalho de cada
 * base (vento/temperatura/visibilidade/teto) e a cor do indicador
 * (`dot`) de cada posto avançado — ver docblock de `bases()`.
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
        $logbook = null !== $pilotEntity
            ? array_map(fn (Voo $v) => $this->logbookViewModel($v), $voos->findAllForPilot($pilotEntity))
            : [];
        $fleet = array_map(
            fn (Aeronave $a) => $this->fleetViewModel($a, $voos),
            $aeronaves->findAllOrderedByBaseAndReg()
        );

        return $this->render('portal/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $sessionPilot,
            'logbookSummary' => $this->logbookSummary($logbook),
            'fleetSummary' => $this->fleetSummary($fleet),
            'logbook' => $logbook,
            'fleet' => $fleet,
            'bases' => $this->bases($aeroportos),
            'nowIso' => '2026-08-19T12:00:00Z',
        ]);
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
     * de `logbookViewModel()`) em vez de vir fixo — `vatsimPct` continua
     * 100 fixo de propósito: a validação cruzada com o datafeed da
     * VATSIM é trabalho da ingestão real do ACARS (ver README, "Próximos
     * passos"), não existe ainda nenhuma fonte pra calcular esse número.
     *
     * @param list<array<string, mixed>> $logbook
     *
     * @return array{hours: string, flights: int, avgDifficulty: int, vatsimPct: int}
     */
    private function logbookSummary(array $logbook): array
    {
        $flights = count($logbook);
        if (0 === $flights) {
            return ['hours' => '0,0', 'flights' => 0, 'avgDifficulty' => 0, 'vatsimPct' => 100];
        }

        $totalMin = array_sum(array_column($logbook, 'tempoMin'));
        $avgDif = (int) round(array_sum(array_column($logbook, 'dif')) / $flights);
        $hours = sprintf('%d,%d', intdiv($totalMin, 60), intdiv(($totalMin % 60) * 10, 60));

        return ['hours' => $hours, 'flights' => $flights, 'avgDifficulty' => $avgDif, 'vatsimPct' => 100];
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
        ];
    }

    /**
     * A view Bases nao e filtrada/ordenada no cliente, entao aqui vira
     * loop direto no Twig (nao precisa virar JSON).
     *
     * `stations` (via `postosAvancados()`) já vem do catálogo real - o
     * resto (nome/tag/blurb da base e o "boletim" de vento/temperatura/
     * visibilidade/teto do cabeçalho) continua fixo de propósito: não
     * existe schema de estação meteorológica nem busca de METAR/TAF
     * ainda (ver README, "Próximos passos") - diferente do Mapa ao vivo,
     * que já tem clima real via Open-Meteo pra cada aeroporto do
     * catálogo, mas só pra popup de aeronave, não pro boletim por base
     * daqui. Ligar os dois é trabalho futuro, não desta fatia.
     *
     * @return array{north: array<string, mixed>, south: array<string, mixed>}
     */
    private function bases(AeroportoRepository $aeroportos): array
    {
        return [
            'north' => [
                'icao' => 'PAFA', 'name' => 'Fairbanks, Alasca', 'tag' => 'KBT Norte',
                'blurb' => 'Interior e Ártico. Suprimento de campos sem estrada e pernas de pesquisa acima do Círculo Polar.',
                'wind' => '210/09', 'windWarn' => false, 'temp' => '11 °C', 'vis' => '4 800 m', 'visWarn' => true, 'ceil' => '2 100 ft',
                'stations' => $this->postosAvancados($aeroportos, 'PAFA'),
            ],
            'south' => [
                'icao' => 'SCCI', 'name' => 'Punta Arenas, Chile', 'tag' => 'KBT Sul',
                'blurb' => 'Magalhães e Patagônia. Apoio a estações de pesquisa, travessia de fiordes e transporte técnico.',
                'wind' => '280/41G56', 'windWarn' => true, 'temp' => '3 °C', 'vis' => '9 999 m', 'visWarn' => false, 'ceil' => '2 400 ft',
                'stations' => $this->postosAvancados($aeroportos, 'SCCI'),
            ],
        ];
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
     * - `dot` (cor do indicador) fica fixo em 'ok' de propósito: não há
     *   fonte de clima/condição por posto avançado ainda (ver docblock
     *   de `bases()`) - antes era um valor mock inventado por estação,
     *   então um indicador neutro é mais honesto que continuar
     *   fabricando cor sem dado real por trás.
     *
     * @return list<array{icao: string, name: string, dist: string, dot: string}>
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
                    'dot' => 'ok',
                ];
            },
            $aeroportos->findPostosAvancadosDe($baseIcao)
        );
    }
}
