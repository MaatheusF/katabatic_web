<?php

namespace App\Controller;

use App\Repository\PilotRepository;
use App\Repository\VooRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Relatório de voo. A página em si continua quase toda renderizada no
 * cliente (voo.js lê a telemetria e desenha cabeçalho, fases, mapa,
 * gráficos sincronizados, pouso, composição do índice e eventos) — o
 * que mudou nesta fatia é de onde essa telemetria vem.
 *
 * Antes: `public/assets/data/flights.json`, um arquivo estático
 * público (qualquer um com a URL via qualquer pilota). Agora:
 * `telemetria()` abaixo, que devolve exatamente o mesmo formato de
 * array (voo.js não mudou uma linha), só que consultado do banco e
 * filtrado pelo piloto logado — ver `App\Entity\Voo` e
 * `VooRepository::findComTelemetriaForPilot()`.
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
            'flightsUrl' => $this->generateUrl('app_voo_telemetria'),
        ]);
    }

    #[Route('/voo/telemetria', name: 'app_voo_telemetria', methods: ['GET'])]
    public function telemetria(Request $request, PilotRepository $pilots, VooRepository $voos): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json([], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json([]);
        }

        $flights = array_map(
            function ($voo) {
                $telemetria = $voo->getTelemetria();
                // pilot_report é editável de verdade agora (ver relato()
                // abaixo) - nunca vem gravado dentro do blob de telemetria
                // em si (esse é só o que o ACARS mandou), então é sempre
                // sobrescrito aqui com o valor atual da coluna.
                $telemetria['pilot_report'] = $voo->getPilotReport();

                return $telemetria;
            },
            $voos->findComTelemetriaForPilot($pilot)
        );

        return $this->json($flights);
    }

    #[Route('/voo/{codigo}/relato', name: 'app_voo_relato', methods: ['POST'])]
    public function relato(string $codigo, Request $request, EntityManagerInterface $em, PilotRepository $pilots, VooRepository $voos): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->json(['error' => 'Piloto não encontrado.'], 404);
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->json(['error' => 'Voo não encontrado.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $relato = trim((string) ($data['relato'] ?? ''));

        $voo->setPilotReport('' !== $relato ? $relato : null);
        $em->flush();

        return $this->json(['pilot_report' => $voo->getPilotReport()]);
    }
}
