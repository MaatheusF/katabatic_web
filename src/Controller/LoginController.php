<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Login da area do piloto.
 *
 * MOCK: nao ha Security component, User entity nem hash de senha ainda -
 * so um "banco" de pilotos fixo em PHP e a sessao guardando quem esta
 * logado. Serve pra validar o fluxo (Home -> Login -> Portal) e a UX de
 * erro antes de trocar isso pelo Security real do Symfony (authenticator
 * + Doctrine User + password hasher), quando o schema do banco existir.
 */
class LoginController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function show(Request $request): Response
    {
        if ($request->getSession()->get('pilot')) {
            return $this->redirectToRoute('app_portal');
        }

        return $this->render('login/index.html.twig', [
            'error' => null,
            'cid' => '',
        ]);
    }

    #[Route('/login', name: 'app_login_submit', methods: ['POST'])]
    public function authenticate(Request $request): Response
    {
        $cid = trim((string) $request->request->get('cid', ''));
        $password = (string) $request->request->get('password', '');

        $pilot = $this->findMockPilot($cid, $password);

        if (null === $pilot) {
            return $this->render('login/index.html.twig', [
                'error' => 'CID ou senha inválidos. No modo mock, use o CID 1234567 com qualquer senha.',
                'cid' => $cid,
            ]);
        }

        $request->getSession()->set('pilot', $pilot);

        return $this->redirectToRoute('app_portal');
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(Request $request): Response
    {
        $request->getSession()->invalidate();

        return $this->redirectToRoute('app_home');
    }

    /**
     * "Base de pilotos" mock. Quando o schema do banco existir isso vira
     * PilotRepository::findByCid() + password_verify() contra o hash.
     *
     * @return array{initials: string, name: string, cid: string}|null
     */
    private function findMockPilot(string $cid, string $password): ?array
    {
        if ('' === $cid || '' === $password) {
            return null;
        }

        $mockPilots = [
            '1234567' => ['initials' => 'KB', 'name' => 'Comandante', 'cid' => '1234567'],
        ];

        return $mockPilots[$cid] ?? null;
    }
}
