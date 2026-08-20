<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Historico de aeronave: todas as pernas voadas por uma matricula da
 * frota num periodo, plotadas num mapa (traçado + origem/destino de
 * cada perna) e listadas ao lado. Acessada a partir do card/linha da
 * aeronave na view Frota do Portal (ver PortalController::fleet).
 *
 * A frota aqui e a mesma do Portal/Novo voo (mock, mesmos 6 registros)
 * - so reformatada com o essencial pro cabecalho desta tela. O
 * historico de pernas e mock tambem (ver aircraftLegs()): o Logbook de
 * verdade so tem 6 linhas hoje, poucas pra mostrar uma tela de "todas
 * as pernas de uma aeronave" de forma convincente, entao esta tela usa
 * um historico mais longo (varios meses) gerado deterministicamente
 * aqui. Quando o Logbook virar tabela de verdade, isso vira uma
 * consulta filtrada por matricula.
 */
class AeronaveController extends AbstractController
{
    #[Route('/aeronave/{reg}', name: 'app_aeronave', methods: ['GET'])]
    public function index(Request $request, string $reg): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        $fleet = $this->fleet();
        $aircraft = null;
        foreach ($fleet as $a) {
            if ($a['reg'] === $reg) {
                $aircraft = $a;
                break;
            }
        }
        if (null === $aircraft) {
            throw $this->createNotFoundException('Aeronave não encontrada na frota.');
        }

