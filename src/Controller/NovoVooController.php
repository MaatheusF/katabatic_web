<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registro de voo: importar telemetria (dropzone simula leitura das
 * pastas do script de captura) ou registro manual. Publicar/Salvar
 * rascunho ainda sao mock (alert no JS) - entram como POST de verdade
 * quando o Logbook for tabela real.
 *
 * A frota usada aqui e a mesma do Portal (ver PortalController::fleet),
 * so reformatada pro formato que o JS do formulario espera.
 */
class NovoVooController extends AbstractController
{
    #[Route('/novo-voo', name: 'app_novo_voo', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('novo_voo/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $pilot,
            'aircraft' => $this->aircraftFleet(),
        ]);
    }

    /**
     * @return list<array{reg: string, tipo: string, base: string, pos: string, status: string, dot: string}>
     */
    private function aircraftFleet(): array
    {
        return [
            ['reg' => 'CC-KBA', 'tipo' => 'DHC-6 Twin Otter 300', 'base' => 'SCCI', 'pos' => 'SCNT', 'status' => 'Em voo', 'dot' => 'var(--accent)'],
            ['reg' => 'CC-KBC', 'tipo' => 'Cessna 208B Grand Caravan', 'base' => 'SCCI', 'pos' => 'SCCI', 'status' => 'Disponível', 'dot' => 'var(--ok)'],
            ['reg' => 'CC-KBD', 'tipo' => 'Pilatus PC-6 Porter', 'base' => 'SCCI', 'pos' => 'SCBA', 'status' => 'Fora de base', 'dot' => 'var(--danger)'],
            ['reg' => 'N208KB', 'tipo' => 'Cessna 208B Grand Caravan', 'base' => 'PAFA', 'pos' => 'PABT', 'status' => 'Em voo', 'dot' => 'var(--accent)'],
            ['reg' => 'N412KB', 'tipo' => 'DHC-2 Beaver', 'base' => 'PAFA', 'pos' => 'PAFA', 'status' => 'Disponível', 'dot' => 'var(--ok)'],
            ['reg' => 'N67KB', 'tipo' => 'Beechcraft King Air 350', 'base' => 'PAFA', 'pos' => 'PAFA', 'status' => 'Disponível', 'dot' => 'var(--ok)'],
        ];
    }
}
