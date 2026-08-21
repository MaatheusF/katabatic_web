<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Entity\Voo;
use App\Repository\AeronaveRepository;
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
 * Bases continua mock (não existe schema de estação/METAR ainda).
 */
class PortalController extends AbstractController
{
    #[Route('/portal', name: 'app_portal', methods: ['GET'])]
    public function index(Request $request, PilotRepository $pilots, VooRepository $voos, AeronaveRepository $aeronaves): Response
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
            'bases' => $this->bases(),
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
     * @return array{north: array<string, mixed>, south: array<string, mixed>}
     */
    private function bases(): array
    {
        return [
            'north' => [
                'icao' => 'PAFA', 'name' => 'Fairbanks, Alasca', 'tag' => 'KBT Norte',
                'blurb' => 'Interior e Ártico. Suprimento de campos sem estrada e pernas de pesquisa acima do Círculo Polar.',
                'wind' => '210/09', 'windWarn' => false, 'temp' => '11 °C', 'vis' => '4 800 m', 'visWarn' => true, 'ceil' => '2 100 ft',
                'stations' => [
                    ['icao' => 'PABT', 'name' => 'Bettles', 'dist' => '168 nm', 'dot' => 'ok'],
                    ['icao' => 'PFYU', 'name' => 'Fort Yukon', 'dist' => '122 nm', 'dot' => 'accent'],
                    ['icao' => 'PAKP', 'name' => 'Anaktuvuk Pass', 'dist' => '228 nm', 'dot' => 'ice'],
                    ['icao' => 'PASC', 'name' => 'Deadhorse', 'dist' => '373 nm', 'dot' => 'danger'],
                    ['icao' => 'PAOT', 'name' => 'Kotzebue', 'dist' => '380 nm', 'dot' => 'ok'],
                ],
            ],
            'south' => [
                'icao' => 'SCCI', 'name' => 'Punta Arenas, Chile', 'tag' => 'KBT Sul',
                'blurb' => 'Magalhães e Patagônia. Apoio a estações de pesquisa, travessia de fiordes e transporte técnico.',
                'wind' => '280/41G56', 'windWarn' => true, 'temp' => '3 °C', 'vis' => '9 999 m', 'visWarn' => false, 'ceil' => '2 400 ft',
                'stations' => [
                    ['icao' => 'SCNT', 'name' => 'Puerto Natales', 'dist' => '130 nm', 'dot' => 'accent'],
                    ['icao' => 'SCGZ', 'name' => 'Puerto Williams', 'dist' => '150 nm', 'dot' => 'danger'],
                    ['icao' => 'SCFM', 'name' => 'Porvenir', 'dist' => '22 nm', 'dot' => 'ok'],
                    ['icao' => 'SCBA', 'name' => 'Balmaceda', 'dist' => '432 nm', 'dot' => 'ice'],
                ],
            ],
        ];
    }
}
