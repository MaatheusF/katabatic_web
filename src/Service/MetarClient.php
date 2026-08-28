<?php

namespace App\Service;

/**
 * Busca o METAR mais recente publicado pra um ICAO, direto do
 * aviationweather.gov (NOAA/NWS — API pública, sem chave/token). Usado
 * por `TelemetriaVooBuilder::build()` (ambas as vias de entrada, ACARS e
 * upload manual — ver docblock de lá) pra preencher
 * `Voo::$dados['metar']` (origem) e `Voo::$dados['metarPouso']` (pouso
 * real, `$posIcao` — não necessariamente o `destino` declarado).
 *
 * **Aproximação documentada.** Não é o METAR "no horário do voo" — é o
 * METAR mais recente publicado no MOMENTO em que o servidor processa o
 * POST de fechamento (`ingerir()`, chamado quando o voo termina). Como
 * os voos desta rede são curtos (a maioria sob 3h) e o cliente envia o
 * payload assim que a gravação encerra, a diferença costuma ser de
 * minutos — mas não é garantido (retry manual de um payload salvo em
 * disco, por exemplo, pode chegar horas depois). Buscar o METAR
 * histórico de verdade (horário exato do voo) exigiria uma API
 * diferente (arquivo histórico, não "latest") — fora do escopo desta
 * fatia.
 *
 * **Melhor esforço, sempre.** Qualquer falha (rede, timeout, ICAO sem
 * estação meteorológica — comum nas pistas de bush flying sem ICAO
 * oficial desta rede, ver `Aeroporto::$icaoOficial`) devolve `null` em
 * vez de lançar. Quem chama decide o texto de fallback (ver
 * `ingerir()`) — nunca deve travar a gravação do voo por causa disto.
 *
 * Usa `curl_exec()` do PHP direto, mesmo padrão (e mesmo motivo) de
 * `ImportarAeroportosOurairportsCommand`: nenhuma dependência nova de
 * composer só pra uma chamada HTTP. Sem dependência de Doctrine/Symfony
 * — só string entra, string (ou null) sai, fácil de testar isolado.
 */
class MetarClient
{
    private const BASE_URL = 'https://aviationweather.gov/api/data/metar';
    private const CONNECT_TIMEOUT_S = 4;
    private const TOTAL_TIMEOUT_S = 6;

    /**
     * Texto cru do METAR mais recente (ex.: "PAFA 221953Z 09008KT 10SM
     * FEW250 M02/M08 A3005"), ou `null` quando não dá pra saber (sem
     * `ext-curl`, ICAO inválido, sem estação, timeout, erro de rede).
     * Nunca lança.
     */
    public function buscarMaisRecente(string $icao): ?string
    {
        $icao = strtoupper(trim($icao));
        if ('' === $icao || !preg_match('/^[A-Z0-9]{3,8}$/', $icao) || !\function_exists('curl_init')) {
            return null;
        }

        $url = self::BASE_URL.'?ids='.rawurlencode($icao).'&format=raw';

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
            \CURLOPT_USERAGENT => 'Katabatic/1.0 (voo-metar; +ACARS)',
            \CURLOPT_FAILONERROR => true,
        ]);

        $body = curl_exec($ch);
        $erro = curl_error($ch);
        curl_close($ch);

        if (false === $body || '' !== $erro) {
            return null;
        }

        // A API devolve uma linha por estação pedida (só pedimos uma) -
        // corpo vazio significa "sem observação recente pra esse ICAO"
        // (comum em pistas locais/FAA sem estação meteorológica).
        $linha = trim((string) $body);

        return '' !== $linha ? $linha : null;
    }
}
