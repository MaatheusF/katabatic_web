<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Entity\TipoAeronave;
use App\Repository\AeronaveRepository;
use App\Repository\TipoAeronaveRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ferramentas do piloto: página única com 4 calculadoras (vento cruzado/
 * cauda, conversor de unidades + ETA, peso e balanceamento, distância de
 * decolagem/pouso ajustada) — item do backlog do README ("Ideias
 * futuras: Ferramentas do piloto"). Aberta a qualquer piloto logado
 * (não é área de admin), mesmo padrão de `ManuaisController`.
 *
 * Vento cruzado/cauda e o conversor de unidades/ETA são só matemática —
 * não dependem de nenhum dado cadastrado. Peso e balanceamento e
 * distância ajustada dependem do perfil de performance por tipo
 * (`App\Entity\TipoAeronave`, cadastrado em `/tipos-aeronave`) — aqui
 * só embutimos a frota (`aeronaves`, pra ligar aeronave→tipo) e os
 * tipos já cadastrados (`tiposAeronave`) como JSON pro cliente; toda a
 * lógica das duas calculadoras é client-side em `ferramentas.js`. Um
 * tipo sem perfil cadastrado (ou com campos em branco) simplesmente
 * mostra um aviso nessas duas calculadoras, ver `ferramentas.js`.
 *
 * Ambas as calculadoras que usam `TipoAeronave` são deliberadamente
 * limitadas: peso e balanceamento é só peso total vs. MTOW (sem
 * envelope de CG/momento) e a distância ajustada usa regra de bolso
 * sobre a distância de referência (sem interpolar gráfico do POH) —
 * ver docblock de `TipoAeronave` e os avisos no próprio template.
 */
class FerramentasController extends AbstractController
{
    #[Route('/ferramentas', name: 'app_ferramentas', methods: ['GET'])]
    public function index(Request $request, AeronaveRepository $aeronaves, TipoAeronaveRepository $tipos): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('ferramentas/index.html.twig', [
            'activeView' => 'ferramentas',
            'pilot' => $pilot,
            'aeronaves' => array_map(
                fn (Aeronave $a) => ['reg' => $a->getReg(), 'tipo' => $a->getTipo()],
                $aeronaves->findAllOrderedByBaseAndReg()
            ),
            'tiposAeronave' => array_map(fn (TipoAeronave $t) => $this->tipoViewModel($t), $tipos->findAllOrderedByNome()),
        ]);
    }

    /**
     * @return array{nome: string, pesoVazioLb: ?int, pesoMaxDecolagemLb: ?int, decolagemDistanciaFt: ?int, pousoDistanciaFt: ?int}
     */
    private function tipoViewModel(TipoAeronave $t): array
    {
        return [
            'nome' => $t->getNome(),
            'pesoVazioLb' => $t->getPesoVazioLb(),
            'pesoMaxDecolagemLb' => $t->getPesoMaxDecolagemLb(),
            'decolagemDistanciaFt' => $t->getDecolagemDistanciaFt(),
            'pousoDistanciaFt' => $t->getPousoDistanciaFt(),
        ];
    }
}