        return $this->render('aeronave/index.html.twig', [
            'activeView' => 'frota',
            'pilot' => $pilot,
            'aircraft' => $aircraft,
            'legs' => $this->aircraftLegs($reg),
            'airportsUrl' => '/assets/data/airports.json',
            'nowIso' => '2026-08-19T12:00:00Z',
        ]);
    }

    /**
     * Mesma frota mock do Portal (PortalController::fleet), so com os
     * campos que o cabecalho desta tela usa.
     *
     * @return list<array{reg: string, tipo: string, base: string, status: string, statusTag: string, horas: int}>
     */
    private function fleet(): array
    {
        return [
            ['reg' => 'CC-KBA', 'tipo' => 'DHC-6 Twin Otter 300', 'base' => 'SCCI', 'status' => 'Em voo', 'statusTag' => 'warn', 'horas' => 318],
            ['reg' => 'CC-KBC', 'tipo' => 'Cessna 208B Grand Caravan', 'base' => 'SCCI', 'status' => 'Disponível', 'statusTag' => 'ok', 'horas' => 204],
            ['reg' => 'CC-KBD', 'tipo' => 'Pilatus PC-6 Porter', 'base' => 'SCCI', 'status' => 'Fora de base', 'statusTag' => 'bad', 'horas' => 96],
            ['reg' => 'N208KB', 'tipo' => 'Cessna 208B Grand Caravan', 'base' => 'PAFA', 'status' => 'Em voo', 'statusTag' => 'warn', 'horas' => 412],
            ['reg' => 'N412KB', 'tipo' => 'DHC-2 Beaver', 'base' => 'PAFA', 'status' => 'Disponível', 'statusTag' => 'ok', 'horas' => 147],
            ['reg' => 'N67KB', 'tipo' => 'Beechcraft King Air 350', 'base' => 'PAFA', 'status' => 'Disponível', 'statusTag' => 'ok', 'horas' => 89],
        ];
    }

    /**
     * Gera um historico deterministico de pernas para a matricula pedida:
     * alterna entre a base da aeronave e as estacoes da sua rede (norte
     * ou sul), voltando no tempo a partir de 2026-08-19 (mesmo "agora"
     * mock do Portal) em offsets crescentes - recente/semana/mes/
     * trimestre/semestre - pra dar o que filtrar no seletor de periodo.
     *
     * A perna mais recente das 3 matriculas que tem voo de teste real
     * gravado (N208KB, CC-KBA, N67KB - ver PortalController::logbook)
     * repete exatamente callsign/rota/data daquela linha do Logbook e
     * carrega `flightId`, pra abrir /voo?id=... com telemetria de
     * verdade. O traçado no mapa desta tela, porem, sempre usa a linha
     * reta entre as coordenadas ICAO (ver airports.json) mesmo nessas 3
     * pernas - a telemetria real gravada nos testes fica geografiaamente
     * em outro lugar (ver README), plotar o traçado de verdade ali
     * quebraria o enquadramento do mapa com o resto do historico.
     *
     * @return list<array{data: string, hora: string, callsign: string, tipo: string, origem: string, destino: string, tempo: string, tempoMin: int, dif: int, flightId: ?string}>
     */
    private function aircraftLegs(string $reg): array
    {
        $networks = [
            'CC-KBA' => ['base' => 'SCCI', 'estacoes' => ['SCNT', 'SCGZ', 'SCFM', 'SCBA'], 'callsignPrefix' => '4', 'tipo' => 'Pesquisa'],
            'CC-KBC' => ['base' => 'SCCI', 'estacoes' => ['SCFM', 'SCBA', 'SCNT', 'SCGZ'], 'callsignPrefix' => '1', 'tipo' => 'Carga'],
            'CC-KBD' => ['base' => 'SCCI', 'estacoes' => ['SCBA', 'SCFM', 'SCNT'], 'callsignPrefix' => '9', 'tipo' => 'Reposicionamento'],
            'N208KB' => ['base' => 'PAFA', 'estacoes' => ['PABT', 'PFYU', 'PAKP', 'PASC'], 'callsignPrefix' => '1', 'tipo' => 'Carga'],
            'N412KB' => ['base' => 'PAFA', 'estacoes' => ['PFYU', 'PABT', 'PAOT'], 'callsignPrefix' => '2', 'tipo' => 'Pessoal'],
            'N67KB' => ['base' => 'PAFA', 'estacoes' => ['PASC', 'PAOT', 'PAKP', 'PABT'], 'callsignPrefix' => '2', 'tipo' => 'Pessoal'],
        ];
        if (!isset($networks[$reg])) {
            return [];
        }
        $net = $networks[$reg];

        // Ligacoes reais entre 3 matriculas e voos de teste gravados -
        // so a perna mais recente de cada uma usa esses dados exatos.
        $realMatch = [
            'N208KB' => ['callsign' => 'KBT118', 'destino' => 'PABT', 'tempo' => '0:52', 'tempoMin' => 52, 'dif' => 81, 'flightId' => '20260819_033457_KBT118'],
            'CC-KBA' => ['callsign' => 'KBT412', 'destino' => 'SCNT', 'tempo' => '1:04', 'tempoMin' => 64, 'dif' => 74, 'flightId' => '20260819_032837_KBT118'],
            'N67KB' => ['callsign' => 'KBT207', 'destino' => 'PASC', 'tempo' => '1:48', 'tempoMin' => 108, 'dif' => 29, 'flightId' => '20260819_032200_KBT118'],
        ];

        // Offsets em dias a partir de 2026-08-19 - espacamento cresce
        // quanto mais antigo, pra dar cobertura nas faixas de 7/30/90/
        // 180 dias e "todo o periodo" do filtro.
        $offsets = [0, 3, 6, 9, 13, 17, 22, 28, 35, 44, 55, 68, 84, 103, 126, 152, 181];
        $ref = new \DateTimeImmutable('2026-08-19T00:00:00Z');
        $horas = ['08:15Z', '10:40Z', '13:05Z', '15:30Z', '17:50Z', '20:10Z', '03:20Z', '06:00Z'];
        $tempos = [['0:41', 41], ['0:58', 58], ['1:12', 72], ['1:35', 95], ['0:33', 33], ['1:50', 110]];
        $difs = [24, 38, 45, 52, 58, 63, 69, 75, 33, 47];

        $legs = [];
        $estCount = count($net['estacoes']);
        foreach ($offsets as $i => $daysAgo) {
            $data = $ref->modify("-{$daysAgo} days")->format('Y-m-d');
            $numero = $net['callsignPrefix'] . str_pad((string) (($i * 37 + 3) % 90 + 10), 2, '0', STR_PAD_LEFT);
            $callsign = 'KBT' . $numero;
            $origem = $net['base'];
            $destino = $net['estacoes'][$i % $estCount];
            $hora = $horas[$i % count($horas)];
            [$tempo, $tempoMin] = $tempos[$i % count($tempos)];
            $dif = $difs[$i % count($difs)];
            $flightId = null;

            if (0 === $daysAgo && isset($realMatch[$reg])) {
                $m = $realMatch[$reg];
                $callsign = $m['callsign'];
                $destino = $m['destino'];
                $tempo = $m['tempo'];
                $tempoMin = $m['tempoMin'];
                $dif = $m['dif'];
                $flightId = $m['flightId'];
            }

            // Pernas em dias impares "voltam" da estacao pra base, pra o
            // historico nao ficar so saidas - metade das linhas inverte
            // origem/destino (exceto a mais recente, que fica como voo
            // de ida igual ao Logbook, pra bater com a linha real).
            if (0 !== $daysAgo && 1 === $i % 2) {
                [$origem, $destino] = [$destino, $origem];
            }

            $legs[] = [
                'data' => $data,
                'hora' => $hora,
                'callsign' => $callsign,
                'tipo' => $net['tipo'],
                'origem' => $origem,
                'destino' => $destino,
                'tempo' => $tempo,
                'tempoMin' => $tempoMin,
                'dif' => $dif,
                'flightId' => $flightId,
            ];
        }

        return $legs;
    }
}
