<?php

namespace App\Service;

use App\Entity\Aeronave;
use App\Entity\PesquisaCaptura;
use App\Repository\ConfiguracaoPesquisaRepository;
use App\Repository\PesquisaCapturaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Equivalente de `PesquisaAmbienteCaptador` pra captura de mapa/vento
 * por altitude — cadência mais espaçada
 * (`ConfiguracaoPesquisa::$capturaCadenciaMin`), chamada do mesmo lugar
 * (`AcarsIngestaoController::posicao()`), mesma garantia de melhor
 * esforço absoluto (nunca lança, nunca pode derrubar o heartbeat de
 * posição).
 */
class PesquisaCapturaOrquestrador
{
    public function __construct(
        private readonly PesquisaMapaCaptador $mapaCaptador,
        private readonly OpenMeteoClient $openMeteo,
        private readonly ConfiguracaoPesquisaRepository $configs,
        private readonly PesquisaCapturaRepository $capturas,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function capturarSeNecessario(Aeronave $aeronave, float $lat, float $lon, ?int $altFt, \DateTimeImmutable $agora): void
    {
        if (!$aeronave->isEmVooDePesquisa()) {
            return;
        }

        try {
            $config = $this->configs->obterOuCriar($this->em);

            $ultima = $this->capturas->findMaisRecenteByAeronaveReg($aeronave->getReg());
            if (null !== $ultima) {
                $proxima = $ultima->getCapturadoEm()->modify('+'.$config->getCapturaCadenciaMin().' minutes');
                if ($agora < $proxima) {
                    return;
                }
            }

            $manifesto = $this->mapaCaptador->capturar($aeronave->getReg(), $lat, $lon, $agora, $config->getMapaGridRaio());

            // Área de vento acompanhando a MESMA configuração de raio da
            // imagem — ver `PesquisaMapaCaptador::grauPorRaioTiles()`.
            // Antes o vento sempre usava os valores fixos default de
            // `gradeVento()` (±1°, 3×3 pontos), então nunca crescia junto
            // com `mapaGridRaio` — a área de vento acabava menor que 1
            // tile de largura mesmo com o raio configurado bem maior.
            $raioGrausVento = $this->mapaCaptador->grauPorRaioTiles($config->getMapaGridRaio());
            $pontosPorEixoVento = min(2 * max(1, $config->getMapaGridRaio()) + 1, 15);
            $grade = $this->openMeteo->gradeVento($lat, $lon, $config->getNiveisPressaoHpa(), $raioGrausVento, $pontosPorEixoVento);

            if (null === $manifesto && null === $grade) {
                // Nada deu certo neste ciclo - não grava uma captura vazia,
                // o próximo heartbeat na cadência tenta de novo.
                return;
            }

            $captura = new PesquisaCaptura($aeronave->getReg(), $agora, $lat, $lon);
            $captura->setAltFt($altFt);
            $captura->setManifestoMapa($manifesto);
            $captura->setGradeVento($grade);

            $this->em->persist($captura);
            $this->em->flush();
        } catch (\Throwable) {
            // Melhor esforço - ver docblock da classe.
        }
    }
}
