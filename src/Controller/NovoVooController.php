<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Repository\AeronaveRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registro de voo: importar telemetria (dropzone simula leitura das
 * pastas do script de captura) ou registro manual. Publicar/Salvar
 * rascunho ainda sao mock (alert no JS) - o Logbook (`App\Entity\Voo`,
 * ver README "Backend: voos e telemetria") ja e tabela de verdade, mas
 * esta tela ainda nao grava nela; falta o POST de criacao.
 *
 * **Atualizado (backend real):** a frota do seletor de aeronave agora
 * vem de `App\Entity\Aeronave` (ver "Backend: mapa ao vivo e histórico
 * da frota") em vez de um array mock próprio.
 */
class NovoVooController extends AbstractController
{
    #[Route('/novo-voo', name: 'app_novo_voo', methods: ['GET'])]
    public function index(Request $request, AeronaveRepository $aeronaves): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('novo_voo/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $pilot,
            'aircraft' => array_map(
                fn (Aeronave $a) => $this->aircraftViewModel($a),
                $aeronaves->findAllOrderedByBaseAndReg()
            ),
        ]);
    }

    /**
     * @return array{reg: string, tipo: string, base: string, pos: string, status: string, dot: string}
     */
    private function aircraftViewModel(Aeronave $a): array
    {
        $dot = match ($a->getStatusTag()) {
            'warn' => 'var(--accent)',
            'bad' => 'var(--danger)',
            default => 'var(--ok)',
        };

        return [
            'reg' => $a->getReg(),
            'tipo' => $a->getTipo(),
            'base' => $a->getBase(),
            'pos' => $a->getPosIcao(),
            'status' => $a->getStatusEfetivo(),
            'dot' => $dot,
        ];
    }
}
