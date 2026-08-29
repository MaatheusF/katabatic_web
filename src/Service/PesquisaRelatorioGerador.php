<?php

namespace App\Service;

/**
 * Monta o relatório automático de um voo de Pesquisa — determinístico
 * (sem IA, decisão tomada em conversa: sem alternativa gratuita boa o
 * bastante, preferível a depender de uma API paga), a partir do resumo
 * congelado por `PesquisaVooAggregator` (amostras ambiente + capturas
 * de mapa/vento) e, quando disponível, da telemetria da própria
 * aeronave (`Voo::$dados['telemetria']`, produzida por
 * `TelemetryDeriver`) — pra correlacionar o pior momento meteorológico
 * com o que a aeronave sentiu de verdade (G, toque, etc.).
 *
 * Devolve uma ESTRUTURA (não uma string em markdown) — mais fácil pro
 * template renderizar cada seção com o estilo certo, e mais fácil de
 * reaproveitar (JSON, export futuro) sem precisar reparsear markdown.
 * Chamado uma única vez, no fechamento do voo
 * (`AcarsIngestaoController::ingerir()`), e congelado dentro de
 * `Voo::$dados['pesquisa']['relatorio']` — nunca recalculado depois,
 * mesmo texto/números pra sempre, mesmo se os limiares de severidade
 * mudarem (mesmo princípio de `metar`/`metarPouso`).
 *
 * **Escopo deliberado da narrativa.** Divide o voo em janelas
 * cronológicas usando os horários das CAPTURAS de mapa/vento como
 * fronteira (cadência mais espaçada, ~30 min — são os "capítulos"
 * naturais da missão); quando não há nenhuma captura (só amostras
 * ambiente, cadência mais fina), agrupa as amostras em até 6 blocos
 * aproximadamente iguais no tempo. Cada janela vira um parágrafo de
 * verdade, com números reais — não uma linha.
 */
class PesquisaRelatorioGerador
{
    /** Catálogo de weather_code (WMO), mesmo catálogo que `mapa-ao-vivo.js` bucketiza no cliente (`weatherCodeInfo()`) — aqui, uma descrição textual em vez de um bucket de severidade. */
    private const WEATHER_LABELS = [
        0 => 'céu limpo', 1 => 'poucas nuvens', 2 => 'parcialmente nublado', 3 => 'encoberto',
        45 => 'névoa', 48 => 'névoa com deposição de gelo',
        51 => 'garoa fraca', 53 => 'garoa moderada', 55 => 'garoa forte',
        56 => 'garoa congelante fraca', 57 => 'garoa congelante forte',
        61 => 'chuva fraca', 63 => 'chuva moderada', 65 => 'chuva forte',
        66 => 'chuva congelante fraca', 67 => 'chuva congelante forte',
        71 => 'neve fraca', 73 => 'neve moderada', 75 => 'neve forte', 77 => 'grãos de neve',
        80 => 'pancadas de chuva fracas', 81 => 'pancadas de chuva moderadas', 82 => 'pancadas de chuva violentas',
        85 => 'pancadas de neve fracas', 86 => 'pancadas de neve fortes',
        95 => 'trovoada', 96 => 'trovoada com granizo fraco', 99 => 'trovoada com granizo forte',
    ];

    /**
     * @param array{amostras: list<array<string, mixed>>, resumo: array<string, mixed>, capturas: list<array<string, mixed>>} $pesquisa
     * @param array<string, mixed>|null                                                                                       $telemetria
     *
     * @return array{geradoEm: string, resumoMissao: array<string, mixed>, narrativa: list<string>, destaques: list<string>, estatisticas: list<array{label: string, valor: string}>, indicadoresTelemetria: list<array{label: string, valor: string, tag: string}>}
     */
    public function gerar(
        string $callsign,
        string $aeronaveReg,
        string $tipoAeronave,
        string $origem,
        string $destino,
        \DateTimeImmutable $startedAt,
        int $tempoMin,
        array $pesquisa,
        ?array $telemetria,
    ): array {
        $amostras = $pesquisa['amostras'];
        $resumo = $pesquisa['resumo'];
        $capturas = $pesquisa['capturas'];

        return [
            'geradoEm' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'resumoMissao' => $this->resumoMissao($callsign, $aeronaveReg, $tipoAeronave, $origem, $destino, $startedAt, $tempoMin, $resumo),
            'narrativa' => $this->narrativa($amostras, $capturas, $startedAt),
            'destaques' => $this->destaques($amostras, $resumo, $telemetria, $startedAt),
            'estatisticas' => $this->estatisticas($resumo, \count($amostras), \count($capturas), $tempoMin),
            'indicadoresTelemetria' => $this->indicadoresTelemetria($telemetria),
        ];
    }

