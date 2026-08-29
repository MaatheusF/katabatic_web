<?php

namespace App\Service;

/**
 * Busca condições ambiente ao vivo (vento/rajada/temperatura/pressão/
 * precipitação) num ponto lat/lon, direto da Open-Meteo (API pública,
 * sem chave — uso não-comercial; ver https://open-meteo.com/en/pricing:
 * 10 000 chamadas/dia, 5 000/hora, 600/min no plano gratuito, folgado
 * pra cadência de minutos desta captura).
 *
 * Alimenta `App\Service\PesquisaAmbienteCaptador` — a amostra numérica
 * "de tanto em tanto tempo" da camada de pesquisa meteorológica (não
 * confundir com o mapa/vento por altitude, que é uma captura à parte,
 * mais pesada, ver `App\Entity\PesquisaAmostra` vs. o futuro `Pesquisa
 * Captura`).
 *
 * **Por que não dá pra usar isto pra histórico.** A API "archive" da
 * Open-Meteo (https://open-meteo.com/en/docs/historical-weather-api)
 * só guarda vento a 10 m/100 m — sem os níveis de pressão que a grade
 * de vento por altitude do mapa científico usa. Ou seja: se a captura
 * falhar ou não rodar num instante, aquele instante não pode ser
 * reconsultado depois — é por isso que a arquitetura inteira desta
 * camada é "capturar ao vivo e guardar", nunca "guardar só a
 * referência e consultar de novo na hora de exibir".
 *
 * Mesmo estilo de `MetarClient`: `curl_exec()` puro (nenhuma dependência
 * nova de composer), melhor esforço sempre — qualquer falha (rede,
 * timeout, resposta malformada) devolve `null`, nunca lança. Quem chama
 * decide o que fazer com a ausência (ver `PesquisaAmbienteCaptador`,
 * que simplesmente não grava a amostra daquele ciclo).
 */
class OpenMeteoClient
{
    private const BASE_URL = 'https://api.open-meteo.com/v1/forecast';
    private const CONNECT_TIMEOUT_S = 4;
    private const TOTAL_TIMEOUT_S = 6;

    /**
     * Condição atual num ponto — `null` quando não dá pra saber (sem
     * `ext-curl`, coordenada inválida, timeout, erro de rede/parsing).
     *
     * @return array{tempC: ?float, pressaoHpa: ?float, ventoKt: ?float, ventoDir: ?int, rajadaKt: ?float, precipMmH: ?float, weatherCode: ?int}|null
     */
    public function condicaoAtual(float $lat, float $lon): ?array
    {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || !\function_exists('curl_init')) {
            return null;
        }

        $params = [
            'latitude' => round($lat, 4),
            'longitude' => round($lon, 4),
            'current' => 'temperature_2m,precipitation,weather_code,pressure_msl,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
            'wind_speed_unit' => 'kn',
            'timezone' => 'UTC',
        ];
        $url = self::BASE_URL.'?'.http_build_query($params);

