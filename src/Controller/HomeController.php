<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Home institucional publica.
 *
 * Os dados abaixo ainda sao mock, na mesma estrutura que os mockups em
 * docs/mockups-originais usavam no JS. Quando o schema do banco existir
 * (ver README), isso vira uma consulta via Repository em vez de arrays
 * fixos aqui — a template nao deve precisar mudar.
 */
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'board' => $this->stationBoard(),
            'figures' => $this->companyFigures(),
            'bases' => $this->bases(),
            'fleet' => $this->fleet(),
            'recentFlights' => $this->recentFlights(),
            'crewCriteria' => $this->crewCriteria(),
        ]);
    }

    /**
     * @return list<array{icao: string, nameKey: string, nameFallback: string, time: string, wind: string, windWarn: bool, temp: string, vis: string, visWarn: bool, ceil: string}>
     */
    private function stationBoard(): array
    {
        return [
            [
                'icao' => 'PAFA',
                'nameKey' => 'board.pafa',
                'nameFallback' => 'Fairbanks, Alasca',
                'time' => '04:12 AKDT',
                'wind' => '210/09',
                'windWarn' => false,
                'temp' => '11 °C',
                'vis' => '4 800 m FU',
                'visWarn' => true,
                'ceil' => '2 100 ft',
            ],
            [
                'icao' => 'SCCI',
                'nameKey' => 'board.scci',
                'nameFallback' => 'Punta Arenas, Chile',
                'time' => '09:12 CLT',
                'wind' => '280/41G56',
                'windWarn' => true,
                'temp' => '3 °C',
                'vis' => '9 999 m',
                'visWarn' => false,
                'ceil' => '2 400 ft',
            ],
        ];
    }

    /**
     * @return list<array{value: string, labelKey: string}>
     */
    private function companyFigures(): array
    {
        return [
            ['value' => '23', 'labelKey' => 'fig.flights', 'labelFallback' => 'Voos nos últimos 90 dias'],
            ['value' => '100%', 'labelKey' => 'fig.vatsim', 'labelFallback' => 'Operados na rede VATSIM'],
            ['value' => '6', 'labelKey' => 'fig.fleet', 'labelFallback' => 'Aeronaves na frota'],
            ['value' => '2', 'labelKey' => 'fig.bases', 'labelFallback' => 'Bases operacionais'],
        ];
    }

    /**
     * @return array{north: array<string, mixed>, south: array<string, mixed>}
     */
    private function bases(): array
    {
        return [
            'north' => [
                'field' => 'PAFA',
                'destinations' => 11,
                'avgLeg' => '147 nm',
                'basedAircraft' => 3,
                'stations' => [
                    ['icao' => 'PABT', 'name' => 'Bettles'],
                    ['icao' => 'PFYU', 'name' => 'Fort Yukon'],
                    ['icao' => 'PAKP', 'name' => 'Anaktuvuk Pass'],
                    ['icao' => 'PASC', 'name' => 'Deadhorse'],
                    ['icao' => 'PAOT', 'name' => 'Kotzebue'],
                ],
            ],
            'south' => [
                'field' => 'SCCI',
                'destinations' => 9,
                'avgLeg' => '134 nm',
                'basedAircraft' => 3,
                'stations' => [
                    ['icao' => 'SCNT', 'name' => 'Puerto Natales'],
                    ['icao' => 'SCGZ', 'name' => 'Puerto Williams'],
                    ['icao' => 'SCFM', 'name' => 'Porvenir'],
                    ['icao' => 'SCBA', 'name' => 'Balmaceda'],
                ],
            ],
        ];
    }

    /**
     * @return list<array{reg: string, type: string, base: string, hours: int}>
     */
    private function fleet(): array
    {
        return [
            ['reg' => 'CC-KBA', 'type' => 'DHC-6 Twin Otter 300', 'base' => 'SCCI', 'hours' => 318],
            ['reg' => 'CC-KBC', 'type' => 'Cessna 208B Grand Caravan', 'base' => 'SCCI', 'hours' => 204],
            ['reg' => 'CC-KBD', 'type' => 'Pilatus PC-6 Porter', 'base' => 'SCCI', 'hours' => 96],
            ['reg' => 'N208KB', 'type' => 'Cessna 208B Grand Caravan', 'base' => 'PAFA', 'hours' => 412],
            ['reg' => 'N412KB', 'type' => 'DHC-2 Beaver', 'base' => 'PAFA', 'hours' => 147],
            ['reg' => 'N67KB', 'type' => 'Beechcraft King Air 350', 'base' => 'PAFA', 'hours' => 89],
        ];
    }

    /**
     * @return list<array{call: string, tagKey: string, from: string, to: string, fromToLabel: string, reg: string, type: string, date: string, duration: string, diff: int, level: string}>
     */
    private function recentFlights(): array
    {
        return [
            [
                'call' => 'KBT412', 'tagKey' => 'op.research', 'tagFallback' => 'Pesquisa',
                'from' => 'SCCI', 'to' => 'SCNT', 'fromToLabel' => 'Punta Arenas → Puerto Natales',
                'reg' => 'CC-KBA', 'type' => 'Twin Otter', 'date' => '17/08', 'duration' => '1 h 04',
                'diff' => 78, 'level' => 'high',
            ],
            [
                'call' => 'KBT118', 'tagKey' => 'op.cargo', 'tagFallback' => 'Carga',
                'from' => 'PAFA', 'to' => 'PABT', 'fromToLabel' => 'Fairbanks → Bettles',
                'reg' => 'N208KB', 'type' => 'Caravan', 'date' => '15/08', 'duration' => '2 h 11',
                'diff' => 54, 'level' => 'mid',
            ],
            [
                'call' => 'KBT207', 'tagKey' => 'op.crew', 'tagFallback' => 'Pessoal',
                'from' => 'PAFA', 'to' => 'PASC', 'fromToLabel' => 'Fairbanks → Deadhorse',
                'reg' => 'N67KB', 'type' => 'King Air', 'date' => '14/08', 'duration' => '1 h 48',
                'diff' => 31, 'level' => 'low',
            ],
            [
                'call' => 'KBT903', 'tagKey' => 'op.ferry', 'tagFallback' => 'Reposicionamento',
                'from' => 'SCCI', 'to' => 'SCBA', 'fromToLabel' => 'Punta Arenas → Balmaceda',
                'reg' => 'CC-KBD', 'type' => 'PC-6', 'date' => '12/08', 'duration' => '2 h 37',
                'diff' => 66, 'level' => 'mid',
            ],
        ];
    }

    /**
     * @return list<array{key: string}>
     */
    private function crewCriteria(): array
    {
        return [
            ['num' => '01', 'key' => 'crew.c1', 'fallback' => '<strong>Conta VATSIM ativa.</strong> A avaliação e todos os voos acontecem na rede.'],
            ['num' => '02', 'key' => 'crew.c2', 'fallback' => '<strong>Três pernas com o ACARS.</strong> Uma em cada base e uma à sua escolha.'],
            ['num' => '03', 'key' => 'crew.c3', 'fallback' => '<strong>Sem slew e sem aceleração de tempo.</strong> Detectados automaticamente; invalidam o voo.'],
            ['num' => '04', 'key' => 'crew.c4', 'fallback' => '<strong>Pouso dentro de -400 fpm</strong> e sem exceder o limite de G da aeronave.'],
            ['num' => '05', 'key' => 'crew.c5', 'fallback' => '<strong>Relatório de missão escrito.</strong> O que o sensor não mede, você conta.'],
        ];
    }
}
