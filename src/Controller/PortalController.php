<?php

namespace App\Controller;

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
 * verdade. Logbook e Frota tambem continuam mock. Quando o schema do
 * banco existir isso tudo vira consulta via Repository, filtrada pelo
 * piloto autenticado de verdade (ver README).
 */
class PortalController extends AbstractController
{
    #[Route('/portal', name: 'app_portal', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('portal/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $pilot,
            'logbookSummary' => $this->logbookSummary(),
            'fleetSummary' => $this->fleetSummary(),
            'logbook' => $this->logbook(),
            'fleet' => $this->fleet(),
            'bases' => $this->bases(),
            'nowIso' => '2026-08-19T12:00:00Z',
        ]);
    }

    /**
     * @return array{hours: string, flights: int, avgDifficulty: int, vatsimPct: int}
     */
    private function logbookSummary(): array
    {
        return ['hours' => '18,4', 'flights' => 23, 'avgDifficulty' => 57, 'vatsimPct' => 100];
    }

    /**
     * @return array{count: int, totalHours: string, inFlight: int, away: int}
     */
    private function fleetSummary(): array
    {
        return ['count' => 6, 'totalHours' => '1 266', 'inFlight' => 2, 'away' => 1];
    }

    /**
     * Um voo por linha, na mesma forma que o JS do mockup consumia -
     * vira JSON no template e o portal.js filtra/ordena no cliente.
     *
     * @return list<array<string, mixed>>
     */
    private function logbook(): array
    {
        return [
            ['data' => '2026-08-19', 'hora' => '03:34Z', 'callsign' => 'KBT118', 'tipo' => 'Carga', 'origem' => 'PAFA', 'destino' => 'PABT', 'rota' => 'Fairbanks → Bettles', 'aeronave' => 'N208KB', 'modelo' => 'C208', 'tempo' => '0:52', 'tempoMin' => 52, 'cond' => 'Neve · -20 °C', 'condTag' => 'bad', 'ocor' => 'Overspeed', 'ocorTag' => 'warn', 'dif' => 81],
            ['data' => '2026-08-19', 'hora' => '03:28Z', 'callsign' => 'KBT412', 'tipo' => 'Pesquisa', 'origem' => 'SCCI', 'destino' => 'SCNT', 'rota' => 'Punta Arenas → Puerto Natales', 'aeronave' => 'CC-KBA', 'modelo' => 'DHC6', 'tempo' => '1:04', 'tempoMin' => 64, 'cond' => 'Chuva · em nuvem', 'condTag' => 'warn', 'ocor' => null, 'ocorTag' => null, 'dif' => 74],
            ['data' => '2026-08-19', 'hora' => '03:22Z', 'callsign' => 'KBT207', 'tipo' => 'Pessoal', 'origem' => 'PAFA', 'destino' => 'PASC', 'rota' => 'Fairbanks → Deadhorse', 'aeronave' => 'N67KB', 'modelo' => 'BE20', 'tempo' => '1:48', 'tempoMin' => 108, 'cond' => 'Claro · seco', 'condTag' => 'ok', 'ocor' => 'Quique', 'ocorTag' => 'warn', 'dif' => 29],
            ['data' => '2026-08-17', 'hora' => '21:10Z', 'callsign' => 'KBT903', 'tipo' => 'Reposicionamento', 'origem' => 'SCCI', 'destino' => 'SCBA', 'rota' => 'Punta Arenas → Balmaceda', 'aeronave' => 'CC-KBD', 'modelo' => 'PC6', 'tempo' => '2:37', 'tempoMin' => 157, 'cond' => 'Vento 41G56', 'condTag' => 'warn', 'ocor' => null, 'ocorTag' => null, 'dif' => 66],
            ['data' => '2026-08-15', 'hora' => '14:02Z', 'callsign' => 'KBT118', 'tipo' => 'Carga', 'origem' => 'PAFA', 'destino' => 'PFYU', 'rota' => 'Fairbanks → Fort Yukon', 'aeronave' => 'N412KB', 'modelo' => 'DHC2', 'tempo' => '1:11', 'tempoMin' => 71, 'cond' => 'Gelo leve', 'condTag' => 'warn', 'ocor' => 'Pouso duro', 'ocorTag' => 'bad', 'dif' => 58],
            ['data' => '2026-08-14', 'hora' => '09:47Z', 'callsign' => 'KBT412', 'tipo' => 'Pesquisa', 'origem' => 'SCCI', 'destino' => 'SCGZ', 'rota' => 'Punta Arenas → Puerto Williams', 'aeronave' => 'CC-KBA', 'modelo' => 'DHC6', 'tempo' => '1:22', 'tempoMin' => 82, 'cond' => 'Turbulência severa', 'condTag' => 'bad', 'ocor' => null, 'ocorTag' => null, 'dif' => 88],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fleet(): array
    {
        return [
            ['reg' => 'CC-KBA', 'tipo' => 'DHC-6 Twin Otter 300', 'status' => 'Em voo', 'statusTag' => 'warn', 'base' => 'SCCI', 'pos' => 'SCNT', 'horas' => 318, 'ultimo' => '19/08'],
            ['reg' => 'CC-KBC', 'tipo' => 'Cessna 208B Grand Caravan', 'status' => 'Disponível', 'statusTag' => 'ok', 'base' => 'SCCI', 'pos' => 'SCCI', 'horas' => 204, 'ultimo' => '16/08'],
            ['reg' => 'CC-KBD', 'tipo' => 'Pilatus PC-6 Porter', 'status' => 'Fora de base', 'statusTag' => 'bad', 'base' => 'SCCI', 'pos' => 'SCBA', 'horas' => 96, 'ultimo' => '17/08'],
            ['reg' => 'N208KB', 'tipo' => 'Cessna 208B Grand Caravan', 'status' => 'Em voo', 'statusTag' => 'warn', 'base' => 'PAFA', 'pos' => 'PABT', 'horas' => 412, 'ultimo' => '19/08'],
            ['reg' => 'N412KB', 'tipo' => 'DHC-2 Beaver', 'status' => 'Disponível', 'statusTag' => 'ok', 'base' => 'PAFA', 'pos' => 'PAFA', 'horas' => 147, 'ultimo' => '15/08'],
            ['reg' => 'N67KB', 'tipo' => 'Beechcraft King Air 350', 'status' => 'Disponível', 'statusTag' => 'ok', 'base' => 'PAFA', 'pos' => 'PAFA', 'horas' => 89, 'ultimo' => '19/08'],
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
