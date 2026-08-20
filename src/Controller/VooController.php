<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Relatorio de voo. A pagina em si e quase toda renderizada no cliente
 * (voo.js le a telemetria e desenha cabecalho, fases, mapa, graficos
 * sincronizados, pouso, composicao do indice e eventos), entao o
 * controller so garante a sessao e aponta o JS pro asset de telemetria.
 *
 * A telemetria em `public/assets/data/flights.json` e real (3 voos de
 * teste gravados via ACARS), nao mock - mas ainda nao vem do banco.
 * Quando o schema existir isso vira uma consulta por piloto/voo
 * (idealmente so os pontos do voo pedido, nao a lista inteira).
 */
class VooController extends AbstractController
{
    #[Route('/voo', name: 'app_voo', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('voo/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $pilot,
            'flightsUrl' => '/assets/data/flights.json',
        ]);
    }
}
