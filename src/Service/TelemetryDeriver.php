<?php

namespace App\Service;

/**
 * Deriva o blob `telemetria` (mesmo formato que `flights.json` sempre
 * usou — `track`/`prof`/`env`/`events`/`phases`/`parcels`/`score`/`dur`/
 * .../`td`/`orig`/`dest`, consumido por `voo.js` sem mudar uma linha)
 * a partir da gravação crua que `katabatic_capture.py --record` produz:
 * amostras de cinemática a 1 Hz, ambiente a 0,1 Hz e eventos por
 * mudança (ver `docs/payload-telemetria-acars.md`, seção 2).
 *
 * **Por que isso precisou existir.** O script de captura é um
 * instrumento de medição, não o cliente ACARS completo do contrato
 * v1.1 (esse ainda não existe — sessão aberta/fechada, streaming em
 * lotes, fila SQLite, gzip, GRIB, METAR, VATSIM, tudo isso continua
 * fora do escopo desta fatia). Ele só grava CSV cru em disco; nada do
 * que `flights.json` sempre teve pronto (`score`, `phases`, `parcels`,
 * `dist`...) vem de lá. Esta classe é o "servidor deriva" da seção 7
 * do contrato, na versão mínima que dá pra escrever sem um voo real
 * pra calibrar contra.
 *
 * **v1, documentadamente aproximado** (revisar quando houver mais
 * voos reais e as peças que faltam no contrato existirem):
 *
 * - Fases de voo (`phases`) são um corte simples por `vs_fpm`/
 *   `on_ground`, sem suavização sofisticada.
 * - Índice de dificuldade (`score`/`parcels`) usa referências de
 *   escala arbitrárias (não calibradas contra frota real) — turbulência
 *   e G em particular dependem de uma linha de base por aeronave que o
 *   contrato já sinaliza como pendente ("existe piso de ruído por
 *   aeronave").
 * - Través (`windc`) é aproximado pelo heading da aeronave no
 *   toque (ou o último heading registrado), não pelo heading de pista
 *   de verdade — falta base de aeroportos com heading de pista (item
 *   já pendente no roadmap do projeto).
 * - Sem METAR: teto/visibilidade não são buscados aqui (ver
 *   `AcarsIngestaoController`, que deixa esse campo do Logbook como
 *   `null`).
 *
 * Deliberadamente sem nenhuma dependência de Doctrine/Symfony — só
 * arrays entra, array sai. Isso deixa testar isolado (`php -r` com um
 * payload sintético) sem precisar do kernel nem do banco.
 */
class TelemetryDeriver
{
    private const PHASE_MIN_SEGMENT_S = 20;
    private const CLIMB_VS_THRESHOLD = 300;
    private const DESCENT_VS_THRESHOLD = -300;

    /** Mesmo limiar que `voo.js` já usa pra classificar um toque como "Duro". */
    private const HARD_LANDING_FPM = 450;

    private const STATE_FIELD_LABELS = [
        'gear' => 'Trem',
        'flaps' => 'Flaps',
        'spoilers' => 'Spoilers',
        'parking_brake' => 'Freio de estacionamento',
        'eng1' => 'Motor 1',
        'eng2' => 'Motor 2',
        'light_landing' => 'Luz de pouso',
        'stall_warning' => 'Alerta de estol',
        'overspeed' => 'Overspeed',
        'crash' => 'Colisão',
    ];

    /** Campos onde "para" verdadeiro/diferente de zero é uma excedência de verdade. */
    private const ALARM_FIELDS = ['stall_warning', 'overspeed', 'crash'];

