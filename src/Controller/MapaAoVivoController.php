<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mapa ao vivo (tela cheia): posição de toda a frota agora - aeronaves em
 * voo (marcador se movendo na rota) e aeronaves em solo (base/estação
 * atual). Pensada como o "centro de operações" de relance, sem entrar no
 * detalhe de uma perna especifica (isso e o /voo e o /aeronave/{reg}).
 *
 * `liveFlights()` sao as mesmas 2 aeronaves que o Portal ja marca como
 * "Em voo" (ver PortalController::fleet) - CC-KBA e N208KB - com a rota
 * (origem/destino real da rede) e o `flightId` que aponta pra telemetria
 * gravada de verdade em flights.json. Nao existe ainda um feed ao vivo de
 * posicao via ACARS, entao o mapa-ao-vivo.js *repete em loop* a telemetria
 * gravada (track/prof, ~5-6 min por gravacao) pra simular movimento
 * continuo - a posicao no mapa e sempre sintetizada como um ponto ao longo
 * do arco origem→destino (mesma solucao do historico de aeronave), nunca a
 * lat/lon real gravada (que fica geograficamente em outro lugar, ver
 * README). Isso e so pra essa tela ter "vida" como demonstracao; quando o
 * ACARS real existir, a posicao passa a vir de eventos reais e o replay em
 * loop desaparece.
 *
 * `parkedAircraft()` sao as outras 4 aeronaves da frota (mesma mock do
 * Portal), paradas na base ou numa estacao.
 */
class MapaAoVivoController extends AbstractController
{
    #[Route('/mapa-ao-vivo', name: 'app_mapa_ao_vivo', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('mapa_ao_vivo/index.html.twig', [
            'activeView' => 'mapaVivo',
            'pilot' => $pilot,
            'liveFlights' => $this->liveFlights(),
            'parkedAircraft' => $this->parkedAircraft(),
            'airportsUrl' => '/assets/data/airports.json',
            'flightsUrl' => '/assets/data/flights.json',
        ]);
    }

    /**
     * @return list<array{reg: string, modelo: string, callsign: string, tipo: string, origem: string, destino: string, tempoMin: int, flightId: string}>
     */
    private function liveFlights(): array
    {
        return [
            ['reg' => 'N208KB', 'modelo' => 'C208', 'callsign' => 'KBT118', 'tipo' => 'Carga', 'origem' => 'PAFA', 'destino' => 'PABT', 'tempoMin' => 52, 'flightId' => '20260819_033457_KBT118'],
            ['reg' => 'CC-KBA', 'modelo' => 'DHC6', 'callsign' => 'KBT412', 'tipo' => 'Pesquisa', 'origem' => 'SCCI', 'destino' => 'SCNT', 'tempoMin' => 64, 'flightId' => '20260819_032837_KBT118'],
        ];
    }

    /**
     * @return list<array{reg: string, modelo: string, base: string, pos: string, status: string, statusTag: string}>
     */
    private function parkedAircraft(): array
    {
        return [
            ['reg' => 'CC-KBC', 'modelo' => 'Cessna 208B Grand Caravan', 'base' => 'SCCI', 'pos' => 'SCCI', 'status' => 'Disponível', 'statusTag' => 'ok'],
            ['reg' => 'CC-KBD', 'modelo' => 'Pilatus PC-6 Porter', 'base' => 'SCCI', 'pos' => 'SCBA', 'status' => 'Fora de base', 'statusTag' => 'bad'],
            ['reg' => 'N412KB', 'modelo' => 'DHC-2 Beaver', 'base' => 'PAFA', 'pos' => 'PAFA', 'status' => 'Disponível', 'statusTag' => 'ok'],
            ['reg' => 'N67KB', 'modelo' => 'Beechcraft King Air 350', 'base' => 'PAFA', 'pos' => 'PAFA', 'status' => 'Disponível', 'statusTag' => 'ok'],
        ];
    }
}