        $ch = curl_init($url);
        if (false === $ch) {
            return null;
        }

        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 3,
            \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
            \CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_S,
            \CURLOPT_USERAGENT => 'Katabatic/1.0 (pesquisa-meteorologica; +ACARS)',
            \CURLOPT_FAILONERROR => true,
        ]);

        $body = curl_exec($ch);
        $erro = curl_error($ch);
        curl_close($ch);

        if (false === $body || '' !== $erro) {
            return null;
        }

        $json = json_decode((string) $body, true);
        if (!\is_array($json) || !isset($json['current']) || !\is_array($json['current'])) {
            return null;
        }

        $c = $json['current'];

        return [
            'tempC' => is_numeric($c['temperature_2m'] ?? null) ? (float) $c['temperature_2m'] : null,
            'pressaoHpa' => is_numeric($c['pressure_msl'] ?? null) ? (float) $c['pressure_msl'] : null,
            'ventoKt' => is_numeric($c['wind_speed_10m'] ?? null) ? (float) $c['wind_speed_10m'] : null,
            'ventoDir' => is_numeric($c['wind_direction_10m'] ?? null) ? (int) round((float) $c['wind_direction_10m']) : null,
            'rajadaKt' => is_numeric($c['wind_gusts_10m'] ?? null) ? (float) $c['wind_gusts_10m'] : null,
            'precipMmH' => is_numeric($c['precipitation'] ?? null) ? (float) $c['precipitation'] : null,
            'weatherCode' => is_numeric($c['weather_code'] ?? null) ? (int) $c['weather_code'] : null,
        ];
    }

    /**
     * Grade de vento por altitude ao redor de um ponto — a peça que
     * alimenta o mapa estilo Windy da área científica (ver
     * `App\Service\PesquisaMapaCaptador` e o futuro `leaflet-velocity`
     * no front). Só a resolução **horária** existe pra níveis de
     * pressão na Open-Meteo (não há "current" pra isso) — pega a hora
     * UTC mais próxima de agora dentro da resposta.
     *
     * Um ponto de grade por vez seria uma chamada HTTP por ponto; a
     * Open-Meteo aceita coordenadas em lote (`latitude`/`longitude`
     * como listas separadas por vírgula, mesmo truque que
     * `mapa-ao-vivo.js` já usa no cliente) — uma chamada só devolve a
     * grade inteira.
     *
     * Vento devolvido como componentes u/v (convenção meteorológica:
     * "de onde vem", u/v apontam pra onde o vento SOPRA) — é o formato
     * que uma biblioteca de partículas animadas (`leaflet-velocity`)
     * espera, sem o front ter que fazer trigonometria.
     *
     * @param list<int> $niveisHpa
     *
     * @return array{geradoEm: string, niveisHpa: list<int>, pontos: list<array{lat: float, lon: float, niveis: array<string, array{speedKt: float, dirDeg: int, uKt: float, vKt: float}>}>}|null
     */
    public function gradeVento(float $centerLat, float $centerLon, array $niveisHpa, float $raioGraus = 1.0, int $pontosPorEixo = 3): ?array
    {
        if ([] === $niveisHpa || $pontosPorEixo < 2 || !\function_exists('curl_init')) {
            return null;
        }

        $lats = [];
        $lons = [];
        $passo = (2 * $raioGraus) / ($pontosPorEixo - 1);
        for ($i = 0; $i < $pontosPorEixo; ++$i) {
            for ($j = 0; $j < $pontosPorEixo; ++$j) {
                $lat = $centerLat - $raioGraus + $i * $passo;
                $lon = $centerLon - $raioGraus + $j * $passo;
                if ($lat < -90 || $lat > 90) {
                    continue;
                }
                $lats[] = round($lat, 4);
                $lons[] = round(($lon + 540) % 360 - 180, 4); // normaliza a longitude pro intervalo [-180, 180]
            }
        }
        if ([] === $lats) {
            return null;
        }

        $hourlyVars = [];
        foreach ($niveisHpa as $n) {
            $hourlyVars[] = "wind_speed_{$n}hPa";
            $hourlyVars[] = "wind_direction_{$n}hPa";
        }

        $params = [
            'latitude' => implode(',', $lats),
            'longitude' => implode(',', $lons),
            'hourly' => implode(',', $hourlyVars),
            'wind_speed_unit' => 'kn',
            'forecast_days' => 1,
            'timezone' => 'UTC',
        ];
        $url = self::BASE_URL.'?'.http_build_query($params);

        $ch = curl_init($url);
        if (false === $ch) {
            return null;
        }

        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 3,
            \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
            \CURLOPT_TIMEOUT => 15, // lote de vários pontos - dá mais tempo que a condição de um ponto só
            \CURLOPT_USERAGENT => 'Katabatic/1.0 (pesquisa-meteorologica; +ACARS)',
            \CURLOPT_FAILONERROR => true,
        ]);

        $body = curl_exec($ch);
        $erro = curl_error($ch);
        curl_close($ch);

        if (false === $body || '' !== $erro) {
            return null;
        }

        $json = json_decode((string) $body, true);
        // Um ponto só: a API devolve um objeto solto em vez de lista de
        // um elemento - normaliza pro mesmo formato de sempre.
        if (\is_array($json) && isset($json['hourly'])) {
            $json = [$json];
        }
        if (!\is_array($json) || [] === $json) {
            return null;
        }

        $agora = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $horaAlvo = $agora->format('Y-m-d\TH:00');

        $pontos = [];
        foreach ($json as $idx => $local) {
            if (!\is_array($local) || !isset($local['hourly']['time']) || !\is_array($local['hourly']['time'])) {
                continue;
            }
            $tempos = $local['hourly']['time'];
            $posicao = array_search($horaAlvo, $tempos, true);
            if (false === $posicao) {
                $posicao = 0;
            }

            $niveis = [];
            foreach ($niveisHpa as $n) {
                $speed = $local['hourly']["wind_speed_{$n}hPa"][$posicao] ?? null;
                $dir = $local['hourly']["wind_direction_{$n}hPa"][$posicao] ?? null;
                if (!is_numeric($speed) || !is_numeric($dir)) {
                    continue;
                }
                $speed = (float) $speed;
                $dir = ((int) round((float) $dir)) % 360;
                $rad = deg2rad($dir);
                // Convenção meteorológica: direção é de onde o vento VEM;
                // u/v apontam pra onde ele SOPRA (o que leaflet-velocity
                // e qualquer campo de partículas esperam).
                $niveis[(string) $n] = [
                    'speedKt' => $speed,
                    'dirDeg' => $dir,
                    'uKt' => round(-$speed * sin($rad), 2),
                    'vKt' => round(-$speed * cos($rad), 2),
                ];
            }
            if ([] === $niveis) {
                continue;
            }

            $pontos[] = [
                'lat' => $lats[$idx] ?? $centerLat,
                'lon' => $lons[$idx] ?? $centerLon,
                'niveis' => $niveis,
            ];
        }

        if ([] === $pontos) {
            return null;
        }

        return [
            'geradoEm' => $agora->format(\DateTimeInterface::ATOM),
            'niveisHpa' => $niveisHpa,
            'pontos' => $pontos,
        ];
    }
}
