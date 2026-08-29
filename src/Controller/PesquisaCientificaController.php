<?php

namespace App\Controller;

use App\Repository\PilotRepository;
use App\Repository\VooRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Área científica de um voo de Pesquisa — página separada do
 * relatório universal (`/voo`, SPA de logbook), acessível por um link
 * a partir de lá. Mesmo guard de posse dos outros endpoints por
 * `{codigo}` em `VooController` (`findOneByCodigoForPilot()`), mas
 * renderizada no servidor (não é mais uma tela da SPA de `voo.js`) —
 * o conteúdo aqui (narrativa do relatório, tabela de estatísticas) é
 * page-load, não precisa do ciclo de fetch/estado do resto do
 * Logbook; só o mapa (rota + replay de captura + vento animado) é
 * interativo no cliente (`pesquisa-cientifica.js`).
 *
 * `Voo::getPesquisa()` (ver docblock de lá) devolve `null` pra
 * qualquer voo que não seja de Pesquisa ou cuja sessão ACARS não
 * tenha mandado nenhum heartbeat de posição — nesse caso a página
 * mostra um estado vazio explicando o porquê, em vez de 404 (o voo
 * existe, só não tem dado de pesquisa pra mostrar).
 */
class PesquisaCientificaController extends AbstractController
{
    #[Route('/voo/{codigo}/pesquisa', name: 'app_voo_pesquisa', methods: ['GET'])]
    public function index(
        string $codigo,
        Request $request,
        PilotRepository $pilots,
        VooRepository $voos,
        #[Autowire('%env(CARTO_API_KEY)%')] string $cartoApiKey,
    ): Response {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->redirectToRoute('app_login');
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        $voo = $voos->findOneByCodigoForPilot($codigo, $pilot);
        if (null === $voo) {
            return $this->redirectToRoute('app_voo');
        }

        $pesquisa = $voo->getPesquisa();
        $telemetria = $voo->getTelemetria();

        return $this->render('pesquisa_cientifica/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $sessionPilot,
            'cartoApiKey' => $cartoApiKey,
            'voo' => [
                'codigo' => $voo->getCodigo(),
                'callsign' => $voo->getCallsign(),
                'aeronaveReg' => $voo->getAeronaveReg(),
                'origem' => $voo->getOrigem(),
                'destino' => $voo->getDestino(),
                'startedAt' => $voo->getStartedAt()->format(\DateTimeInterface::ATOM),
            ],
            'pesquisa' => $pesquisa,
            'track' => \is_array($telemetria) && \is_array($telemetria['track'] ?? null) ? $telemetria['track'] : [],
            // Telemetria REAL da aeronave (MSFS, via TelemetryDeriver) —
            // além de `track` (só rota), o front usa `env`/`events` daqui
            // pra correlacionar posição x tempo dos eventos de gelo/vento/
            // precipitação sentidos de verdade. Pedido em conversa:
            // "dados de telemetria real com índice de gelo".
            'telemetria' => $telemetria,
        ]);
    }
}
