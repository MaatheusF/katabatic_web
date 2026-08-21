<?php

namespace App\Security;

use App\Repository\PilotRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Autenticador de login "de verdade" - substitui o mock que existia em
 * LoginController::findMockPilot() (sessão com um array fixo, CID
 * 1234567 aceitando qualquer senha). Formulário e telas continuam
 * exatamente os mesmos (mesmo template, mesmo CID de exemplo pra dev -
 * ver README pra credencial semeada pela migration); só a validação
 * por trás passou a ser real, contra a tabela `pilot`.
 *
 * GET e POST em /login são a MESMA rota (`app_login`, ver
 * LoginController::show()) - convenção padrão do Security do Symfony
 * pra login por formulário: supports() abaixo só intercepta o POST
 * (a submissão do formulário); o GET (mostrar a página) passa direto
 * pro controller, que nunca vê o POST porque este autenticador
 * responde antes dele.
 */
class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PilotRepository $pilots,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $cid = trim((string) $request->request->get('cid', ''));
        $password = (string) $request->request->get('password', '');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $cid);

        return new Passport(
            new UserBadge($cid, function (string $cid) {
                $pilot = $this->pilots->findOneByCid($cid);
                if (null === $pilot) {
                    // Mensagem genérica de propósito (não revela se o CID
                    // existe ou não) - o LoginController traduz isso pra
                    // texto amigável na tela, não usa esta mensagem direto.
                    throw new CustomUserMessageAuthenticationException('CID ou senha inválidos.');
                }

                return $pilot;
            }),
            new PasswordCredentials($password)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        /** @var \App\Entity\Pilot $pilot */
        $pilot = $token->getUser();

        // Shim de transição: as outras telas da área logada (Portal, Voo,
        // Agendamentos etc.) ainda leem `session->get('pilot')` como um
        // array simples, não sabem nada de Security - ver a nota no topo
        // de config/packages/security.yaml. Gravando aqui, no mesmo
        // formato de sempre, todo o resto do site continua funcionando
        // sem precisar ser tocado hoje.
        $request->getSession()->set('pilot', [
            'initials' => $pilot->getInitials(),
            'name' => $pilot->getName(),
            'cid' => $pilot->getCid(),
            'admin' => $pilot->isAdmin(),
            'email' => $pilot->getEmail(),
            'photo' => $pilot->getPhoto(),
        ]);

        return new RedirectResponse($this->urlGenerator->generate('app_portal'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        return new RedirectResponse($this->getLoginUrl($request));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
