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
 * - Amostras com slew ativo ou `sim_rate` fora de ~1× são excluídas
 *   das estatísticas agregadas (`dist`/`score`/min-max-avg/fases/
 *   través — ver `trustedSamples()`), implementando o princípio #3 do
 *   contrato ("ninguém confia no cliente"): sem isso, um piloto que
 *   acelera o sim ou usa slew conseguiria distância/velocidade/G
 *   fisicamente impossíveis inflando (ou zerando) o índice de
 *   dificuldade. `track`/`prof` (o que o mapa/gráfico do relatório
 *   mostra) continuam com a gravação inteira, sem esse filtro — a
 *   ideia é não confiar nos números derivados, não esconder o que
 *   aconteceu no voo.
 *
 * **Atualizado: escala de `ice_pct` corrigida na fonte.** Esta classe
 * nunca precisou mudar — o bug estava em `katabatic_capture.py`, que
 * pedia `STRUCTURAL ICE PCT` na unidade `percent over 100` (fração
 * 0.0–1.0 pra SimConnect, confirmado contra a documentação oficial do
 * SDK) enquanto todo o resto daqui (`icing_onset` a partir de
 * `ice >= 1.0`, peso `iceMax * 4` no índice de dificuldade,
 * `sprintf('%.1f%%', ...)`) sempre assumiu um valor já 0-100. Voos
 * gravados antes da correção reportam gelo estrutural ~100× menor do
 * que o simulador de fato modelou — ver `docs/payload-telemetria-acars.md`,
 * seção 8, e o comentário em `GROUP_C` no script de captura.
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
        'deice_estrutural' => 'Deice estrutural',
        'deice_parabrisa' => 'Deice do para-brisa',
    ];

    /** Campos onde "para" verdadeiro/diferente de zero é uma excedência de verdade. */
    private const ALARM_FIELDS = ['stall_warning', 'overspeed', 'crash'];

    /**
     * `SURFACE TYPE` (SimConnect) — corroborado por um fórum técnico
     * independente (fsdeveloper.com) além do conhecimento geral do SDK,
     * ver conversa que motivou esta refinamento. Mesma lista que o
     * comentário de debug de `katabatic_capture.py` usa.
     */
    private const SURFACE_TYPES = [
        0 => 'Concreto', 1 => 'Grama', 2 => 'Água', 3 => 'Grama irregular', 4 => 'Asfalto',
        5 => 'Grama curta', 6 => 'Grama alta', 7 => 'Turfa dura', 8 => 'Neve', 9 => 'Gelo',
        10 => 'Urbano', 11 => 'Floresta', 12 => 'Terra', 13 => 'Coral', 14 => 'Cascalho',
        15 => 'Tratada com óleo', 16 => 'Placas de aço', 17 => 'Betuminosa', 18 => 'Tijolo',
        19 => 'Macadame', 20 => 'Tábuas', 21 => 'Areia', 22 => 'Xisto', 23 => 'Tarmac',
    ];

    /**
     * `SURFACE CONDITION` (SimConnect) — **não reverificado
     * independentemente nesta sessão** contra a documentação oficial do
     * SDK (três tentativas diretas falharam: um 404, um resumo que não
     * trazia a resposta, um timeout de permissão num fonte promissor).
     * Vem do conhecimento geral de SimConnect e é corroborado pelo
     * próprio comentário de debug de `katabatic_capture.py`
     * ("surface_cond 0=normal 1=molhada 2=gelo 3=neve"), mas sem a
     * mesma confirmação cruzada que `SURFACE_TYPES` teve. Revisar se
     * aparecer um pouso com condição de superfície visivelmente errada
     * (ex.: "molhada" num METAR sem precipitação nenhuma).
     */
    private const SURFACE_CONDITIONS = [
        0 => 'Normal', 1 => 'Molhada', 2 => 'Gelo', 3 => 'Neve',
    ];

    /**
     * @param array<string, mixed> $payload         payload cru enviado pelo script (ver AcarsIngestaoController)
     * @param float|null           $limiteG         limite de fator de carga POSITIVO cadastrado pra essa aeronave (App\Entity\Aeronave::getLimiteG()) — usado só pra sinalizar excedência "overG", opcional
     * @param float|null           $limiteGNegativo limite de fator de carga NEGATIVO cadastrado (App\Entity\Aeronave::getLimiteGNegativo()) — mesma ideia do lado negativo, opcional
     *
     * @return array{dur: int, dificuldade: int, telemetria: array<string, mixed>}
     */
    public function derive(array $payload, ?float $limiteG = null, ?float $limiteGNegativo = null): array
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

        // Estatísticas agregadas usam só o subconjunto confiável (sem
        // slew/sim_rate anormal) — ver `trustedSamples()` e docblock da
        // classe. `track`/`prof` acima, pro mapa/gráfico, ficam com
        // `$samples` inteiro de propósito.
        $trusted = $this->trustedSamples($samples);
        $trustedTrack = array_map(
            static fn (array $s) => [$s['t_s'], self::num($s['lat']), self::num($s['lon'])],
            $trusted
        );

        [$groundS, $airS] = $this->groundAirSeconds($trusted);
        $altMax = self::maxOf($trusted, 'alt_ft') ?? 0.0;
        $iasMax = self::maxOf($trusted, 'ias_kt') ?? 0.0;
        $gsAvg = self::avgOf($trusted, 'gs_kt') ?? 0.0;
        $vsMax = self::maxOf($trusted, 'vs_fpm') ?? 0.0;
        $vsMin = self::minOf($trusted, 'vs_fpm') ?? 0.0;
        $gmax = self::maxOf($trusted, 'g_max') ?? 1.0;
        $gmin = self::minOf($trusted, 'g_min') ?? 1.0;
        $accYRmsMax = self::maxOf($trusted, 'acc_y_rms') ?? 0.0;
        $dist = $this->trackDistanceNm($trustedTrack);

        $oatMin = self::minOf($env, 'oat_c') ?? 0.0;
        $oatMax = self::maxOf($env, 'oat_c') ?? 0.0;
        $iceMax = self::maxOf($env, 'ice_pct') ?? 0.0;
        $windMax = self::maxOf($env, 'wind_kt') ?? 0.0;
        $precipMax = self::maxOf($env, 'precip_rate') ?? 0.0;
        $cloudS = $this->cloudSeconds($env);
        $imc = $dur > 0 ? (int) round($cloudS / $dur * 100) : 0;
        $fuel = $this->fuelUsed($payload, $env);

        $phases = $this->derivePhases($trusted, $dur);
        [$evList, $exceed, $bounces, $td] = $this->deriveEvents($events, $limiteG, $gmax, $limiteGNegativo, $gmin);

        // Superfície de pouso: não vem no evento `touchdown` em si (o
        // script só lê `surface_type`/`surface_cond` no grupo C, 0,1 Hz
        // — ver docblock de SURFACE_TYPES/SURFACE_CONDITIONS), então
        // pega a amostra de ambiente mais próxima no tempo do toque.
        // `$env` aqui já está com offsets/ordenado (acima), antes do
        // `array_map` que produz `$envArr` (que não carrega esses dois
        // campos) — por isso usa este, não aquele.
        if (null !== $td) {
            $td = $this->attachSurfaceAtTouchdown($td, $env);
        }

        $wx = $this->classifyWeather($oatMin, $precipMax);
        $windc = $this->crosswindComponent($trusted, $env, $td);

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

    /**
     * Limiar de desvio de `sim_rate` em relação a 1× que ainda conta
     * como "voo normal" — mesma folga que `katabatic_capture.py` usa
     * (0,01) seria sensível demais aqui (dispararia por ruído de
     * ponto flutuante numa leitura só); 0,05 deixa passar oscilação
     * pequena e ainda pega qualquer aceleração de sim perceptível.
     */
    private const SIM_RATE_TOLERANCE = 0.05;

    /**
     * Subconjunto de `$samples` sem slew ativo e sem `sim_rate` fora de
     * ~1× — usado só pras estatísticas agregadas (`dist`, min/max/avg,
     * fases, través, e por consequência `score`/`dificuldade`), nunca
     * pro `track`/`prof` que o relatório mostra (esses continuam com a
     * gravação inteira — ver docblock da classe).
     *
     * Se filtrar tudo (voo inteiro com sim rate alterado, por exemplo),
     * cai de volta pro conjunto sem filtro: estatística aproximada é
     * melhor que nenhuma, e um voo 100% fora do normal já fica visível
     * pelos eventos `sim_rate_change`/`slew_detected` na timeline.
     *
     * @param list<array<string, mixed>> $samples
     *
     * @return list<array<string, mixed>>
     */
    private function trustedSamples(array $samples): array
    {
        $trusted = array_values(array_filter($samples, static function (array $s): bool {
            if (true === ($s['slew'] ?? false)) {
                return false;
            }
            $rate = self::num($s['sim_rate'] ?? null);
            if (null !== $rate && abs(((float) $rate) - 1.0) > self::SIM_RATE_TOLERANCE) {
                return false;
            }

            return true;
        }));

        return $trusted ?: $samples;
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
    private function deriveEvents(array $events, ?float $limiteG, float $gmax, ?float $limiteGNegativo = null, float $gmin = 1.0): array
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
                        't_s' => $t,
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
        // Espelha a checagem acima pro lado negativo — `$limiteGNegativo`
        // é `App\Entity\Aeronave::$limiteGNegativo`, cadastrado só na
        // criação da aeronave (sem rota de edição ainda, ver docblock da
        // entidade), `null` até alguém preencher. Sem evento próprio na
        // timeline (diferente de overG, que também não tem — os dois só
        // incrementam `exceed`, o "Fator de carga" na composição do
        // índice já usa `$gmin` de verdade, ver `difficultyScore()`).
        if (null !== $limiteGNegativo && $gmin < $limiteGNegativo) {
            ++$exceed;
        }

        return [$out, $exceed, $bounces, $td];
    }

    /**
     * Acrescenta `surface_type`/`surface_cond` (crus, enum do
     * SimConnect) e seus rótulos em PT ao `$td` já montado, usando a
     * amostra de ambiente (`$env`, grupo C, ~0,1 Hz) mais próxima no
     * tempo do toque — não existe amostra de ambiente exatamente no
     * instante do toque, então "mais próxima" é a melhor aproximação
     * disponível (ver docblock de SURFACE_TYPES/SURFACE_CONDITIONS pra
     * proveniência dos mapeamentos).
     *
     * @param array<string, mixed>       $td
     * @param list<array<string, mixed>> $env
     *
     * @return array<string, mixed>
     */
    private function attachSurfaceAtTouchdown(array $td, array $env): array
    {
        $sample = $this->nearestByTime($env, (int) $td['t_s']);
        $tipo = null !== $sample ? self::num($sample['surface_type'] ?? null) : null;
        $cond = null !== $sample ? self::num($sample['surface_cond'] ?? null) : null;

        $td['surface_type'] = null !== $tipo ? (int) $tipo : null;
        $td['surface_type_label'] = null !== $tipo ? (self::SURFACE_TYPES[(int) $tipo] ?? null) : null;
        $td['surface_cond'] = null !== $cond ? (int) $cond : null;
        $td['surface_cond_label'] = null !== $cond ? (self::SURFACE_CONDITIONS[(int) $cond] ?? null) : null;

        return $td;
    }

    /**
     * Amostra de `$rows` (precisa de `t_s`) com o `t_s` mais próximo de
     * `$tS` — usado pra achar a leitura de ambiente mais perto do
     * instante do toque (`attachSurfaceAtTouchdown()`).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private function nearestByTime(array $rows, int $tS): ?array
    {
        $best = null;
        $bestDiff = null;
        foreach ($rows as $row) {
            $diff = abs((int) ($row['t_s'] ?? 0) - $tS);
            if (null === $bestDiff || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $row;
            }
        }

        return $best;
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
     * Recalcula o través (windc) usando um heading de PISTA real
     * (magnético, `Aeroporto::$pistaPrincipalHeadingMag`) em vez da
     * aproximação padrão de `crosswindComponent()` (heading da aeronave
     * no toque, ou o último heading conhecido) — chamado de
     * `AcarsIngestaoController::ingerir()` só quando o aeroporto de
     * pouso real do voo tem essa informação cadastrada.
     *
     * Reaproveita a mesma fórmula, mas a partir do `env` JÁ derivado
     * dentro de `$telemetria` (última amostra) em vez de reprocessar o
     * payload cru — quem chama não precisa guardar `$env` à parte só
     * pra este recálculo pontual, feito depois de `derive()` já ter
     * rodado (a resolução do aeroporto de pouso real depende da
     * telemetria já derivada, ver `AcarsIngestaoController::pousoRealIcao()`).
     *
     * **Não corrige variação magnética** entre o heading magnético
     * cadastrado e `wind_dir`, que o simulador manda em graus
     * VERDADEIROS — ver docblock de `Aeroporto::$pistaPrincipalHeadingMag`.
     *
     * @param array<string, mixed> $telemetria mesmo array que `derive()` devolve em `telemetria`
     *
     * @return float|null `null` quando não há amostra de ambiente nenhuma pra calcular (voo sem env, ou tempo de gravação curto demais)
     */
    public function recomputeWindcComHeadingDePista(array $telemetria, float $pistaHeadingMagDeg): ?float
    {
        $env = $telemetria['env'] ?? [];
        if (!is_array($env) || [] === $env) {
            return null;
        }

        $ultimo = end($env);
        $windKt = self::num($ultimo[5] ?? null);
        $windDir = self::num($ultimo[6] ?? null);
        if (null === $windKt || null === $windDir) {
            return null;
        }

        $diff = deg2rad(((float) $windDir) - $pistaHeadingMagDeg);

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