    /**
     * Indicadores de telemetria REAL da aeronave (MSFS, via
     * `TelemetryDeriver`) — diferente de tudo mais nesta classe, que
     * vem da previsão meteorológica externa (Open-Meteo) ao longo da
     * rota. Pedido em conversa: "dados de telemetria real com índice
     * de gelo, tempestades no local etc" — `ice_pct` (gelo estrutural,
     * sensor `STRUCTURAL ICE PCT` do simulador), OAT, vento e
     * precipitação sentidos pela própria aeronave, e eventos de
     * `TelemetryDeriver::deriveEvents()` do tipo `icing_onset`. `tag`
     * reaproveita as 3 cores já usadas em `.pc-badge-*` (calmo/
     * moderado/severo) — mesmo vocabulário visual do resto da página.
     *
     * @param array<string, mixed>|null $telemetria
     *
     * @return list<array{label: string, valor: string, tag: string}>
     */
    private function indicadoresTelemetria(?array $telemetria): array
    {
        if (null === $telemetria) {
            return [];
        }

        $linhas = [];

        $ice = is_numeric($telemetria['ice'] ?? null) ? (float) $telemetria['ice'] : 0.0;
        $linhas[] = [
            'label' => 'Gelo estrutural máximo (sensor da aeronave)',
            'valor' => sprintf('%.1f%%', $ice),
            'tag' => $ice <= 0.0 ? 'calmo' : ($ice < 10.0 ? 'moderado' : 'severo'),
        ];

        $oatMin = $telemetria['oat_min'] ?? null;
        $oatMax = $telemetria['oat_max'] ?? null;
        if (is_numeric($oatMin) && is_numeric($oatMax)) {
            $linhas[] = [
                'label' => 'Temperatura externa (OAT, sensor)',
                'valor' => sprintf('%.0f°C a %.0f°C', $oatMin, $oatMax),
                'tag' => ((float) $oatMin) <= -20.0 ? 'moderado' : 'calmo',
            ];
        }

        $windMax = $telemetria['wind_max'] ?? null;
        if (is_numeric($windMax)) {
            $windMax = (float) $windMax;
            $linhas[] = [
                'label' => 'Vento máximo sentido pela aeronave',
                'valor' => sprintf('%.0f kt', $windMax),
                'tag' => $windMax >= 40 ? 'severo' : ($windMax >= 25 ? 'moderado' : 'calmo'),
            ];
        }

        $precipMax = $telemetria['precip_max'] ?? null;
        if (is_numeric($precipMax) && ((float) $precipMax) > 0) {
            $precipMax = (float) $precipMax;
            $linhas[] = [
                'label' => 'Precipitação máxima (sensor da aeronave)',
                'valor' => sprintf('%.2f mm/h', $precipMax),
                'tag' => $precipMax >= 15 ? 'severo' : ($precipMax >= 4 ? 'moderado' : 'calmo'),
            ];
        }

        $imc = $telemetria['imc'] ?? null;
        if (is_numeric($imc)) {
            $imc = (int) $imc;
            $linhas[] = [
                'label' => 'Tempo dentro de nuvens (IMC)',
                'valor' => sprintf('%d%% do voo', $imc),
                'tag' => $imc >= 50 ? 'moderado' : 'calmo',
            ];
        }

        $wx = $telemetria['wx'] ?? null;
        if (\is_string($wx) && 'Claro' !== $wx) {
            $linhas[] = [
                'label' => 'Condição predominante (sensor da aeronave)',
                'valor' => $wx,
                'tag' => 'moderado',
            ];
        }

        $eventos = $telemetria['events'] ?? null;
        if (\is_array($eventos)) {
            foreach ($eventos as $e) {
                if (!\is_array($e) || \count($e) < 5 || 'icing_onset' !== $e[1]) {
                    continue;
                }
                [, , $titulo, $valor, $severidade] = $e;
                $linhas[] = [
                    'label' => 'Evento: '.$titulo,
                    'valor' => '' !== (string) $valor ? (string) $valor : '—',
                    'tag' => 'bad' === $severidade ? 'severo' : ('warn' === $severidade ? 'moderado' : 'calmo'),
                ];
            }
        }

        return $linhas;
    }

