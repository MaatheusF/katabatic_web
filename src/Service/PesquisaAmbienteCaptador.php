<?php

namespace App\Service;

use App\Entity\Aeronave;
use App\Entity\PesquisaAmostra;
use App\Repository\ConfiguracaoPesquisaRepository;
use App\Repository\PesquisaAmostraRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Decide se um heartbeat de posição ACARS (`AcarsIngestaoController::
 * posicao()`) deve virar uma amostra ambiente de pesquisa, e a grava
 * quando sim. Ver docblock de `App\Entity\PesquisaAmostra` pro desenho
 * geral (por que sem FK pra `Voo`, por que congelado no fechamento).
 *
 * **Melhor esforço absoluto.** Chamado no meio do heartbeat de posição
 * — que é o sinal "esta aeronave está viva" pro Mapa ao vivo. Nenhuma
 * falha aqui (Open-Meteo fora do ar, timeout, erro de banco) pode
 * derrubar o heartbeat em si: `capturar()` nunca lança, sempre volta
 * silenciosamente. Ver `AcarsIngestaoController::posicao()`, que chama
 * isto DEPOIS de já ter persistido a posição.
 */
class PesquisaAmbienteCaptador
{
    public function __construct(
        private readonly OpenMeteoClient $openMeteo,
        private readonly ConfiguracaoPesquisaRepository $configs,
        private readonly PesquisaAmostraRepository $amostras,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param Aeronave $aeronave já persistida, com `emVooTipoOperacao`
     *                            avaliado por `isEmVooDePesquisa()`
     */
    public function capturar(Aeronave $aeronave, float $lat, float $lon, ?int $altFt, \DateTimeImmutable $agora): void
    {
        if (!$aeronave->isEmVooDePesquisa()) {
            return;
        }

        try {
            $config = $this->configs->obterOuCriar($this->em);

            $ultima = $this->amostras->findMaisRecenteByAeronaveReg($aeronave->getReg());
            if (null !== $ultima) {
                $proxima = $ultima->getCapturadoEm()->modify('+'.$config->getAmostraCadenciaMin().' minutes');
                if ($agora < $proxima) {
                    return;
                }
            }

            $condicao = $this->openMeteo->condicaoAtual($lat, $lon);
            if (null === $condicao) {
                // Sem dado desta vez - não grava um buraco fingindo ser
                // amostra; o próximo heartbeat tenta de novo.
                return;
            }

            $severidade = $config->classificarSeveridade($condicao['ventoKt'], $condicao['rajadaKt'], $condicao['precipMmH']);

            $amostra = new PesquisaAmostra($aeronave->getReg(), $agora, $lat, $lon, $severidade);
            $amostra->setAltFt($altFt);
            $amostra->setVentoKt($condicao['ventoKt']);
            $amostra->setVentoDir($condicao['ventoDir']);
            $amostra->setRajadaKt($condicao['rajadaKt']);
            $amostra->setTempC($condicao['tempC']);
            $amostra->setPressaoHpa($condicao['pressaoHpa']);
            $amostra->setPrecipMmH($condicao['precipMmH']);
            $amostra->setWeatherCode($condicao['weatherCode']);

            $this->em->persist($amostra);
            $this->em->flush();
        } catch (\Throwable) {
            // Melhor esforço - ver docblock da classe.
        }
    }
}
