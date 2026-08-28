<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Manuais e Operações: hub de documentação/ajuda da área logada, aberto
 * a qualquer piloto (não é área de admin, ao contrário de Solicitações/
 * Pilotos) - fica no grupo "Operação" do rail, depois de Agendamentos.
 *
 * Ainda só existe um manual de verdade (Fraseologia VATSIM); os outros
 * cards em manuals() são propositalmente "em breve" (ready = false, sem
 * rota própria ainda) - mesmo padrão de roadmap transparente do resto
 * do app, só que como cards desabilitados em vez de checklist no
 * README. Diferente de Logbook/Frota/Agendamentos, esta seção não tem
 * dado mock nenhum: cada manual é conteúdo estático, uma rota +
 * template dedicados (sem `/manuais/{slug}` genérico) - mesmo padrão
 * "uma tela, um controller, um template" do resto do app.
 */
class ManuaisController extends AbstractController
{
    #[Route('/manuais', name: 'app_manuais', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('manuais/index.html.twig', [
            'activeView' => 'manuais',
            'pilot' => $pilot,
            'manuals' => $this->manuals(),
        ]);
    }

    #[Route('/manuais/fraseologia-vatsim', name: 'app_manual_fraseologia', methods: ['GET'])]
    public function fraseologiaVatsim(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('manuais/fraseologia_vatsim.html.twig', [
            'activeView' => 'manuais',
            'pilot' => $pilot,
        ]);
    }

    #[Route('/manuais/ficha-de-voo', name: 'app_manual_ficha_voo', methods: ['GET'])]
    public function fichaDeVoo(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('manuais/ficha_de_voo.html.twig', [
            'activeView' => 'manuais',
            'pilot' => $pilot,
        ]);
    }

    /**
     * `key` identifica o card pra tradução: o template usa
     * `data-i18n="manuais.card.{{ m.key }}.title"` (idem tag/blurb) pra
     * poder traduzir esse texto vindo de PHP (o mecanismo data-i18n só
     * enxerga o que já foi renderizado em HTML, não sabe ler array PHP -
     * a chave amarra o card ao dicionário EN em manuais/index.html.twig).
     *
     * @return list<array<string, mixed>>
     */
    private function manuals(): array
    {
        return [
            ['key' => 'fraseologia', 'route' => 'app_manual_fraseologia', 'title' => 'Fraseologia VATSIM', 'tag' => 'Comunicação', 'blurb' => 'Comunicações padrão UNICOM em inglês, por etapa do voo — do radio check ao pouso.', 'ready' => true],
            ['key' => 'ficha_voo', 'route' => 'app_manual_ficha_voo', 'title' => 'Ficha de voo (kneeboard)', 'tag' => 'Apoio de voo', 'blurb' => 'PDF pra imprimir (3 voos por folha) ou planilha editável, pra anotar dados durante o voo.', 'ready' => true],
            ['key' => 'emergencia', 'route' => null, 'title' => 'Procedimentos de emergência', 'tag' => 'Segurança', 'blurb' => 'Checklists e fraseologia pra pane de motor, pouso forçado e chamadas de Mayday/Pan-Pan.', 'ready' => false],
            ['key' => 'meteorologia', 'route' => null, 'title' => 'Meteorologia polar', 'tag' => 'Meteorologia', 'blurb' => 'Como ler METAR/TAF e reconhecer riscos específicos do Ártico e da Patagônia.', 'ready' => false],
            ['key' => 'pistas', 'route' => null, 'title' => 'Operação em pistas não pavimentadas', 'tag' => 'Técnica de voo', 'blurb' => 'Pouso e decolagem em cascalho, neve compactada e pistas curtas das estações avançadas.', 'ready' => false],
        ];
    }
}