    /**
     * @param array<string, mixed> $resumo
     *
     * @return array<string, mixed>
     */
    private function resumoMissao(string $callsign, string $aeronaveReg, string $tipoAeronave, string $origem, string $destino, \DateTimeImmutable $startedAt, int $tempoMin, array $resumo): array
    {
        return [
            'callsign' => $callsign,
            'aeronaveReg' => $aeronaveReg,
            'tipoAeronave' => $tipoAeronave,
            'rota' => sprintf('%s → %s', $origem, $destino),
            'data' => $startedAt->format('d/m/Y'),
            'horaInicioZ' => $startedAt->format('H:i').'Z',
            'duracao' => sprintf('%dh%02d', intdiv($tempoMin, 60), $tempoMin % 60),
            'piorCondicao' => $resumo['piorSeveridade'],
            'titulo' => $this->tituloMissao((string) $resumo['piorSeveridade']),
        ];
    }

    private function tituloMissao(string $piorSeveridade): string
    {
        return match ($piorSeveridade) {
            'severo' => 'Missão de pesquisa em condições meteorológicas severas',
            'moderado' => 'Missão de pesquisa em condições meteorológicas moderadas',
            default => 'Missão de pesquisa em condições meteorológicas estáveis',
        };
    }

    /**
     * @param list<array<string, mixed>> $amostras
     * @param list<array<string, mixed>> $capturas
     *
     * @return list<string>
     */
    private function narrativa(array $amostras, array $capturas, \DateTimeImmutable $startedAt): array
    {
        if ([] === $amostras) {
            return ['Nenhuma amostra ambiente foi registrada durante este voo — a sessão ACARS pode não ter enviado heartbeats de posição.'];
        }

        $fronteiras = $this->fronteirasDeCapturas($capturas, $amostras);
        if ([] === $fronteiras) {
            $fronteiras = $this->fronteirasAutomaticas($amostras);
        }

        $janelas = $this->agruparPorFronteiras($amostras, $fronteiras);

        $paragrafos = [];
        foreach ($janelas as $janela) {
            $paragrafos[] = $this->paragrafoJanela($janela, $startedAt);
        }

        return $paragrafos;
    }

    /**
     * As capturas marcam o INÍCIO de cada janela (a primeira acontece
     * em t=0, a segunda ~30min depois, etc. — ver `PesquisaCaptura
     * Orquestrador`), não o fim. `agruparPorFronteiras()` espera
     * fronteiras como fim de janela — por isso a fronteira da janela i
     * é o INÍCIO da captura i+1 (não o dela mesma); a última janela vai
     * até a amostra mais recente. Bug corrigido: antes, usar os
     * horários das capturas direto como fronteira fazia a primeira
     * janela conter só a amostra exatamente em t=0 (quase vazia) e
     * empurrava todo o resto uma janela adiante.
     *
     * @param list<array<string, mixed>> $capturas
     * @param list<array<string, mixed>> $amostras
     *
     * @return list<\DateTimeImmutable>
     */
    private function fronteirasDeCapturas(array $capturas, array $amostras): array
    {
        if ([] === $capturas || [] === $amostras) {
            return [];
        }

        $inicios = array_map(static fn ($c) => new \DateTimeImmutable((string) $c['em']), $capturas);
        $fronteiras = \array_slice($inicios, 1);
        $fronteiras[] = new \DateTimeImmutable((string) $amostras[\count($amostras) - 1]['em']);

        return $fronteiras;
    }

    /**
     * @param list<array<string, mixed>> $amostras
     *
     * @return list<\DateTimeImmutable>
     */
    private function fronteirasAutomaticas(array $amostras): array
    {
        $primeiro = new \DateTimeImmutable((string) $amostras[0]['em']);
        $ultimo = new \DateTimeImmutable((string) $amostras[\count($amostras) - 1]['em']);
        $totalSeg = max(1, $ultimo->getTimestamp() - $primeiro->getTimestamp());
        $blocos = min(6, max(1, (int) ceil($totalSeg / 900))); // ~15 min por bloco, no máximo 6

        $fronteiras = [];
        for ($i = 1; $i <= $blocos; ++$i) {
            $fronteiras[] = $primeiro->modify('+'.(int) round($totalSeg * $i / $blocos).' seconds');
        }

        return $fronteiras;
    }

