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
 * Ferramentas do piloto: página única com 7 calculadoras (vento cruzado/
 * cauda, conversor de unidades/QNH + ETA, peso e balanceamento, distância
 * de decolagem/pouso ajustada, Zulu×hora local das Bases, alcance de
 * planeio engine-out, ponto ideal de descida) — item do backlog do
 * README ("Ideias futuras: Ferramentas do piloto"). Aberta a qualquer
 * piloto logado (não é área de admin), mesmo padrão de `ManuaisController`.
 *
 * Só peso e balanceamento e distância ajustada dependem de dado
 * cadastrado — o perfil de performance por tipo (`App\Entity\TipoAeronave`,
 * cadastrado em `/tipos-aeronave`). Aqui só embutimos a frota
 * (`aeronaves`, pra ligar aeronave→tipo) e os tipos já cadastrados
 * (`tiposAeronave`) como JSON pro cliente; toda a lógica das duas
 * calculadoras é client-side em `ferramentas.js`. Um tipo sem perfil
 * cadastrado (ou com campos em branco) simplesmente mostra um aviso
 * nessas duas calculadoras, ver `ferramentas.js`. As outras cinco
 * (vento cruzado, conversor/QNH/ETA, Zulu×Bases, planeio, TOD) são só
 * matemática client-side, sem nenhum dado do backend.
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
