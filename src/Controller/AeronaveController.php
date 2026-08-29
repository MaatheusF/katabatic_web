<?php

namespace App\Controller;

use App\Entity\Voo;
use App\Repository\AeronaveRepository;
use App\Repository\TipoAeronaveRepository;
use App\Repository\VooRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Histórico de aeronave: todas as pernas voadas por uma matrícula da
 * frota, plotadas num mapa (traçado + origem/destino de cada perna) e
 * listadas ao lado. Acessada a partir do card/linha da aeronave na
 * view Frota do Portal (ver PortalController::fleetViewModel()).
 *
 * **Atualizado (backend real):** a aeronave vem de
 * `App\Entity\Aeronave` (404 se a matrícula não existir na frota) e as
 * pernas vêm de `VooRepository::findAllByAeronaveReg()` — todo voo já
 * registrado com essa matrícula, de qualquer piloto, mais recente
 * primeiro. Antes desta fatia, esta tela gerava um histórico sintético
 * de ~17 pernas por matrícula (ver git log) só pra ter volume
 * suficiente pra demonstrar filtro de período; agora mostra o volume
 * real do Logbook, que hoje é pequeno (1-2 pernas por aeronave) porque
 * só existem 11 voos gravados no total — cresce conforme o Logbook
 * cresce, sem precisar gerar nada artificialmente.
 *
 * **Atualizado: trajeto real no mapa, não uma linha reta/curva entre
 * aeroportos.** `legViewModel()` manda o `track` gravado de verdade
 * (`Voo::getTelemetria()['track']`, decimado — ver `trackPoints()`)
 * pra toda perna que tem telemetria ACARS; `aeronave.js` desenha
 * exatamente essas coordenadas em vez de uma curva estimada entre
 * `origem`/`destino`. Perna sem telemetria (histórico só narrativo,
 * ver `Voo::hasTelemetria()`) continua sem trajeto real pra mostrar —
 * cai no fallback antigo (curva estimada entre os dois aeroportos,
 * só quando ambos existem em `airports.json`).
 *
 * **Atualizado: resolução completa sob demanda.** O `track` acima vem
 * sempre decimado (`TRACK_MAX_PONTOS`) pro carregamento inicial da
 * tela não pesar com um histórico grande. `trajetosCompletos()` abaixo
 * é um endpoint à parte — `GET /aeronave/{reg}/trajetos-completos` —
 * que devolve o `track` **sem decimar** de toda perna com telemetria
 * da aeronave; `aeronave.js` só chama isso quando o piloto desliga o
 * toggle "Mostrar todas as posições" (opt-in, com estado de
 * carregamento — ver docblock de `trajetosCompletos()` pra por quê
 * não vem tudo de cara).
 *
 * **CARTO API key.** Igual a `MapaAoVivoController`/`VooController`:
 * injeta `CARTO_API_KEY` via `#[Autowire]` e manda pro template como
 * `cartoApiKey`, que expõe `window.KATABATIC_CARTO_API_KEY` pro
 * `aeronave.js` montar a URL do tile com `?key=...` — sem isso essa
 * tela (a única das três que ainda não tinha sido corrigida) continuava
 * mostrando a marca d'água "API KEY REQUIRED" por cima do mapa (ver
 * README, "Mapa base (CARTO)").
 */
class AeronaveController extends AbstractController
{
    #[Route('/aeronave/{reg}', name: 'app_aeronave', methods: ['GET'])]
    public function index(
        Request $request,
        string $reg,
        AeronaveRepository $aeronaves,
        VooRepository $voos,
        TipoAeronaveRepository $tipos,
        #[Autowire('%env(CARTO_API_KEY)%')] string $cartoApiKey,
    ): Response {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        $aircraft = $aeronaves->findOneByReg($reg);
        if (null === $aircraft) {
            throw $this->createNotFoundException('Aeronave não encontrada na frota.');
        }

        return $this->render('aeronave/index.html.twig', [
            'activeView' => 'frota',
            'pilot' => $pilot,
            'aircraft' => [
                'reg' => $aircraft->getReg(),
                'tipo' => $aircraft->getTipo(),
                // Casamento fraco por string com TipoAeronave::$nome, mesmo
                // padrao de PortalController::fleetViewModel() - null se o
                // tipo ainda nao tem perfil cadastrado em /tipos-aeronave.
                'categoria' => $tipos->findOneByNome($aircraft->getTipo())?->getCategoria(),
                'base' => $aircraft->getBase(),
                'status' => $aircraft->getStatusEfetivo(),
                'statusTag' => $aircraft->getStatusTag(),
                'horas' => $aircraft->getHoras(),
            ],
            'legs' => array_map(fn (Voo $v) => $this->legViewModel($v), $voos->findAllByAeronaveReg($reg)),
            'airportsUrl' => $this->generateUrl('app_aeroportos_catalogo'),
            'trajetosCompletosUrl' => $this->generateUrl('app_aeronave_trajetos_completos', ['reg' => $reg]),
            'nowIso' => '2026-08-19T12:00:00Z',
            'cartoApiKey' => $cartoApiKey,
        ]);
    }