    /**
     * @param list<array<string, mixed>> $amostras
     * @param list<\DateTimeImmutable>   $fronteiras
     *
     * @return list<list<array<string, mixed>>>
     */
    private function agruparPorFronteiras(array $amostras, array $fronteiras): array
    {
        $janelas = array_fill(0, \count($fronteiras), []);
        foreach ($amostras as $a) {
            $em = new \DateTimeImmutable((string) $a['em']);
            $idx = 0;
            foreach ($fronteiras as $i => $limite) {
                $idx = $i;
                if ($em <= $limite) {
                    break;
                }
            }
            $janelas[$idx][] = $a;
        }

        return array_values(array_filter($janelas, static fn ($j) => [] !== $j));
    }

    /**
     * @param list<array<string, mixed>> $janela
     */
    private function paragrafoJanela(array $janela, \DateTimeImmutable $startedAt): string
    {
        $inicio = new \DateTimeImmutable((string) $janela[0]['em']);
        $fim = new \DateTimeImmutable((string) $janela[\count($janela) - 1]['em']);
        $minInicio = intdiv(max(0, $inicio->getTimestamp() - $startedAt->getTimestamp()), 60);
        $minFim = intdiv(max(0, $fim->getTimestamp() - $startedAt->getTimestamp()), 60);

        $ventos = array_values(array_filter(array_map(static fn ($a) => $a['ventoKt'], $janela), static fn ($v) => null !== $v));
        $rajadas = array_values(array_filter(array_map(static fn ($a) => $a['rajadaKt'], $janela), static fn ($v) => null !== $v));
        $precips = array_values(array_filter(array_map(static fn ($a) => $a['precipMmH'], $janela), static fn ($v) => null !== $v));
        $temps = array_values(array_filter(array_map(static fn ($a) => $a['tempC'], $janela), static fn ($v) => null !== $v));
        $codigos = array_values(array_filter(array_map(static fn ($a) => $a['weatherCode'], $janela), static fn ($v) => null !== $v));

        $ventoMed = [] !== $ventos ? array_sum($ventos) / \count($ventos) : null;
        $rajadaMax = [] !== $rajadas ? max($rajadas) : null;
        $precipMax = [] !== $precips ? max($precips) : null;
        $tempMed = [] !== $temps ? array_sum($temps) / \count($temps) : null;
        $tempoDescricao = [] !== $codigos ? $this->weatherLabel((int) $codigos[array_key_last($codigos)]) : null;

        $frase = sprintf('Entre %d e %d min de voo', $minInicio, $minFim);
        $frase .= null !== $tempoDescricao ? sprintf(', o tempo esteve predominantemente com %s', $tempoDescricao) : '';
        $frase .= '. ';

        if (null !== $ventoMed) {
            $frase .= sprintf('Vento médio de %.0f kt', $ventoMed);
            $frase .= null !== $rajadaMax && $rajadaMax > $ventoMed + 5 ? sprintf(', com rajadas de até %.0f kt', $rajadaMax) : '';
            $frase .= '. ';
        }
        if (null !== $precipMax && $precipMax > 0.1) {
            $frase .= sprintf('Precipitação de até %.1f mm/h registrada nesta janela. ', $precipMax);
        }
        if (null !== $tempMed) {
            $frase .= sprintf('Temperatura média de %.0f°C. ', $tempMed);
        }

        $piores = array_map(static fn ($a) => $a['severidade'], $janela);
        if (\in_array('severo', $piores, true)) {
            $frase .= 'Condição classificada como SEVERA em ao menos uma amostra desta janela.';
        } elseif (\in_array('moderado', $piores, true)) {
            $frase .= 'Condição classificada como moderada nesta janela.';
        } else {
            $frase .= 'Condição estável durante toda a janela.';
        }

        return trim($frase);
    }

    private function weatherLabel(int $code): string
    {
        return self::WEATHER_LABELS[$code] ?? 'condição não classificada';
    }

