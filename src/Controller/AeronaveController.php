<?php

namespace App\Controller;

use App\Entity\Voo;
use App\Repository\AeronaveRepository;
use App\Repository\VooRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Histórico de aeronave: todas as pernas voadas por uma matrícula da
 * frota, plotadas num mapa (traçado + origem/destino de cada perna) e
 * listadas ao lado. Acessada a partir do card/linha da aeronave na
 * view Frota do Portal (ver PortalController::fleetViewModel()).
 *
 * **Atualizado (backend real):** a aeronave vem de
 * `App\Entity\Aeronave` (404 se a matrícula não existir na frota) e as
 * pernas vêm de `VooRepository::findAllByAeronaveReg()` — todo voo já
 * registrado com essa matrícula, de qualquer piloto, mais recente
 * primeiro. Antes desta fatia, esta tela gerava um histórico sintético
 * de ~17 pernas por matrícula (ver git log) só pra ter volume
 * suficiente pra demonstrar filtro de período; agora mostra o volume
 * real do Logbook, que hoje é pequeno (1-2 pernas por aeronave) porque
 * só existem 11 voos gravados no total — cresce conforme o Logbook
 * cresce, sem precisar gerar nada artificialmente.
 */
class AeronaveController extends AbstractController
{
    #[Route('/aeronave/{reg}', name: 'app_aeronave', methods: ['GET'])]
    public function index(Request $request, string $reg, AeronaveRepository $aeronaves, VooRepository $voos): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        $aircraft = $aeronaves->findOneByReg($reg);
        if (null === $aircraft) {
            throw $this->createNotFoundException('Aeronave não encontrada na frota.');
        }

        return $this->render('aeronave/index.html.twig', [
            'activeView' => 'frota',
            'pilot' => $pilot,
            'aircraft' => [
                'reg' => $aircraft->getReg(),
                'tipo' => $aircraft->getTipo(),
                'base' => $aircraft->getBase(),
                'status' => $aircraft->getStatusEfetivo(),
                'statusTag' => $aircraft->getStatusTag(),
                'horas' => $aircraft->getHoras(),
            ],
            'legs' => array_map(fn (Voo $v) => $this->legViewModel($v), $voos->findAllByAeronaveReg($reg)),
            'airportsUrl' => '/assets/data/airports.json',
            'nowIso' => '2026-08-19T12:00:00Z',
        ]);
    }

    /**
     * @return array{data: string, hora: string, callsign: string, tipo: string, origem: string, destino: string, tempo: string, tempoMin: int, dif: int, flightId: ?string}
     */
    private function legViewModel(Voo $v): array
    {
        $tempoMin = $v->getTempoMin();
        $tempo = sprintf('%d:%02d', intdiv($tempoMin, 60), $tempoMin % 60);

        return [
            'data' => $v->getStartedAt()->format('Y-m-d'),
            'hora' => $v->getStartedAt()->format('H:i').'Z',
            'callsign' => $v->getCallsign(),
            'tipo' => $v->getTipoOperacao(),
            'origem' => $v->getOrigem(),
            'destino' => $v->getDestino(),
            'tempo' => $tempo,
            'tempoMin' => $tempoMin,
            'dif' => $v->getDificuldade(),
            'flightId' => $v->getCodigo(),
        ];
    }
}
