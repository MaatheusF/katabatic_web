<?php

namespace App\Controller;

use App\Entity\MembershipRequest;
use App\Repository\MembershipRequestRepository;
use App\Repository\PilotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Solicitação de adesão: tela pública (sem sessão) onde quem quer virar
 * piloto da Katabatic se candidata.
 *
 * "Enviar solicitação" agora é um POST de verdade (`adesao.js` faz um
 * `fetch` em JSON pra `submit()` abaixo em vez de só trocar de card na
 * tela) — grava uma linha em `membership_request`, e o grid de
 * Solicitações da área logada (ver SolicitacoesController) passou a
 * consultar essa mesma tabela. Antes desta fatia os dois lados eram
 * conjuntos mock independentes que não se falavam (ver git log /
 * README).
 */
class AdesaoController extends AbstractController
{
    /** Mesmos valores dos chips do formulário (ver templates/adesao/index.html.twig). */
    private const VALID_EXPERIENCE = ['Iniciante', 'Intermediário', 'Experiente'];

    /**
     * Mesma lista de bases que `AeroportoRepository::BASES`/
     * `NovaAeronaveController::BASES_VALIDAS`/`AeroportoController::BASES_VALIDAS`
     * (repetida, não importada — ver docblock de `AeroportoRepository::BASES`),
     * mais 'Sem preferência' (só existe aqui, um piloto ainda não tem
     * aeronave pra precisar de base de verdade). **Atualizado: bases
     * sazonais** — SLLP/VNKT/WAJW/VQPR entraram como preferência
     * selecionável, mesma decisão de `NovaAeronaveController` — ver
     * README "Bases sazonais" (a base do Nepal é Catmandu/VNKT, não
     * Lukla — Lukla é destino, não hub).
     */
    private const VALID_BASE_PREF = ['PAFA', 'SCCI', 'SLLP', 'VNKT', 'WAJW', 'VQPR', 'Sem preferência'];

    #[Route('/adesao', name: 'app_adesao', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('adesao/index.html.twig');
    }

    #[Route('/adesao', name: 'app_adesao_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        EntityManagerInterface $em,
        MembershipRequestRepository $requests,
        PilotRepository $pilots,
        CsrfTokenManagerInterface $csrf,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        // CSRF: tela pública sem sessão de piloto, então isto é a única
        // defesa contra um site de terceiros disparando este POST sem o
        // usuário ter aberto /adesao de verdade - token gerado por
        // `csrf_token('adesao')` no template, devolvido por adesao.js no
        // próprio corpo JSON (não dá pra usar cabeçalho custom com um
        // form comum, mas isto já é um fetch com JSON, então cabe igual
        // no payload).
        if (!$csrf->isTokenValid(new CsrfToken('adesao', (string) ($data['_csrf_token'] ?? '')))) {
            return $this->json(['errors' => ['Sessão expirada — recarregue a página e tente de novo.']], 419);
        }

        $name = trim((string) ($data['nome'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $cid = trim((string) ($data['cid'] ?? ''));
        $discord = trim((string) ($data['discord'] ?? ''));
        $discord = '' !== $discord ? $discord : null;
        $experience = (string) ($data['experiencia'] ?? '');
        $basePref = (string) ($data['basePref'] ?? '');
        $heardAbout = trim((string) ($data['comoConheceu'] ?? ''));
        $heardAbout = '' !== $heardAbout ? $heardAbout : null;
        $motivation = trim((string) ($data['motivacao'] ?? ''));

        $errors = [];
        if ('' === $name) {
            $errors[] = 'Informe seu nome completo.';
        }
        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail válido.';
        }
        if (!preg_match('/^\d{6,8}$/', $cid)) {
            $errors[] = 'Informe um CID VATSIM válido (só números).';
        }
        if ('' === $motivation) {
            $errors[] = 'Conte um pouco sobre por que quer voar na Katabatic.';
        }
        if (!in_array($experience, self::VALID_EXPERIENCE, true)) {
            $errors[] = 'Selecione uma experiência válida.';
        }
        if (!in_array($basePref, self::VALID_BASE_PREF, true)) {
            $errors[] = 'Selecione uma base preferida válida.';
        }

        // Só confere duplicidade se o CID em si já é válido — evita um
        // segundo erro confuso ("CID já em uso") em cima de um CID que
        // nem passou no formato.
        if ([] === $errors) {
            if (null !== $pilots->findOneByCid($cid)) {
                $errors[] = 'Esse CID já pertence a um piloto da Katabatic.';
            } elseif ($requests->hasPendingForCid($cid)) {
                $errors[] = 'Já existe uma solicitação pendente pra esse CID — aguarde a resposta por e-mail.';
            }
        }

        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        $membershipRequest = new MembershipRequest(
            $name,
            $email,
            $cid,
            $experience,
            $basePref,
            $discord,
            $heardAbout,
            $motivation,
        );
        $em->persist($membershipRequest);
        $em->flush();

        return $this->json([
            'id' => $membershipRequest->getId(),
            // Código só pra dar um retorno concreto na tela de confirmação —
            // mesmo formato do ID fictício que existia na versão mock,
            // agora rastreável de verdade até a linha no banco.
            'code' => sprintf('KADE-%04d', $membershipRequest->getId()),
        ], 201);
    }
}