    /**
     * `track` sem decimar de toda perna com telemetria da aeronave —
     * ver docblock da classe. Endpoint à parte (não vem junto de
     * `index()`) de propósito: um histórico grande, sem decimação,
     * pode ser um payload considerável (voo de 1h+ a 1 Hz = milhares de
     * pontos, multiplicado por dezenas de pernas) — só vale pagar esse
     * custo quando o piloto realmente pede resolução completa (toggle
     * "Mostrar todas as posições" em `aeronave.js`), não em todo
     * carregamento da tela.
     *
     * Devolve um objeto `{ codigo: [[lat, lon], ...] }` — uma chave por
     * voo com telemetria, não filtrado por período/tipo/acidentado
     * (esse filtro já é feito no cliente, em cima do mesmo `LEGS` que
     * `index()` mandou; `aeronave.js` só troca qual `track` usa pra
     * cada perna já filtrada, não refaz a filtragem aqui).
     */
    #[Route('/aeronave/{reg}/trajetos-completos', name: 'app_aeronave_trajetos_completos', methods: ['GET'])]
    public function trajetosCompletos(Request $request, string $reg, AeronaveRepository $aeronaves, VooRepository $voos): JsonResponse
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->json([], 401);
        }

        $aircraft = $aeronaves->findOneByReg($reg);
        if (null === $aircraft) {
            return $this->json(['error' => 'Aeronave não encontrada na frota.'], 404);
        }

        $out = [];
        foreach ($voos->findAllByAeronaveReg($reg) as $v) {
            $codigo = $v->getCodigo();
            if (null === $codigo) {
                continue;
            }
            $pontos = $this->trackPoints($v, null);
            if (null !== $pontos) {
                $out[$codigo] = $pontos;
            }
        }

        return $this->json($out);
    }

    /**
     * @return array{data: string, hora: string, callsign: string, tipo: string, origem: string, destino: string, destinoReal: ?string, tempo: string, tempoMin: int, dif: int, flightId: ?string, acidentado: bool, track: ?list<array{0: float, 1: float}>}
     */
    private function legViewModel(Voo $v): array
    {
        $tempoMin = $v->getTempoMin();
        $tempo = sprintf('%d:%02d', intdiv($tempoMin, 60), $tempoMin % 60);

        return [
            'data' => $v->getStartedAt()->format('Y-m-d'),
            'hora' => $v->getStartedAt()->format('H:i').'Z',
            'callsign' => $v->getCallsign(),
            'tipo' => $v->getTipoOperacao(),
            'origem' => $v->getOrigem(),
            'destino' => $v->getDestino(),
            // Ver App\Entity\Voo::$destinoReal - null na grande maioria
            // (pousou onde o plano dizia); preenchido só quando
            // AcarsIngestaoController::ingerir() detectou pouso
            // alternativo. aeronave.js mostra um selo quando presente.
            'destinoReal' => $v->getDestinoReal(),
            'tempo' => $tempo,
            'tempoMin' => $tempoMin,
            'dif' => $v->getDificuldade(),
            'flightId' => $v->getCodigo(),
            // Ver App\Entity\Voo::$status - aeronave.js decide, com o
            // filtro "Mostrar acidentadas", se essa perna aparece na
            // lista/mapa (ver README, "Marcar voo como acidentado").
            'acidentado' => $v->isAcidentado(),
            'track' => $this->trackPoints($v),
        ];
    }

    private const TRACK_MAX_PONTOS = 180;

    /**
     * Trajeto real gravado pelo ACARS — [lat, lon] por amostra, sem os
     * outros campos (tempo, altitude, etc.) que
     * `Voo::getTelemetria()['track']` carrega e essa tela não usa.
     * `null` quando o voo não tem telemetria (histórico só narrativo,
     * ver `Voo::hasTelemetria()`) — `aeronave.js` cai no fallback de
     * curva estimada entre `origem`/`destino` nesse caso.
     *
     * Decima pra no máximo `$max` pontos por perna (amostra 1 Hz real
     * vira ~180 pontos mesmo num voo de 1h+) — sem isso, um histórico
     * com muitos voos longos manda um payload enorme só pra desenhar
     * uma linha que visualmente não precisa de 1 ponto por segundo.
     * Sempre inclui a última amostra mesmo quando ela não cai
     * exatamente no passo da decimação, senão o traçado "encolhe" antes
     * do pouso de verdade. `$max = null` desliga a decimação
     * inteiramente — usado só por `trajetosCompletos()`, ver docblock
     * de lá pra por quê isso é opt-in e não o padrão.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    private function trackPoints(Voo $v, ?int $max = self::TRACK_MAX_PONTOS): ?array
    {
        if (!$v->hasTelemetria()) {
            return null;
        }

        $track = $v->getTelemetria()['track'] ?? null;
        if (!is_array($track) || count($track) < 2) {
            return null;
        }

        $total = count($track);
        $step = null === $max ? 1 : max(1, (int) ceil($total / $max));

        $pontos = [];
        foreach ($track as $i => $amostra) {
            if (null !== $max && 0 !== $i % $step && $i !== $total - 1) {
                continue;
            }
            if (!isset($amostra[1], $amostra[2]) || !is_numeric($amostra[1]) || !is_numeric($amostra[2])) {
                continue;
            }
            // 5 casas decimais (~1,1 m de precisão) - sobra pro mapa, não
            // vale o payload extra da precisão de float completa.
            $pontos[] = [round((float) $amostra[1], 5), round((float) $amostra[2], 5)];
        }

        return $pontos ?: null;
    }
}