    /**
     * @param list<array<string, mixed>> $amostras
     * @param array<string, mixed>       $resumo
     * @param array<string, mixed>|null  $telemetria
     *
     * @return list<string>
     */
    private function destaques(array $amostras, array $resumo, ?array $telemetria, \DateTimeImmutable $startedAt): array
    {
        $destaques = [];

        $pior = $this->amostraMaisCritica($amostras);
        if (null !== $pior) {
            $em = new \DateTimeImmutable((string) $pior['em']);
            $minPior = intdiv(max(0, $em->getTimestamp() - $startedAt->getTimestamp()), 60);
            $partes = [];
            if (null !== $pior['ventoKt']) {
                $partes[] = sprintf('vento de %.0f kt', $pior['ventoKt']);
            }
            if (null !== $pior['rajadaKt']) {
                $partes[] = sprintf('rajada de %.0f kt', $pior['rajadaKt']);
            }
            if (null !== $pior['precipMmH'] && $pior['precipMmH'] > 0.1) {
                $partes[] = sprintf('precipitação de %.1f mm/h', $pior['precipMmH']);
            }
            $destaques[] = sprintf(
                'Momento mais crítico: %d min de voo (%s), severidade "%s"%s.',
                $minPior,
                $em->format('H:i').'Z',
                $pior['severidade'],
                [] !== $partes ? ' — '.implode(', ', $partes) : ''
            );

            $eventoCorrelato = $this->eventoProximo($telemetria, $em->getTimestamp() - $startedAt->getTimestamp());
            if (null !== $eventoCorrelato) {
                $destaques[] = sprintf('A telemetria da aeronave registrou, neste mesmo período: %s.', $eventoCorrelato);
            }
        }

        if (($resumo['total'] ?? 0) > 0) {
            $destaques[] = sprintf('%d amostras ambiente coletadas ao longo do voo.', $resumo['total']);
        }

        return $destaques;
    }

    /**
     * @param list<array<string, mixed>> $amostras
     *
     * @return array<string, mixed>|null
     */
    private function amostraMaisCritica(array $amostras): ?array
    {
        $ordem = ['calmo' => 0, 'moderado' => 1, 'severo' => 2];
        $pior = null;
        foreach ($amostras as $a) {
            if (null === $pior || ($ordem[$a['severidade']] ?? 0) > ($ordem[$pior['severidade']] ?? 0)) {
                $pior = $a;
            }
        }

        return $pior;
    }

    /**
     * Procura, no array `events` de `Voo::$dados['telemetria']` (produzido
     * por `TelemetryDeriver::deriveEvents()` — listas posicionais
     * `[t_s, type, título, valor, severidade]`, não associativas), um
     * evento de severidade relevante (`warn`/`bad`) dentro de uma janela
     * de ±5 min do instante informado — melhor esforço, `null` quando não
     * há telemetria ou nada correlato.
     *
     * @param array<string, mixed>|null $telemetria
     */
    private function eventoProximo(?array $telemetria, int $offsetSegundos): ?string
    {
        $eventos = $telemetria['events'] ?? null;
        if (!\is_array($eventos)) {
            return null;
        }

        $janela = 5 * 60;
        foreach ($eventos as $e) {
            if (!\is_array($e) || \count($e) < 5) {
                continue;
            }
            [$t, , $titulo, $valor, $severidade] = $e;
            if (!\in_array($severidade, ['warn', 'bad'], true)) {
                continue;
            }
            if (abs(((int) $t) - $offsetSegundos) <= $janela) {
                return '' !== (string) $valor ? sprintf('%s (%s)', $titulo, $valor) : (string) $titulo;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $resumo
     *
     * @return list<array{label: string, valor: string}>
     */
    private function estatisticas(array $resumo, int $totalAmostras, int $totalCapturas, int $tempoMin): array
    {
        $linhas = [
            ['label' => 'Amostras ambiente', 'valor' => (string) $totalAmostras],
            ['label' => 'Capturas de mapa/vento', 'valor' => (string) $totalCapturas],
            ['label' => 'Duração do voo', 'valor' => sprintf('%dh%02d', intdiv($tempoMin, 60), $tempoMin % 60)],
            ['label' => 'Pior severidade observada', 'valor' => ucfirst((string) $resumo['piorSeveridade'])],
        ];
        if (null !== ($resumo['ventoMaxKt'] ?? null)) {
            $linhas[] = ['label' => 'Vento máximo', 'valor' => sprintf('%.0f kt', $resumo['ventoMaxKt'])];
        }
        if (null !== ($resumo['rajadaMaxKt'] ?? null)) {
            $linhas[] = ['label' => 'Rajada máxima', 'valor' => sprintf('%.0f kt', $resumo['rajadaMaxKt'])];
        }
        if (null !== ($resumo['precipMaxMmH'] ?? null)) {
            $linhas[] = ['label' => 'Precipitação máxima', 'valor' => sprintf('%.1f mm/h', $resumo['precipMaxMmH'])];
        }
        if (null !== ($resumo['tempMinC'] ?? null) && null !== ($resumo['tempMaxC'] ?? null)) {
            $linhas[] = ['label' => 'Faixa de temperatura', 'valor' => sprintf('%.0f°C a %.0f°C', $resumo['tempMinC'], $resumo['tempMaxC'])];
        }

        return $linhas;
    }
}
