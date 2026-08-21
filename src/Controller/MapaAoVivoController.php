<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Repository\AeronaveRepository;
use App\Repository\VooRepository;
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
 * **Atualizado (backend real):** a frota (quem está "Em voo" vs. parada,
 * base, posição) vem de `App\Entity\Aeronave` em vez de dois arrays
 * mock. Continua sem existir um feed ao vivo de posição via ACARS (ver
 * README) - pra cada aeronave "Em voo", `liveFlights()` busca o voo com
 * telemetria mais recente dela (`VooRepository::findMaisRecenteComTelemetriaByAeronaveReg()`)
 * e `mapa-ao-vivo.js` *repete em loop* essa gravação (~5-6 min) pra
 * simular movimento contínuo - a posição no mapa é sempre sintetizada
 * como um ponto ao longo do arco origem→destino, nunca a lat/lon real
 * gravada (que fica geograficamente em outro lugar, ver README). Isso é
 * só pra essa tela ter "vida" como demonstração; quando o ACARS real
 * existir, a posição passa a vir de eventos reais e o replay em loop
 * desaparece.
 */
class MapaAoVivoController extends AbstractController
{
    #[Route('/mapa-ao-vivo', name: 'app_mapa_ao_vivo', methods: ['GET'])]
    public function index(Request $request, AeronaveRepository $aeronaves, VooRepository $voos): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('mapa_ao_vivo/index.html.twig', [
            'activeView' => 'mapaVivo',
            'pilot' => $pilot,
            'liveFlights' => $this->liveFlights($aeronaves, $voos),
            'parkedAircraft' => $this->parkedAircraft($aeronaves),
            'airportsUrl' => '/assets/data/airports.json',
            'flightsUrl' => '/assets/data/flights.json',
        ]);
    }

    /**
     * Uma entrada por aeronave "Em voo" que tenha telemetria gravada
     * pra repetir em loop — uma aeronave "Em voo" sem nenhum voo com
     * telemetria gravada simplesmente não aparece na lista (não existe
     * gravação nenhuma pra simular movimento dela); ver docblock da
     * classe.
     *
     * @return list<array{reg: string, modelo: string, callsign: string, tipo: string, origem: string, destino: string, tempoMin: int, flightId: string}>
     */
    private function liveFlights(AeronaveRepository $aeronaves, VooRepository $voos): array
    {
        $out = [];
        foreach ($aeronaves->findAllEmVoo() as $a) {
            $voo = $voos->findMaisRecenteComTelemetriaByAeronaveReg($a->getReg());
            if (null === $voo) {
                continue;
            }
            $out[] = [
                'reg' => $a->getReg(),
                'modelo' => $a->getTipo(),
                'callsign' => $voo->getCallsign(),
                'tipo' => $voo->getTipoOperacao(),
                'origem' => $voo->getOrigem(),
                'destino' => $voo->getDestino(),
                'tempoMin' => $voo->getTempoMin(),
                'flightId' => $voo->getCodigo(),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{reg: string, modelo: string, base: string, pos: string, status: string, statusTag: string}>
     */
    private function parkedAircraft(AeronaveRepository $aeronaves): array
    {
        return array_map(
            fn (Aeronave $a) => [
                'reg' => $a->getReg(),
                'modelo' => $a->getTipo(),
                'base' => $a->getBase(),
                'pos' => $a->getPosIcao(),
                'status' => $a->getStatusEfetivo(),
                'statusTag' => $a->getStatusTag(),
            ],
            $aeronaves->findAllNotEmVoo()
        );
    }
}
