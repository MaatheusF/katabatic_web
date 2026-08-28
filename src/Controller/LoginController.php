<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Login da área do piloto.
 *
 * Autenticação de verdade a partir daqui - ver App\Security\
 * LoginFormAuthenticator (o autenticador que faz a validação real
 * contra a tabela `pilot`) e config/packages/security.yaml (comentário
 * no topo explica a estratégia de transição: o resto do site ainda lê
 * a sessão como antes, só o login em si que passou a ser real).
 *
 * GET e POST em /login são a MESMA rota (`app_login`) - convenção
 * padrão do Security do Symfony pra login por formulário: o POST nunca
 * chega até aqui, o LoginFormAuthenticator responde antes (ver
 * `supports()` nele). Este controller só cuida do GET (mostrar a
 * página, com o erro da tentativa anterior se houver).
 */
class LoginController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function show(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_portal');
        }

        // getLastAuthenticationError() traz a AuthenticationException que
        // o LoginFormAuthenticator guardou na sessão (ver
        // onAuthenticationFailure lá) - não expomos a mensagem dela direto
        // pro usuário (poderia vazar detalhe interno), só usamos como flag
        // "houve erro" e mostramos nosso próprio texto amigável.
        $error = $authenticationUtils->getLastAuthenticationError();

        return $this->render('login/index.html.twig', [
            'error' => $error ? 'CID ou senha inválidos.' : null,
            'cid' => $authenticationUtils->getLastUsername(),
        ]);
    }

    /**
     * Corpo vazio de propósito: a rota é interceptada pelo listener de
     * logout do firewall (`logout.path: app_logout` em security.yaml)
     * antes de chegar aqui - ele já invalida a sessão inteira (o que
     * também limpa o array 'pilot' do shim de transição) e redireciona.
     * A rota ainda precisa existir pra `path('app_logout')` funcionar
     * nos templates (rail etc.) e pro firewall ter um path pra casar.
     */
    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): void
    {
        throw new \LogicException('Esta rota deveria ter sido interceptada pelo listener de logout do firewall.');
    }
}