    /**
     * @param array<string, mixed> $payload payload cru enviado pelo script (ver AcarsIngestaoController)
     * @param float|null           $limiteG limite de fator de carga cadastrado pra essa aeronave (App\Entity\Aeronave::getLimiteG()) — usado só pra sinalizar excedência "overG", opcional
     *
     * @return array{dur: int, dificuldade: int, telemetria: array<string, mixed>}
     */
    public function derive(array $payload, ?float $limiteG = null): array
    {
        $startedAt = new \DateTimeImmutable($payload['started_at']);

        $samples = $this->withOffsets($payload['samples'] ?? [], $startedAt);
        $env = $this->withOffsets($payload['env'] ?? [], $startedAt);
        $events = $this->withOffsets($payload['events'] ?? [], $startedAt);

        usort($samples, static fn (array $a, array $b) => $a['t_s'] <=> $b['t_s']);
        usort($env, static fn (array $a, array $b) => $a['t_s'] <=> $b['t_s']);
        usort($events, static fn (array $a, array $b) => $a['t_s'] <=> $b['t_s']);

        $dur = (int) ($payload['duration_s'] ?? ($samples ? end($samples)['t_s'] : 0));

        $track = array_map(
            static fn (array $s) => [$s['t_s'], self::num($s['lat']), self::num($s['lon'])],
            $samples
        );
        $prof = array_map(
            static fn (array $s) => [
                $s['t_s'],
                self::num($s['alt_ft']),
                self::num($s['ias_kt']),
                self::num($s['vs_fpm']),
                self::num($s['g_max']),
                self::num($s['acc_y_rms']),
                self::num($s['gs_kt']),
                (bool) ($s['on_ground'] ?? false),
                self::num($s['tas_kt']),
            ],
            $samples
        );
        $envArr = array_map(
            static fn (array $e) => [
                $e['t_s'],
                self::num($e['oat_c']),
                self::num($e['precip_rate']),
                (bool) ($e['in_cloud'] ?? false),
                self::num($e['ice_pct']),
                self::num($e['wind_kt']),
                self::num($e['wind_dir']),
                self::num($e['fuel_lb']),
            ],
            $env
        );

        [$groundS, $airS] = $this->groundAirSeconds($samples);
        $altMax = self::maxOf($samples, 'alt_ft') ?? 0.0;
        $iasMax = self::maxOf($samples, 'ias_kt') ?? 0.0;
        $gsAvg = self::avgOf($samples, 'gs_kt') ?? 0.0;
        $vsMax = self::maxOf($samples, 'vs_fpm') ?? 0.0;
        $vsMin = self::minOf($samples, 'vs_fpm') ?? 0.0;
        $gmax = self::maxOf($samples, 'g_max') ?? 1.0;
        $gmin = self::minOf($samples, 'g_min') ?? 1.0;
        $accYRmsMax = self::maxOf($samples, 'acc_y_rms') ?? 0.0;
        $dist = $this->trackDistanceNm($track);

        $oatMin = self::minOf($env, 'oat_c') ?? 0.0;
        $oatMax = self::maxOf($env, 'oat_c') ?? 0.0;
        $iceMax = self::maxOf($env, 'ice_pct') ?? 0.0;
        $windMax = self::maxOf($env, 'wind_kt') ?? 0.0;
        $precipMax = self::maxOf($env, 'precip_rate') ?? 0.0;
        $cloudS = $this->cloudSeconds($env);
        $imc = $dur > 0 ? (int) round($cloudS / $dur * 100) : 0;
        $fuel = $this->fuelUsed($payload, $env);

        $phases = $this->derivePhases($samples, $dur);
        [$evList, $exceed, $bounces, $td] = $this->deriveEvents($events, $limiteG, $gmax);

        $wx = $this->classifyWeather($oatMin, $precipMax);
        $windc = $this->crosswindComponent($samples, $env, $td);

        [$score, $parcels] = $this->difficultyScore($accYRmsMax, $gmax, $gmin, $iceMax, $precipMax, $windc, $td);

        $telemetria = [
            'id' => $payload['codigo'],
            'label' => substr($payload['started_at'], 11, 5).'Z · '.$wx,
            'wx' => $wx,
            'start' => $payload['started_at'],
            'track' => $track,
            'prof' => $prof,
            'env' => $envArr,
            'events' => $evList,
            'phases' => $phases,
            'parcels' => $parcels,
            'score' => $score,
            'dur' => $dur,
            'air_s' => $airS,
            'ground_s' => $groundS,
            'alt_max' => $altMax,
            'ias_max' => $iasMax,
            'gs_avg' => $gsAvg,
            'gmax' => $gmax,
            'gmin' => $gmin,
            'dist' => round($dist, 1),
            'vs_max' => $vsMax,
            'vs_min' => $vsMin,
            'fuel' => round($fuel, 1),
            'imc' => $imc,
            'cloud_s' => $cloudS,
            'oat_min' => $oatMin,
            'oat_max' => $oatMax,
            'ice' => $iceMax,
            'wind_max' => $windMax,
            'precip_max' => $precipMax,
            'windc' => round($windc),
            'exceed' => $exceed,
            'bounces' => $bounces,
            'td' => $td,
            'orig' => $payload['origem'],
            'dest' => $payload['destino'],
        ];

        return [
            'dur' => $dur,
            'dificuldade' => $score,
            'telemetria' => $telemetria,
        ];
    }

