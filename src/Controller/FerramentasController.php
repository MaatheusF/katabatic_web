<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Entity\TipoAeronave;
use App\Repository\AeronaveRepository;
use App\Repository\TipoAeronaveRepository;
use App\Repository\VooRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ferramentas do piloto: página única com 8 calculadoras (vento cruzado/
 * cauda, conversor de unidades/QNH + ETA, peso e balanceamento, distância
 * de decolagem/pouso ajustada, Zulu×hora local das Bases, alcance de
 * planeio engine-out, ponto ideal de descida, gerador de callsign/número
 * de voo) — item do backlog do README ("Ideias futuras: Ferramentas do
 * piloto"). Aberta a qualquer piloto logado (não é área de admin), mesmo
 * padrão de `ManuaisController`.
 *
 * **Gerador de callsign/número de voo**: pedido em conversa pra sugerir
 * o número a partir do tipo de operação e da aeronave selecionada —
 * confirmado depois, também em conversa, que companhias aéreas de
 * verdade amarram o número à ROTA (mesmo par origem/destino sempre usa o
 * mesmo número, sentido contrário usa o vizinho par/ímpar), não à
 * aeronave específica (o mesmo avião voa vários números por dia). Toda a
 * lógica (hash determinístico da rota + dígito do tipo) é client-side em
 * `ferramentas.js` — a aeronave selecionada aparece só como referência no
 * resultado, não entra no cálculo.
 *
 * **Atualizado: evita sugerir número já usado por OUTRA rota.** Pedido
 * em conversa ("priorizar sempre números não utilizados") — embutimos
 * `voosExistentes` (todo par callsign/origem/destino já gravado, ver
 * `VooRepository::findCallsignsRotasUsados()`) como JSON pro cliente
 * conferir antes de mostrar o número: se a faixa natural da rota já
 * pertence a uma rota diferente, `ferramentas.js` tenta a próxima faixa
 * livre (sem travar nunca — na pior hipótese, com as 4999 faixas do tipo
 * todas ocupadas por rotas diferentes, ainda devolve algum número). Mesma
 * rota reusando seu próprio número não conta como colisão, de propósito.
 *
 * **Atualizado: número com 4 dígitos (era 3).** Pedido em conversa ("o
 * simbrief aceita até 9 caracteres além do KBT, não seria melhor já
 * ampliar?") — 499 faixas virou 4999 por tipo de operação (10x mais),
 * sufixo final (dígito do tipo + número) passa de 4 pra 5 caracteres,
 * ainda com folga grande dentro do limite de 9 do SimBrief. Ver
 * `hashRota()`/`numeroTxt()` em `ferramentas.js`.
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
 *
 * **Helicóptero (`TipoAeronave::$categoria`).** Peso e balanceamento
 * continua igual pra qualquer categoria — peso total vs. MTOW não muda
 * com asa fixa vs. rotativa. Já a distância de decolagem/pouso ajustada é
 * puramente ground roll/balanced field: não faz sentido pra um pouso ou
 * decolagem vertical, então `ferramentas.js` mostra "não aplicável a
 * rotativas" pra esse tipo em vez do aviso genérico de dados faltando.
 */
class FerramentasController extends AbstractController
{
    #[Route('/ferramentas', name: 'app_ferramentas', methods: ['GET'])]
    public function index(Request $request, AeronaveRepository $aeronaves, TipoAeronaveRepository $tipos, VooRepository $voos): Response
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
            'voosExistentes' => $voos->findCallsignsRotasUsados(),
        ]);
    }

    /**
     * @return array{nome: string, categoria: string, pesoVazioLb: ?int, pesoMaxDecolagemLb: ?int, decolagemDistanciaFt: ?int, pousoDistanciaFt: ?int}
     */
    private function tipoViewModel(TipoAeronave $t): array
    {
        return [
            'nome' => $t->getNome(),
            'categoria' => $t->getCategoria(),
            'pesoVazioLb' => $t->getPesoVazioLb(),
            'pesoMaxDecolagemLb' => $t->getPesoMaxDecolagemLb(),
            'decolagemDistanciaFt' => $t->getDecolagemDistanciaFt(),
            'pousoDistanciaFt' => $t->getPousoDistanciaFt(),
        ];
    }
}
