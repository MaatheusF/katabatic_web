<?php

namespace App\Controller;

use App\Entity\MembershipRequest;
use App\Entity\Pilot;
use App\Repository\MembershipRequestRepository;
use App\Repository\PilotRepository;
use App\Repository\VooRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Grid de administração: solicitações de adesão pendentes/aprovadas/
 * rejeitadas, e a lista de pilotos já cadastrados. Restrito a pilotos
 * com `admin` true na sessão (mesmo shim de transição do resto do
 * site — ver LoginFormAuthenticator e config/packages/security.yaml).
 *
 * Aprovar/Rejeitar agora é de verdade: `solicitacoes.js` faz um
 * `fetch` POST pra `aprovar()`/`rejeitar()` abaixo em vez de só mudar
 * o array em memória da página. Aprovar cria um `Pilot` de verdade
 * (com senha temporária gerada na hora — ver `aprovar()`) quando ainda
 * não existe um piloto com aquele CID; rejeitar só marca o pedido.
 * Antes desta fatia, os dois lados (formulário público em `/adesao` e
 * este grid) usavam conjuntos mock independentes — ver README.
 */
class SolicitacoesController extends AbstractController
{
    #[Route('/solicitacoes', name: 'app_solicitacoes', methods: ['GET'])]
    public function index(Request $request, MembershipRequestRepository $requests, PilotRepository $pilots, VooRepository $voos): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }
        if (empty($pilot['admin'])) {
            return $this->redirectToRoute('app_portal');
        }

        // Uma consulta agregada pra todos os pilotos de uma vez (ver
        // VooRepository::countsByPilot()) em vez de um COUNT por linha
        // do grid.
        $voosCounts = $voos->countsByPilot();

        return $this->render('solicitacoes/index.html.twig', [
            'activeView' => $request->query->get('view', 'solicitacoes'),
            'pilot' => $pilot,
            'solicitacoes' => array_map(
                fn (MembershipRequest $r) => $this->requestViewModel($r),
                $requests->findAllOrderedByRequestDate()
            ),
            'pilotos' => array_map(
                fn (Pilot $p) => $this->pilotViewModel($p, $voosCounts[$p->getId()] ?? 0),
                $pilots->findBy([], ['createdAt' => 'ASC'])
            ),
        ]);
    }

    #[Route('/solicitacoes/{id}/aprovar', name: 'app_solicitacoes_aprovar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function aprovar(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        MembershipRequestRepository $requests,
        PilotRepository $pilots,
        VooRepository $voos,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        if (null !== $err = $this->ensureAdmin($request)) {
            return $err;
        }

        $membershipRequest = $requests->find($id);
        if (null === $membershipRequest) {
            return $this->json(['error' => 'Solicitação não encontrada.'], 404);
        }
        if ('pendente' !== $membershipRequest->getStatus()) {
            return $this->json(['error' => 'Essa solicitação já foi decidida.'], 409);
        }

        // Se já existe um piloto com esse CID (ex.: pedido duplicado que
        // passou pela validação antes de outro já ter sido aprovado), não
        // cria um segundo — só liga o pedido ao piloto que já existe, sem
        // gerar senha nova nem sobrescrever a dele.
        $existingPilot = $pilots->findOneByCid($membershipRequest->getCid());
        $tempPassword = null;
        if (null === $existingPilot) {
            $tempPassword = bin2hex(random_bytes(4));

            $newPilot = new Pilot(
                $membershipRequest->getCid(),
                $membershipRequest->getName(),
                $membershipRequest->getEmail(),
            );
            $newPilot->setBase(
                'Sem preferência' === $membershipRequest->getBasePref() ? 'PAFA' : $membershipRequest->getBasePref()
            );
            $newPilot->setPassword($hasher->hashPassword($newPilot, $tempPassword));

            $em->persist($newPilot);
            $existingPilot = $newPilot;
        }

        $membershipRequest->setStatus('aprovado');
        $membershipRequest->setDecidedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->json([
            'solicitacao' => $this->requestViewModel($membershipRequest),
            'piloto' => $this->pilotViewModel($existingPilot, $voos->countForPilot($existingPilot)),
            // null quando o piloto já existia (nada foi gerado) — o front
            // só mostra o aviso de senha temporária quando isto vem preenchido.
            'senhaTemporaria' => $tempPassword,
        ]);
    }

    #[Route('/solicitacoes/{id}/rejeitar', name: 'app_solicitacoes_rejeitar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rejeitar(int $id, Request $request, EntityManagerInterface $em, MembershipRequestRepository $requests): JsonResponse
    {
        if (null !== $err = $this->ensureAdmin($request)) {
            return $err;
        }

        $membershipRequest = $requests->find($id);
        if (null === $membershipRequest) {
            return $this->json(['error' => 'Solicitação não encontrada.'], 404);
        }
        if ('pendente' !== $membershipRequest->getStatus()) {
            return $this->json(['error' => 'Essa solicitação já foi decidida.'], 409);
        }

        $membershipRequest->setStatus('rejeitado');
        $membershipRequest->setDecidedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->json(['solicitacao' => $this->requestViewModel($membershipRequest)]);
    }

    /**
     * Mesmo guard de `index()` (sessão + papel admin), mas devolvendo
     * JSON em vez de redirecionar — estas duas rotas são chamadas via
     * `fetch` por `solicitacoes.js`, não navegação de página.
     */
    private function ensureAdmin(Request $request): ?JsonResponse
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }
        if (empty($pilot['admin'])) {
            return $this->json(['error' => 'Ação restrita a administradores.'], 403);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestViewModel(MembershipRequest $r): array
    {
        return [
            'id' => $r->getId(),
            'nome' => $r->getName(),
            'email' => $r->getEmail(),
            'discord' => $r->getDiscord(),
            'cid' => $r->getCid(),
            'experiencia' => $r->getExperience(),
            'basePref' => $r->getBasePref(),
            'comoConheceu' => $r->getHeardAbout(),
            'motivacao' => $r->getMotivation(),
            'data' => $r->getCreatedAt()->format('Y-m-d'),
            'status' => $r->getStatus(),
        ];
    }

    /**
     * `$voosCount` vem de fora (`VooRepository::countsByPilot()` no grid
     * inteiro, `countForPilot()` num piloto só em `aprovar()`) em vez de
     * ser calculado aqui dentro — evita esta função disparar uma query
     * por piloto sozinha.
     *
     * @return array<string, mixed>
     */
    private function pilotViewModel(Pilot $p, int $voosCount): array
    {
        return [
            'nome' => $p->getName(),
            'cid' => $p->getCid(),
            'base' => $p->getBase(),
            'papel' => $p->isAdmin() ? 'admin' : 'piloto',
            'dataAdesao' => $p->getCreatedAt()->format('Y-m-d'),
            'voos' => $voosCount,
            'status' => $p->isActive() ? 'ativo' : 'inativo',
        ];
    }
}