    /**
     * Converte o `t` ISO 8601 de cada linha num offset em segundos
     * desde `started_at` (`t_s`) — é o que `track`/`prof`/`env`/`events`
     * usam como primeiro elemento de cada tupla, igual `flights.json`
     * sempre usou.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withOffsets(array $rows, \DateTimeImmutable $startedAt): array
    {
        $base = $startedAt->getTimestamp();
        $out = [];
        foreach ($rows as $row) {
            if (!isset($row['t'])) {
                continue;
            }
            try {
                $t = new \DateTimeImmutable((string) $row['t']);
            } catch (\Exception) {
                continue;
            }
            $row['t_s'] = max(0, $t->getTimestamp() - $base);
            $out[] = $row;
        }

        return $out;
    }

    private static function num(mixed $v): float|int|null
    {
        if (null === $v || '' === $v) {
            return null;
        }
        if (is_bool($v)) {
            return null;
        }

        return is_numeric($v) ? $v + 0 : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function maxOf(array $rows, string $field): ?float
    {
        $vals = array_filter(array_map(static fn ($r) => self::num($r[$field] ?? null), $rows), static fn ($v) => null !== $v);

        return $vals ? (float) max($vals) : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function minOf(array $rows, string $field): ?float
    {
        $vals = array_filter(array_map(static fn ($r) => self::num($r[$field] ?? null), $rows), static fn ($v) => null !== $v);

        return $vals ? (float) min($vals) : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function avgOf(array $rows, string $field): ?float
    {
        $vals = array_filter(array_map(static fn ($r) => self::num($r[$field] ?? null), $rows), static fn ($v) => null !== $v);

        return $vals ? array_sum($vals) / count($vals) : null;
    }

    /**
     * Amostras de cinemática são ~1 Hz (grupo A do contrato), então
     * contar amostras com `on_ground` true/false já dá segundos direto,
     * sem precisar integrar intervalo a intervalo.
     *
     * @param list<array<string, mixed>> $samples
     *
     * @return array{0: int, 1: int} [ground_s, air_s]
     */
    private function groundAirSeconds(array $samples): array
    {
        $ground = 0;
        $air = 0;
        foreach ($samples as $s) {
            if ($s['on_ground'] ?? false) {
                ++$ground;
            } else {
                ++$air;
            }
        }

        return [$ground, $air];
    }

    /**
     * Ambiente é amostrado a cada ~10 s (grupo C) — cada amostra com
     * `in_cloud` verdadeiro conta como 10 s em nuvem. Aproximação
     * documentada: assume intervalo constante em vez de medir o
     * intervalo real entre amostras consecutivas.
     *
     * @param list<array<string, mixed>> $env
     */
    private function cloudSeconds(array $env): int
    {
        $n = 0;
        foreach ($env as $e) {
            if ($e['in_cloud'] ?? false) {
                ++$n;
            }
        }

        return $n * 10;
    }

