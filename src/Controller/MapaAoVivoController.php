<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Repository\AeronaveRepository;
use App\Repository\PosicaoAoVivoRepository;
use App\Repository\VooRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mapa ao vivo (tela cheia): posição de toda a frota agora - aeronaves em
 * voo (marcador se movendo na rota) e aeronaves em solo (base/estação
 * atual). Pensada como o "centro de operações" de relance, sem entrar no
 * detalhe de uma perna especifica (isso e o /voo e o /aeronave/{reg}).
 *
 * **Atualizado (backend real):** a frota (quem está "Em voo" vs. parada,
 * base, posição) vem de `App\Entity\Aeronave` em vez de dois arrays
 * mock.
 *
 * **Atualizado (ACARS fase 3 — posição em tempo real, ver README
 * "Backend: posição em tempo real (ACARS fase 3)"):** pra cada aeronave
 * "Em voo" com um ping de posição recente (`App\Entity\PosicaoAoVivo`),
 * `liveFlights()` agora inclui essa posição real (`live: true`) e
 * `mapa-ao-vivo.js` usa ela direto no mapa, com polling em `GET
 * /mapa-ao-vivo/posicoes`. Uma aeronave "Em voo" SEM ping ainda (cliente
 * antigo, ou decolou sem `--server`) continua caindo no comportamento
 * anterior (`live: false`) — o navegador repete em loop o voo com
 * telemetria mais recente dela
 * (`VooRepository::findMaisRecenteComTelemetriaByAeronaveReg()`), com a
 * posição sintetizada ao longo do arco origem→destino. Convivência
 * deliberada, mesmo padrão mock/real do resto do app: nada quebra pra
 * quem ainda não atualizou o script de captura.
 *
 * **Atualizado: catálogo de aeroportos vem do banco.** `airportsUrl`
 * agora aponta pra `AeroportoController::catalogo()` em vez do antigo
 * `public/assets/data/airports.json` — mesmo formato JSON de sempre,
 * `mapa-ao-vivo.js` não mudou uma linha (ver README).
 */
