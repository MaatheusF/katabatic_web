<?php

namespace App\Controller;

use App\Entity\ConfiguracaoPesquisa;
use App\Repository\ConfiguracaoPesquisaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tela admin (`$pilot['admin']`, mesmo guard de
 * `AeroportoController`/`TipoAeronaveController`) pra editar a
 * configuração única da camada de pesquisa meteorológica — cadência de
 * amostragem/captura e limiares de severidade (ver `App\Entity\
 * ConfiguracaoPesquisa`). Registro único: sem lista, sem criar/remover
 * — só um formulário que sempre edita a mesma linha
 * (`ConfiguracaoPesquisaRepository::obterOuCriar()`), mesmo padrão de
 * `PerfilController` (form POST direto, sem JSON/JS de submissão) em
 * vez do padrão JSON-API de `TipoAeronaveController` (que existe porque
 * lá há uma lista de verdade pra manter em sincronia sem recarregar a
 * página).
 */
class ConfiguracaoPesquisaController extends AbstractController
{
    #[Route('/configuracoes/pesquisa', name: 'app_configuracao_pesquisa', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em, ConfiguracaoPesquisaRepository $configs): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }
        if (empty($pilot['admin'])) {
            return $this->redirectToRoute('app_portal');
        }

        return $this->render('configuracao_pesquisa/index.html.twig', [
            'activeView' => 'configuracaoPesquisa',
            'pilot' => $pilot,
            'config' => $this->viewModel($configs->obterOuCriar($em)),
            'errors' => [],
        ]);
    }

    #[Route('/configuracoes/pesquisa', name: 'app_configuracao_pesquisa_atualizar', methods: ['POST'])]
    public function atualizar(Request $request, EntityManagerInterface $em, ConfiguracaoPesquisaRepository $configs): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }
        if (empty($pilot['admin'])) {
            return $this->redirectToRoute('app_portal');
        }

        $config = $configs->obterOuCriar($em);

        [$campos, $errors] = $this->validar($request);

        if ([] !== $errors) {
            return $this->render('configuracao_pesquisa/index.html.twig', [
                'activeView' => 'configuracaoPesquisa',
                'pilot' => $pilot,
                'config' => $campos,
                'errors' => $errors,
            ]);
        }

        $config->setAmostraCadenciaMin($campos['amostraCadenciaMin']);
        $config->setCapturaCadenciaMin($campos['capturaCadenciaMin']);
        $config->setMapaGridRaio($campos['mapaGridRaio']);
        $config->setNiveisPressaoHpa($campos['niveisPressaoHpa']);
        $config->setVentoModeradoKt($campos['ventoModeradoKt']);
        $config->setVentoSeveroKt($campos['ventoSeveroKt']);
        $config->setRajadaSeveraKt($campos['rajadaSeveraKt']);
        $config->setPrecipModeradaMmH($campos['precipModeradaMmH']);
        $config->setPrecipSeveraMmH($campos['precipSeveraMmH']);
        $config->touch();

        $em->flush();

        $this->addFlash('success', 'Configuração da pesquisa meteorológica atualizada.');

        return $this->redirectToRoute('app_configuracao_pesquisa');
    }

    /**
     * @return array{0: array{amostraCadenciaMin: int, capturaCadenciaMin: int, mapaGridRaio: int, niveisPressaoHpa: list<int>, ventoModeradoKt: int, ventoSeveroKt: int, rajadaSeveraKt: int, precipModeradaMmH: float, precipSeveraMmH: float}, 1: list<string>}
     */
    private function validar(Request $request): array
    {
        $errors = [];

        $inteiro = function (string $campo, string $rotulo, int $min, int $max) use ($request, &$errors): int {
            $bruto = $request->request->get($campo, '');
            if (!is_numeric($bruto) || (int) $bruto < $min || (int) $bruto > $max) {
                $errors[] = sprintf('%s inválido — informe um número inteiro entre %d e %d.', $rotulo, $min, $max);

                return $min;
            }

            return (int) round((float) $bruto);
        };

        $decimal = function (string $campo, string $rotulo, float $min, float $max) use ($request, &$errors): float {
            $bruto = $request->request->get($campo, '');
            if (!is_numeric($bruto) || (float) $bruto < $min || (float) $bruto > $max) {
                $errors[] = sprintf('%s inválido — informe um número entre %s e %s.', $rotulo, $min, $max);

                return $min;
            }

            return (float) $bruto;
        };

        $amostraCadenciaMin = $inteiro('amostraCadenciaMin', 'Cadência de amostragem ambiente', 1, 60);
        $capturaCadenciaMin = $inteiro('capturaCadenciaMin', 'Cadência de captura de mapa/vento', 5, 180);
        $mapaGridRaio = $inteiro('mapaGridRaio', 'Raio da grade de mapa', 1, 15);
        $ventoModeradoKt = $inteiro('ventoModeradoKt', 'Limiar de vento moderado', 0, 200);
        $ventoSeveroKt = $inteiro('ventoSeveroKt', 'Limiar de vento severo', 0, 250);
        $rajadaSeveraKt = $inteiro('rajadaSeveraKt', 'Limiar de rajada severa', 0, 250);
        $precipModeradaMmH = $decimal('precipModeradaMmH', 'Limiar de precipitação moderada', 0, 500);
        $precipSeveraMmH = $decimal('precipSeveraMmH', 'Limiar de precipitação severa', 0, 500);

        if ([] === $errors && $ventoSeveroKt < $ventoModeradoKt) {
            $errors[] = 'O limiar de vento severo precisa ser maior ou igual ao moderado.';
        }
        if ([] === $errors && $precipSeveraMmH < $precipModeradaMmH) {
            $errors[] = 'O limiar de precipitação severa precisa ser maior ou igual ao moderado.';
        }

        $niveisBruto = trim((string) $request->request->get('niveisPressaoHpa', ''));
        $niveis = [];
        if ('' === $niveisBruto) {
            $errors[] = 'Informe ao menos um nível de pressão.';
        } else {
            foreach (preg_split('/[,\s]+/', $niveisBruto, -1, PREG_SPLIT_NO_EMPTY) as $parte) {
                if (!is_numeric($parte) || (int) $parte < 50 || (int) $parte > 1050) {
                    $errors[] = sprintf('Nível de pressão inválido: "%s" — use valores em hPa entre 50 e 1050.', $parte);
                    continue;
                }
                $niveis[] = (int) $parte;
            }
            if ([] === $niveis && [] === $errors) {
                $errors[] = 'Informe ao menos um nível de pressão.';
            }
            if (\count($niveis) > 12) {
                $errors[] = 'No máximo 12 níveis de pressão — cada nível a mais multiplica o tamanho da grade de vento armazenada por captura.';
            }
        }
        // Maior pressão (mais baixo, mais próximo do solo) primeiro —
        // é a ordem que o relatório e o seletor de altitude do mapa
        // científico vão esperar.
        rsort($niveis);

        return [[
            'amostraCadenciaMin' => $amostraCadenciaMin,
            'capturaCadenciaMin' => $capturaCadenciaMin,
            'mapaGridRaio' => $mapaGridRaio,
            'niveisPressaoHpa' => $niveis,
            'ventoModeradoKt' => $ventoModeradoKt,
            'ventoSeveroKt' => $ventoSeveroKt,
            'rajadaSeveraKt' => $rajadaSeveraKt,
            'precipModeradaMmH' => $precipModeradaMmH,
            'precipSeveraMmH' => $precipSeveraMmH,
        ], $errors];
    }

    /**
     * @return array{amostraCadenciaMin: int, capturaCadenciaMin: int, mapaGridRaio: int, niveisPressaoHpa: list<int>, ventoModeradoKt: int, ventoSeveroKt: int, rajadaSeveraKt: int, precipModeradaMmH: float, precipSeveraMmH: float}
     */
    private function viewModel(ConfiguracaoPesquisa $c): array
    {
        return [
            'amostraCadenciaMin' => $c->getAmostraCadenciaMin(),
            'capturaCadenciaMin' => $c->getCapturaCadenciaMin(),
            'mapaGridRaio' => $c->getMapaGridRaio(),
            'niveisPressaoHpa' => $c->getNiveisPressaoHpa(),
            'ventoModeradoKt' => $c->getVentoModeradoKt(),
            'ventoSeveroKt' => $c->getVentoSeveroKt(),
            'rajadaSeveraKt' => $c->getRajadaSeveraKt(),
            'precipModeradaMmH' => $c->getPrecipModeradaMmH(),
            'precipSeveraMmH' => $c->getPrecipSeveraMmH(),
        ];
    }
}