    /**
     * Distância percorrida somando a distância haversine entre pontos
     * consecutivos do `track`, em milhas náuticas.
     *
     * @param list<array{0: int, 1: float|null, 2: float|null}> $track
     */
    private function trackDistanceNm(array $track): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($track); ++$i) {
            [, $lat1, $lon1] = $track[$i - 1];
            [, $lat2, $lon2] = $track[$i];
            if (null === $lat1 || null === $lon1 || null === $lat2 || null === $lon2) {
                continue;
            }
            $total += $this->haversineNm((float) $lat1, (float) $lon1, (float) $lat2, (float) $lon2);
        }

        return $total;
    }

    private function haversineNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rNm = 3440.065; // raio da Terra em milhas náuticas
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $rNm * asin(min(1.0, sqrt($a)));
    }

    private function fuelUsed(array $payload, array $env): float
    {
        $start = self::num($payload['ident']['fuel_lb'] ?? null);
        if (null === $start && $env) {
            $start = self::num($env[0]['fuel_lb'] ?? null);
        }
        $end = $env ? self::num(end($env)['fuel_lb'] ?? null) : null;
        if (null === $start || null === $end) {
            return 0.0;
        }

        return max(0.0, $start - $end);
    }

    /**
     * Corte simples de fase por `vs_fpm`/`on_ground` — solo enquanto
     * `on_ground`, subida/descida acima de ±300 fpm, cruzeiro no meio.
     * Segmentos curtos (<20 s, ruído de transição) somam no vizinho
     * anterior em vez de virar um bloco minúsculo na barra de fases.
     *
     * @param list<array<string, mixed>> $samples
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function derivePhases(array $samples, int $dur): array
    {
        if (!$samples) {
            return [['cruzeiro', 0, max(0, $dur)]];
        }

        $raw = [];
        foreach ($samples as $s) {
            $phase = ($s['on_ground'] ?? false)
                ? 'solo'
                : (($s['vs_fpm'] ?? 0) >= self::CLIMB_VS_THRESHOLD
                    ? 'subida'
                    : (($s['vs_fpm'] ?? 0) <= self::DESCENT_VS_THRESHOLD ? 'descida' : 'cruzeiro'));
            $raw[] = [$phase, $s['t_s']];
        }

        $segments = [];
        $curPhase = $raw[0][0];
        $curStart = $raw[0][1];
        for ($i = 1; $i < count($raw); ++$i) {
            [$phase, $t] = $raw[$i];
            if ($phase !== $curPhase) {
                $segments[] = [$curPhase, $curStart, $raw[$i - 1][1]];
                $curPhase = $phase;
                $curStart = $t;
            }
        }
        $segments[] = [$curPhase, $curStart, end($raw)[1]];

        // funde segmentos curtos no anterior (ou no seguinte, se for o primeiro)
        $merged = [];
        foreach ($segments as $seg) {
            [$phase, $start, $end] = $seg;
            if (($end - $start) < self::PHASE_MIN_SEGMENT_S && $merged) {
                $lastIdx = count($merged) - 1;
                $merged[$lastIdx][2] = $end;
            } else {
                $merged[] = $seg;
            }
        }

        return $merged ?: [['cruzeiro', 0, max(0, $dur)]];
    }

    /**
     * Traduz eventos crus (já quase prontos do script — touchdown,
     * bounce, takeoff, icing_onset, state_*) pro formato
     * `[t, tipo, rótulo, extra, severidade]` que `voo.js` espera, e
     * conta excedências/quiques/toque no processo.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return array{0: list<array{0:int,1:string,2:string,3:string,4:string}>, 1: int, 2: int, 3: array<string,mixed>|null}
     */
    private function deriveEvents(array $events, ?float $limiteG, float $gmax): array
    {
        $out = [];
        $exceed = 0;
        $bounces = 0;
        $td = null;

        foreach ($events as $e) {
            $type = (string) ($e['type'] ?? '');
            $data = is_array($e['data'] ?? null) ? $e['data'] : [];
            $t = $e['t_s'];

            if ('session_start' === $type || 'session_end' === $type) {
                $out[] = [$t, $type, 'session_start' === $type ? 'Início da sessão' : 'Fim da sessão', '', ''];
                continue;
            }

            if ('touchdown' === $type) {
                $vs = self::num($data['td_vs_fpm'] ?? null);
                if (null !== $vs) {
                    $vs = abs($vs);
                    $td = [
                        'vs' => $vs,
                        'pitch' => self::num($data['td_pitch'] ?? null) ?? 0.0,
                        'bank' => self::num($data['td_bank'] ?? null) ?? 0.0,
                        'hdg' => self::num($data['td_hdg'] ?? null) ?? 0.0,
                        'lat' => self::num($data['td_lat'] ?? null) ?? 0.0,
                        'lon' => self::num($data['td_lon'] ?? null) ?? 0.0,
                    ];
                    $sev = $vs >= self::HARD_LANDING_FPM ? 'bad' : ($vs >= 300 ? 'warn' : 'ok');
                    $out[] = [$t, $type, 'Toque no solo', round($vs).' fpm', $sev];
                    if ($vs >= self::HARD_LANDING_FPM) {
                        ++$exceed;
                    }
                }
                continue;
            }

            if ('bounce' === $type) {
                ++$bounces;
                $out[] = [$t, $type, 'Quique', '', 'warn'];
                continue;
            }

            if ('takeoff' === $type) {
                $out[] = [$t, $type, 'Decolagem', '', 'info'];
                continue;
            }

            if ('icing_onset' === $type) {
                $ice = self::num($data['ice_pct'] ?? null);
                $out[] = [$t, $type, 'Início de gelo', null !== $ice ? $ice.'%' : '', 'warn'];
                continue;
            }

            if ('sim_rate_change' === $type || 'slew_detected' === $type) {
                $out[] = [$t, $type, 'sim_rate_change' === $type ? 'Taxa de simulação alterada' : 'Slew detectado', '', 'bad'];
                continue;
            }

            if (str_starts_with($type, 'state_')) {
                $field = substr($type, 6);
                $label = self::STATE_FIELD_LABELS[$field] ?? ucfirst($field);
                $novo = $data['para'] ?? null;
                $isAlarm = in_array($field, self::ALARM_FIELDS, true);
                $ativo = is_numeric($novo) ? ((float) $novo > 0) : (bool) $novo;
                $sev = $isAlarm ? ($ativo ? 'bad' : 'ok') : 'info';
                $extra = $isAlarm ? ($ativo ? 'ativado' : 'normalizado') : ('→ '.(is_scalar($novo) ? (string) $novo : ''));
                $out[] = [$t, $type, $label, $extra, $sev];
                if ($isAlarm && $ativo) {
                    ++$exceed;
                }
                continue;
            }

            // tipo desconhecido (script novo com evento que esta versão não
            // conhece ainda) - não descarta, só entra sem rótulo bonito, pra
            // não perder o dado.
            $out[] = [$t, $type, ucfirst(str_replace('_', ' ', $type)), '', 'info'];
        }

        if (null !== $limiteG && $gmax > $limiteG) {
            ++$exceed;
        }

        return [$out, $exceed, $bounces, $td];
    }

    private function classifyWeather(float $oatMin, float $precipMax): string
    {
        if ($precipMax > 0.05 && $oatMin <= 0.0) {
            return 'Neve';
        }
        if ($precipMax > 0.05) {
            return 'Chuva';
        }

        return 'Claro';
    }

    /**
     * Aproximação: componente de vento de través projetada contra o
     * heading no toque (ou o último heading conhecido, se não houve
     * toque) — não é alinhada à pista de verdade, ver docblock da
     * classe.
     *
     * @param list<array<string, mixed>> $samples
     * @param list<array<string, mixed>> $env
     */
    private function crosswindComponent(array $samples, array $env, ?array $td): float
    {
        $heading = null;
        if ($td) {
            $heading = $td['hdg'];
        } elseif ($samples) {
            $heading = self::num(end($samples)['hdg_true'] ?? null);
        }
        if (null === $heading || !$env) {
            return 0.0;
        }

        $lastEnv = end($env);
        $windDir = self::num($lastEnv['wind_dir'] ?? null);
        $windKt = self::num($lastEnv['wind_kt'] ?? null);
        if (null === $windDir || null === $windKt) {
            return 0.0;
        }

        $diff = deg2rad(((float) $windDir) - ((float) $heading));

        return ((float) $windKt) * sin($diff);
    }

    /**
     * Fórmula v1 do índice de dificuldade — pesos e referências de
     * escala arbitrários, ver docblock da classe pra tradeoff.
     *
     * @return array{0: int, 1: list<array{0:string,1:int,2:string}>}
     */
    private function difficultyScore(float $accYRmsMax, float $gmax, float $gmin, float $iceMax, float $precipMax, float $windc, ?array $td): array
    {
        $turb = min(100, (int) round($accYRmsMax / 5.0 * 100));
        $gLoad = min(100, (int) round(((($gmax - 1.0) + (1.0 - $gmin)) / 1.5) * 100));
        $ice = min(100, (int) round($iceMax * 4));
        $precip = min(100, (int) round($precipMax / 30 * 100));
        $through = min(100, (int) round(abs($windc) / 25 * 100));

        $parcels = [
            ['Turbulência', max(0, $turb), 'desvio-padrão da aceleração vertical, pico da perna'],
            ['Fator de carga', max(0, $gLoad), sprintf('G entre %.2f e %.2f', $gmin, $gmax)],
            ['Gelo', max(0, $ice), sprintf('%.1f%% de gelo estrutural no pico', $iceMax)],
            ['Precipitação', max(0, $precip), sprintf('%.1f mm/h no pico', $precipMax)],
            ['Través', max(0, $through), sprintf('~%.0f kt de través (aproximado)', abs($windc))],
        ];

        $weights = ['Turbulência' => .25, 'Fator de carga' => .2, 'Gelo' => .15, 'Precipitação' => .15, 'Través' => .15];
        if ($td) {
            $landing = min(100, (int) round($td['vs'] / 700 * 100));
            $parcels[] = ['Pouso', max(0, $landing), sprintf('%.0f fpm de razão de toque', $td['vs'])];
            $weights['Pouso'] = .1;
        }

        $totalWeight = array_sum($weights);
        $score = 0.0;
        foreach ($parcels as $p) {
            $score += $p[1] * ($weights[$p[0]] / $totalWeight);
        }

        return [max(0, min(100, (int) round($score))), $parcels];
    }
}
