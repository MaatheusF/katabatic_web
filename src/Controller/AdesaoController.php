<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Solicitação de adesão: tela pública (sem sessão) onde quem quer virar
 * piloto da Katabatic se candidata. "Enviar solicitação" ainda é mock
 * (JS troca o formulário por uma confirmação, sem POST de verdade) -
 * quando o schema do banco existir isso vira INSERT numa tabela de
 * solicitações, que SolicitacoesController passa a consultar de
 * verdade em vez do array mock em SolicitacoesController::solicitacoes().
 *
 * Por enquanto os dois lados (este formulário e o grid de Solicitações
 * na área logada) não estão de fato ligados - o que é enviado aqui não
 * aparece no grid, que tem seu próprio conjunto mock. Ver README.
 */
class AdesaoController extends AbstractController
{
    #[Route('/adesao', name: 'app_adesao', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('adesao/index.html.twig');
    }
}
