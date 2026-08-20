<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cadastro de aeronave: identificacao (pais/matricula/tipo/base), limites
 * operacionais usados no indice de dificuldade, fotos e observacoes
 * internas. "Salvar aeronave" ainda e mock (alert no JS) - vira INSERT
 * de verdade quando a Frota for tabela real.
 */
class NovaAeronaveController extends AbstractController
{
    #[Route('/nova-aeronave', name: 'app_nova_aeronave', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('nova_aeronave/index.html.twig', [
            'activeView' => 'frota',
            'pilot' => $pilot,
        ]);
    }
}