class MapaAoVivoController extends AbstractController
{
    #[Route('/mapa-ao-vivo', name: 'app_mapa_ao_vivo', methods: ['GET'])]
    public function index(Request $request, AeronaveRepository $aeronaves, VooRepository $voos, PosicaoAoVivoRepository $posicoes): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('mapa_ao_vivo/index.html.twig', [
            'activeView' => 'mapaVivo',
            'pilot' => $pilot,
            'liveFlights' => $this->liveFlights($aeronaves, $voos, $posicoes),
            'parkedAircraft' => $this->parkedAircraft($aeronaves),
            'airportsUrl' => $this->generateUrl('app_aeroportos_catalogo'),
            'flightsUrl' => '/assets/data/flights.json',
            'posicoesUrl' => '/mapa-ao-vivo/posicoes',
        ]);
    }

    /**
     * Só a posição ao vivo, em JSON — polling do cliente
     * (`mapa-ao-vivo.js`, a cada ~10-15 s) a partir da carga inicial da
     * tela. Mesmo guard de sessão da tela; devolve o mesmo formato que
     * `liveFlights` já injeta no `window.KATABATIC_MV_FLYING` no load,
     * pra `mapa-ao-vivo.js` reaproveitar o parsing.
     */
    #[Route('/mapa-ao-vivo/posicoes', name: 'app_mapa_ao_vivo_posicoes', methods: ['GET'])]
    public function posicoes(Request $request, AeronaveRepository $aeronaves, VooRepository $voos, PosicaoAoVivoRepository $posicoes): JsonResponse
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->json(['error' => 'Sessão expirada.'], 401);
        }

        return $this->json($this->liveFlights($aeronaves, $voos, $posicoes));
    }

    /**
     * Uma entrada por aeronave "Em voo" — com posição real (`live:
     * true` + `lat`/`lon`/`altFt`/`hdgTrue`/`gsKt`/`iasKt`/`onGround`/
     * `atualizadaEm`) quando existe um ping do ACARS pra ela; senão
     * (`live: false`), cai no replay simulado de sempre, e só entra na
     * lista se tiver algum voo com telemetria gravada pra repetir em
     * loop — ver docblock da classe.
     *
     * @return list<array{reg: string, modelo: string, callsign: string, tipo: string, origem: string, destino: string, tempoMin: int, flightId: string|null, live: bool, lat?: float, lon?: float, altFt?: int|null, hdgTrue?: float|null, gsKt?: int|null, iasKt?: int|null, onGround?: bool|null, atualizadaEm?: string}>
     */
    private function liveFlights(AeronaveRepository $aeronaves, VooRepository $voos, PosicaoAoVivoRepository $posicoes): array
    {
        $emVoo = $aeronaves->findAllEmVoo();
        $posicoesPorReg = $posicoes->findByAeronaves($emVoo);

        $out = [];
        foreach ($emVoo as $a) {
            $ping = $posicoesPorReg[$a->getReg()] ?? null;

            if (null !== $ping) {
                // Posição real: não depende de haver um Voo com
                // telemetria gravada (ver docblock da classe) — o
                // callsign/tipo/origem/destino de exibição ainda vêm do
                // voo com telemetria mais recente quando existe (só pra
                // preencher o popup), mas caem pra genérico se não
                // houver nenhum (aeronave nova, primeiro voo dela via
                // ACARS sem nenhum voo anterior gravado).
                $voo = $voos->findMaisRecenteComTelemetriaByAeronaveReg($a->getReg());
                $out[] = [
                    'reg' => $a->getReg(),
                    'modelo' => $a->getTipo(),
                    'callsign' => $voo?->getCallsign() ?? $a->getReg(),
                    'tipo' => $voo?->getTipoOperacao() ?? '',
                    'origem' => $voo?->getOrigem() ?? $a->getBase(),
                    'destino' => $voo?->getDestino() ?? $a->getPosIcao(),
                    'tempoMin' => $voo?->getTempoMin() ?? 0,
                    'flightId' => $voo?->getCodigo(),
                    'live' => true,
                    'lat' => $ping->getLat(),
                    'lon' => $ping->getLon(),
                    'altFt' => $ping->getAltFt(),
                    'hdgTrue' => $ping->getHdgTrue(),
                    'gsKt' => $ping->getGsKt(),
                    'iasKt' => $ping->getIasKt(),
                    'onGround' => $ping->isOnGround(),
                    'atualizadaEm' => $ping->getRecebidaEm()->format(\DateTimeInterface::ATOM),
                ];
                continue;
            }

            // Sem ping ainda: comportamento antigo (replay simulado) -
            // sem telemetria gravada pra repetir, a aeronave nem aparece.
            $voo = $voos->findMaisRecenteComTelemetriaByAeronaveReg($a->getReg());
            if (null === $voo) {
                continue;
            }
            $out[] = [
                'reg' => $a->getReg(),
                'modelo' => $a->getTipo(),
                'callsign' => $voo->getCallsign(),
                'tipo' => $voo->getTipoOperacao(),
                'origem' => $voo->getOrigem(),
                'destino' => $voo->getDestino(),
                'tempoMin' => $voo->getTempoMin(),
                'flightId' => $voo->getCodigo(),
                'live' => false,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{reg: string, modelo: string, base: string, pos: string, status: string, statusTag: string}>
     */
    private function parkedAircraft(AeronaveRepository $aeronaves): array
    {
        return array_map(
            fn (Aeronave $a) => [
                'reg' => $a->getReg(),
                'modelo' => $a->getTipo(),
                'base' => $a->getBase(),
                'pos' => $a->getPosIcao(),
                'status' => $a->getStatusEfetivo(),
                'statusTag' => $a->getStatusTag(),
            ],
            $aeronaves->findAllNotEmVoo()
        );
    }
}
