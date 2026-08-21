<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Agendamento de voo: reservar uma aeronave da frota pra um voo que ainda
 * vai acontecer (diferente de "Novo voo", que registra um voo que já
 * aconteceu). A ideia central desta tela é permitir agendar *mais de uma*
 * perna seguida pra mesma aeronave — se a próxima perna começa onde a
 * anterior termina, elas formam uma sequência (agendamento.js desenha isso
 * como uma linha do tempo por aeronave, não como uma tabela solta).
 *
 * É tudo mock, igual a Solicitações: `agendamentos()` só monta o estado
 * inicial (injetado no template via JSON) e agendamento.js manipula um
 * array em memória a partir daí (criar novo agendamento) — não persiste
 * entre reloads. Quando existir schema de banco, isso vira uma tabela de
 * verdade e ganha o papel descrito no README (ligação com o ACARS: ao
 * chegar um evento de início de sessão real pra uma aeronave com
 * agendamento em aberto cuja janela bate com o horário, o agendamento é
 * promovido pra "em andamento"/"concluído" em vez de nascer um voo novo
 * solto no Logbook).
 */
class AgendamentoController extends AbstractController
{
    #[Route('/agendamentos', name: 'app_agendamentos', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('agendamento/index.html.twig', [
            'activeView' => 'agendamentos',
            'pilot' => $pilot,
            'fleet' => $this->fleet(),
            'agendamentos' => $this->agendamentos(),
            'airportsUrl' => '/assets/data/airports.json',
            'nowIso' => '2026-08-19T12:00:00Z',
        ]);
    }

    /**
     * Mesma frota mock do Portal/Mapa ao vivo, com o campo `pos` (posição
     * atual) que decide o ponto de partida sugerido do primeiro
     * agendamento de cada aeronave.
     *
     * @return list<array{reg: string, tipo: string, base: string, pos: string, status: string, statusTag: string}>
     */
    private function fleet(): array
    {
        return [
            ['reg' => 'CC-KBA', 'tipo' => 'DHC-6 Twin Otter 300', 'base' => 'SCCI', 'pos' => 'SCNT', 'status' => 'Em voo', 'statusTag' => 'warn'],
            ['reg' => 'CC-KBC', 'tipo' => 'Cessna 208B Grand Caravan', 'base' => 'SCCI', 'pos' => 'SCCI', 'status' => 'Disponível', 'statusTag' => 'ok'],
            ['reg' => 'CC-KBD', 'tipo' => 'Pilatus PC-6 Porter', 'base' => 'SCCI', 'pos' => 'SCBA', 'status' => 'Fora de base', 'statusTag' => 'bad'],
            ['reg' => 'N208KB', 'tipo' => 'Cessna 208B Grand Caravan', 'base' => 'PAFA', 'pos' => 'PABT', 'status' => 'Em voo', 'statusTag' => 'warn'],
            ['reg' => 'N412KB', 'tipo' => 'DHC-2 Beaver', 'base' => 'PAFA', 'pos' => 'PAFA', 'status' => 'Disponível', 'statusTag' => 'ok'],
            ['reg' => 'N67KB', 'tipo' => 'Beechcraft King Air 350', 'base' => 'PAFA', 'pos' => 'PAFA', 'status' => 'Disponível', 'statusTag' => 'ok'],
        ];
    }

    /**
     * Agendamentos futuros (mock), a partir de `nowIso` (2026-08-19
     * 12:00Z). CC-KBC e N412KB têm cada uma uma sequência de pernas
     * encadeadas (destino de uma = origem da próxima) pra demonstrar a
     * visualização em linha do tempo; N67KB tem uma única perna cuja
     * origem *não* bate com a posição atual da aeronave (PASC vs PAFA) só
     * pra mostrar o aviso correspondente; CC-KBD, CC-KBA e N208KB ficam
     * sem nada agendado (estado vazio).
     *
     * @return list<array{id: int, reg: string, origem: string, destino: string, tipo: string, piloto: string, de: string, ate: string, notas: ?string}>
     */
    private function agendamentos(): array
    {
        return [
            ['id' => 1, 'reg' => 'CC-KBC', 'origem' => 'SCCI', 'destino' => 'SCNT', 'tipo' => 'Pesquisa', 'piloto' => 'Ana Beltrão', 'de' => '2026-08-19T22:00:00Z', 'ate' => '2026-08-19T23:15:00Z', 'notas' => null],
            ['id' => 2, 'reg' => 'CC-KBC', 'origem' => 'SCNT', 'destino' => 'SCBA', 'tipo' => 'Pesquisa', 'piloto' => 'Ana Beltrão', 'de' => '2026-08-20T09:00:00Z', 'ate' => '2026-08-20T11:40:00Z', 'notas' => 'Equipe de pesquisa embarca em SCNT.'],
            ['id' => 3, 'reg' => 'CC-KBC', 'origem' => 'SCBA', 'destino' => 'SCCI', 'tipo' => 'Reposicionamento', 'piloto' => 'Ana Beltrão', 'de' => '2026-08-20T15:30:00Z', 'ate' => '2026-08-20T18:10:00Z', 'notas' => null],
            ['id' => 4, 'reg' => 'N412KB', 'origem' => 'PAFA', 'destino' => 'PFYU', 'tipo' => 'Carga', 'piloto' => 'Diego Ferraz', 'de' => '2026-08-19T23:30:00Z', 'ate' => '2026-08-20T00:40:00Z', 'notas' => null],
            ['id' => 5, 'reg' => 'N412KB', 'origem' => 'PFYU', 'destino' => 'PABT', 'tipo' => 'Carga', 'piloto' => 'Diego Ferraz', 'de' => '2026-08-20T08:00:00Z', 'ate' => '2026-08-20T09:10:00Z', 'notas' => null],
            ['id' => 6, 'reg' => 'N67KB', 'origem' => 'PASC', 'destino' => 'PAFA', 'tipo' => 'Pessoal', 'piloto' => 'Camila Souza', 'de' => '2026-08-20T06:00:00Z', 'ate' => '2026-08-20T08:00:00Z', 'notas' => 'Confirmar reposicionamento — aeronave está em PAFA, não em PASC.'],
        ];
    }
}
