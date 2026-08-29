<?php

namespace App\Service;

use App\Repository\PesquisaAmostraRepository;
use App\Repository\PesquisaCapturaRepository;

/**
 * Congela, no fechamento de um voo de Pesquisa
 * (`AcarsIngestaoController::ingerir()`), o resumo das amostras
 * ambiente capturadas ao vivo durante o voo (ver `App\Entity\
 * PesquisaAmostra` e `PesquisaAmbienteCaptador`) dentro de
 * `Voo::$dados['pesquisa']` — mesmo princípio de "congelar no
 * fechamento" que `metar`/`metarPouso` já seguem: o resumo não muda
 * mais depois disso, mesmo que os limiares de severidade
 * (`ConfiguracaoPesquisa`) sejam ajustados no futuro.
 *
 * Separado de `AcarsIngestaoController` pelo mesmo motivo que
 * `TelemetriaVooBuilder` é separado de lá (ver docblock da classe):
 * o controller só valida, resolve entidades e persiste — montar dado
 * complexo é responsabilidade de uma classe de serviço à parte,
 * testável isolada.
 */
class PesquisaVooAggregator
{
    public function __construct(
        private readonly PesquisaAmostraRepository $amostras,
        private readonly PesquisaCapturaRepository $capturas,
    ) {
    }

    /**
     * @return array{amostras: list<array<string, mixed>>, resumo: array{total: int, piorSeveridade: string, ventoMaxKt: ?float, rajadaMaxKt: ?float, precipMaxMmH: ?float, tempMinC: ?float, tempMaxC: ?float}, capturas: list<array<string, mixed>>}|null
     *         `null` quando não há nenhuma amostra na janela do voo — a
     *         chave `pesquisa` simplesmente não é escrita em `Voo::$dados`
     *         nesse caso (voo de Pesquisa cuja sessão nunca mandou
     *         heartbeat de posição, por exemplo). Capturas de mapa/vento
     *         entram junto quando existirem, mesmo critério de janela —
     *         mas sozinhas (sem nenhuma amostra ambiente) não bastam pra
     *         gerar o resumo, então não formam `pesquisa` por conta
     *         própria.
     */
    public function agregar(string $aeronaveReg, \DateTimeImmutable $startedAt, \DateTimeImmutable $encerradoEm): ?array
    {
        $lista = $this->amostras->findParaVoo($aeronaveReg, $startedAt, $encerradoEm);
        if ([] === $lista) {
            return null;
        }

        $ordem = ['calmo' => 0, 'moderado' => 1, 'severo' => 2];
        $pior = 'calmo';
        $ventoMax = null;
        $rajadaMax = null;
        $precipMax = null;
        $tempMin = null;
        $tempMax = null;

        foreach ($lista as $amostra) {
            if (($ordem[$amostra->getSeveridade()] ?? 0) > ($ordem[$pior] ?? 0)) {
                $pior = $amostra->getSeveridade();
            }
            $ventoMax = null === $ventoMax ? $amostra->getVentoKt() : max($ventoMax, $amostra->getVentoKt() ?? $ventoMax);
            $rajadaMax = null === $rajadaMax ? $amostra->getRajadaKt() : max($rajadaMax, $amostra->getRajadaKt() ?? $rajadaMax);
            $precipMax = null === $precipMax ? $amostra->getPrecipMmH() : max($precipMax, $amostra->getPrecipMmH() ?? $precipMax);
            if (null !== $amostra->getTempC()) {
                $tempMin = null === $tempMin ? $amostra->getTempC() : min($tempMin, $amostra->getTempC());
                $tempMax = null === $tempMax ? $amostra->getTempC() : max($tempMax, $amostra->getTempC());
            }
        }

        $capturas = $this->capturas->findParaVoo($aeronaveReg, $startedAt, $encerradoEm);

        return [
            'amostras' => array_map(static fn ($a) => $a->toArray(), $lista),
            'resumo' => [
                'total' => \count($lista),
                'piorSeveridade' => $pior,
                'ventoMaxKt' => $ventoMax,
                'rajadaMaxKt' => $rajadaMax,
                'precipMaxMmH' => $precipMax,
                'tempMinC' => $tempMin,
                'tempMaxC' => $tempMax,
            ],
            'capturas' => array_map(static fn ($c) => $c->toArray(), $capturas),
        ];
    }
}
