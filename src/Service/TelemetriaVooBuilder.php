<?php

namespace App\Service;

use App\Entity\Aeronave;
use App\Repository\AeroportoRepository;
use App\Repository\TipoAeronaveRepository;

/**
 * Monta tudo que um `Voo` com telemetria medida precisa — a partir de um
 * payload cru (mesmo formato `kb-raw-1` que `katabatic_capture.py` sempre
 * produziu, ver `TelemetryDeriver`) e da `Aeronave`/rota já resolvidas
 * por quem chama.
 *
 * **Por que isto existe separado do controller.** Até esta fatia, toda
 * esta lógica (derivar telemetria, resolver pouso alternativo, peso vs.
 * MTOW, través com heading de pista real, carga estimada, METAR real)
 * morava dentro de `Api\AcarsIngestaoController::ingerir()` — o único
 * jeito de criar um `Voo` com telemetria de verdade. A importação manual
 * de `upload_payload.json` pela tela `/novo-voo` (ver `NovoVooController`
 * — "Backend: importação de telemetria via upload") precisa exatamente
 * do mesmo processamento, só que autenticada por sessão de piloto em vez
 * de token ACARS — extrair pra cá deixa as duas vias produzirem o
 * mesmíssimo resultado (mesmos quatro enriquecimentos "melhor esforço")
 * sem duplicar ~120 linhas de lógica que teriam ficado fáceis de
 * divergir com o tempo.
 *
 * Só monta — nunca persiste nem consulta `Pilot`/`Voo`/sessão; isso
 * continua responsabilidade de cada controller (idempotência por
 * `codigo`, resolução de piloto/aeronave, validação dos campos que só
 * fazem sentido pra cada via de entrada).
 */
class TelemetriaVooBuilder
{
    public function __construct(
        private readonly TelemetryDeriver $deriver,
        private readonly AeroportoRepository $aeroportos,
        private readonly TipoAeronaveRepository $tiposAeronave,
        private readonly MetarClient $metarClient,
    ) {
    }

    /**
     * @param array<string, mixed> $payload payload cru (schema `kb-raw-1`) — ver docblock de `TelemetryDeriver::derive()`
     *
     * @return array{dur: int, dificuldade: int, dados: array<string, mixed>, posIcao: string, destinoReal: ?string}
     */
    public function build(array $payload, Aeronave $aeronave, string $origem, string $destino): array
    {
        $derivado = $this->deriver->derive($payload, $aeronave->getLimiteG(), $aeronave->getLimiteGNegativo());
        $telemetria = $derivado['telemetria'];

        // Pouso alternativo (diversão): resolve onde a aeronave pousou de
        // verdade (telemetria), não onde o plano de voo dizia que ela ia
        // pousar - ver pousoRealIcao(). Resolvido ANTES de montar `dados`
        // porque o través com heading de pista real, logo abaixo, precisa
        // saber qual é o aeroporto de pouso pra procurar o heading
        // cadastrado nele. `$posIcao` é sempre o que vira
        // `Aeronave::posIcao` pra quem chama; `$destino` em si (a rota
        // declarada) nunca muda aqui.
        $posIcao = $destino;
        $real = $this->pousoRealIcao($telemetria);
        if (null !== $real && $real !== $destino) {
            $posIcao = $real;
        }

        $tipoAeronave = $this->tiposAeronave->findOneByNome($aeronave->getTipo());

        // Peso real de decolagem vs. MTOW do tipo, quando o payload trouxe
        // `ident.weight_lb`/`ident.fuel_lb` (script de captura sempre
        // manda) e o tipo tem MTOW/peso vazio cadastrado em
        // /tipos-aeronave. Ambos ficam `null` (tile some no relatório, ver
        // voo.js) quando faltar qualquer um dos dois - nunca um número
        // inventado.
        $identData = is_array($payload['ident'] ?? null) ? $payload['ident'] : [];
        $pesoDecolagemLb = is_numeric($identData['weight_lb'] ?? null) ? (int) round((float) $identData['weight_lb']) : null;
        $fuelInicialLb = is_numeric($identData['fuel_lb'] ?? null) ? (float) $identData['fuel_lb'] : null;

        $telemetria['pesoDecolagemLb'] = $pesoDecolagemLb;
        $telemetria['pesoMaxDecolagemLb'] = $tipoAeronave?->getPesoMaxDecolagemLb();

        // Peso ao longo do voo: só combustível queimando, sem carga
        // largada em voo (não existe esse conceito aqui) — peso decai
        // exatamente o quanto `fuel_lb` (grupo C, já em `telemetria['env']`,
        // índice 7) caiu desde o início. Mesma condição de "nunca um
        // número inventado" de `pesoDecolagemLb`: sem os dois pontos de
        // partida (peso de decolagem E combustível inicial), a série
        // fica vazia e o card correspondente some no relatório (ver
        // voo.js), não mostra um gráfico plano errado.
        $pesoSerie = [];
        if (null !== $pesoDecolagemLb && null !== $fuelInicialLb) {
            foreach ($telemetria['env'] as $e) {
                $fuelAtual = $e[7] ?? null;
                if (null === $fuelAtual) {
                    continue;
                }
                $pesoSerie[] = [$e[0], (int) round($pesoDecolagemLb - ($fuelInicialLb - $fuelAtual))];
            }
        }
        $telemetria['pesoSerie'] = $pesoSerie;

        // Peso "ao pouso" = último ponto da série acima (combustível
        // residual no fim da gravação) — aproximação deliberada: não é
        // o peso exatamente no instante do toque (taxi depois do pouso
        // ainda queima um pouco de combustível), mas a diferença é
        // desprezível perto da resolução de 1 lb desta série. Comparado
        // contra o MTOW só como referência de "quanto ainda estava
        // pesada" — MTOW é limite de DECOLAGEM, não existe um limite de
        // peso de pouso cadastrado nesta frota, então isto nunca vira
        // "excedência" no índice de dificuldade, só um número informativo.
        $pesoPousoLb = $pesoSerie ? end($pesoSerie)[1] : null;
        $telemetria['pesoPousoLb'] = $pesoPousoLb;
        $telemetria['margemPousoAteMtowLb'] = (null !== $pesoPousoLb && null !== $telemetria['pesoMaxDecolagemLb'])
            ? $telemetria['pesoMaxDecolagemLb'] - $pesoPousoLb
            : null;

        // Través com heading de pista real: só quando o aeroporto de pouso
        // (real, resolvido acima) tem um heading cadastrado (ver
        // `Aeroporto::$pistaPrincipalHeadingMag`) - senão fica como sempre
        // esteve (aproximado pelo heading da aeronave no toque). `windc`
        // já vinha arredondado por `TelemetryDeriver::derive()`;
        // recalculamos do zero em vez de tentar "corrigir" o valor
        // arredondado.
        $telemetria['windcFonte'] = 'aeronave';
        $aeroportoPouso = $this->aeroportos->findOneByIcao($posIcao);
        $headingPista = $aeroportoPouso?->getPistaPrincipalHeadingMag();
        if (null !== $headingPista) {
            $windcPista = $this->deriver->recomputeWindcComHeadingDePista($telemetria, (float) $headingPista);
            if (null !== $windcPista) {
                $telemetria['windc'] = round($windcPista);
                $telemetria['windcFonte'] = 'pista';
            }
        }

        $condTag = match ($telemetria['wx']) {
            'Neve' => 'bad',
            'Chuva' => 'warn',
            default => 'ok',
        };
        $ocorrencias = array_values(array_filter(array_map(
            static fn (array $ev) => in_array($ev[4], ['bad', 'warn'], true) && '' !== $ev[2]
                ? ['label' => $ev[2], 'tag' => $ev[4]]
                : null,
            $telemetria['events']
        )));

        // Carga/payload estimada por subtração (peso total - peso vazio do
        // tipo - combustível inicial) - funciona com o payload que o
        // script já manda hoje, sem exigir nenhum campo novo nele.
        // "Estimada" de propósito: é peso total menos o que já sabemos,
        // não uma medição direta de carga.
        $pesoVazioLb = $tipoAeronave?->getPesoVazioLb();
        if (null !== $pesoDecolagemLb && null !== $pesoVazioLb) {
            $cargaEstimadaLb = max(0, (int) round($pesoDecolagemLb - $pesoVazioLb - ($fuelInicialLb ?? 0.0)));
            $carga = sprintf('~%d lb (estimado: peso total − peso vazio − combustível)', $cargaEstimadaLb);
        } elseif (null !== $pesoDecolagemLb) {
            $carga = 'Peso vazio do tipo não cadastrado — carga não estimada';
        } else {
            $carga = 'Não informada pela gravação';
        }

        // METAR real da origem E do pouso (aproximação: mais recente
        // publicado no momento em que isto roda, não o METAR histórico de
        // verdade do horário do voo - ver docblock de `MetarClient`).
        // Pouso usa `$posIcao` (resolvido acima - o aeroporto de pouso
        // REAL, já considerando diversão), não `$destino` declarado, pelo
        // mesmo motivo que `windcPista` acima usa `$posIcao`: o METAR que
        // importa pro debrief é de onde a aeronave pousou de verdade.
        // Melhor esforço nos dois: uma falha aqui nunca impede o voo de
        // ser gravado, só deixa o campo com o texto de fallback de
        // sempre.
        try {
            $metarOrigem = $this->metarClient->buscarMaisRecente($origem);
        } catch (\Throwable) {
            $metarOrigem = null;
        }
        try {
            $metarPouso = $this->metarClient->buscarMaisRecente($posIcao);
        } catch (\Throwable) {
            $metarPouso = null;
        }

        $dados = [
            'rota' => $origem.' → '.$destino,
            'modelo' => $aeronave->getTipo(),
            'cond' => $telemetria['wx'],
            'condTag' => $condTag,
            'ocorrencias' => $ocorrencias,
            'dist' => $telemetria['dist'],
            'combustivelKg' => round($telemetria['fuel'] * 0.453592, 1),
            'carga' => $carga,
            'tempoSoloMin' => (int) round($telemetria['ground_s'] / 60),
            'tempoArMin' => (int) round($telemetria['air_s'] / 60),
            'metar' => $metarOrigem ?? 'Não disponível',
            // Quando a origem e o pouso real são o mesmo ICAO (a grande
            // maioria dos voos locais/circuito), evita uma segunda
            // chamada HTTP idêntica pra rede — reaproveita o METAR da
            // origem já buscado acima.
            'metarPouso' => ($posIcao === $origem ? $metarOrigem : $metarPouso) ?? 'Não disponível',
            'pilotReport' => null,
            'telemetria' => $telemetria,
        ];

        return [
            'dur' => $derivado['dur'],
            'dificuldade' => $derivado['dificuldade'],
            'dados' => $dados,
            'posIcao' => $posIcao,
            'destinoReal' => (null !== $real && $real !== $destino) ? $real : null,
        ];
    }

    /**
     * ICAO do aeroporto cadastrado mais próximo de onde a aeronave pousou
     * de verdade — `null` quando não dá pra saber (sem ponto de pouso
     * utilizável) ou quando nenhum aeroporto cadastrado está perto o
     * bastante (`AeroportoRepository::RAIO_PADRAO_KM`): nesses casos quem
     * chama mantém o comportamento de sempre (confia no `destino`
     * declarado) — mais seguro do que "corrigir" a posição da aeronave
     * pra um aeroporto que pode não ser o de verdade.
     *
     * Prioriza o evento `touchdown` (`$telemetria['td']['lat']/['lon']`,
     * ver `TelemetryDeriver::deriveEvents()`) por ser o ponto exato do
     * toque; cai pra última amostra de `$telemetria['track']` quando não
     * há evento de toque utilizável. `$data['td_lat']/['td_lon']` viram
     * `0.0` (não `null`) em `deriveEvents()` quando o cliente não manda um
     * evento `touchdown` com essas coordenadas — `0.0, 0.0` não é um
     * ponto de pouso de verdade nesta rede (Alasca/Patagônia), então é
     * tratado como "sem coordenada de toque" e cai pro fallback da última
     * amostra.
     *
     * @param array<string, mixed> $telemetria
     */
    private function pousoRealIcao(array $telemetria): ?string
    {
        $lat = null;
        $lon = null;

        $td = $telemetria['td'] ?? null;
        if (is_array($td) && (($td['lat'] ?? 0.0) !== 0.0 || ($td['lon'] ?? 0.0) !== 0.0)) {
            $lat = (float) $td['lat'];
            $lon = (float) $td['lon'];
        } else {
            $track = $telemetria['track'] ?? [];
            $ultima = is_array($track) && [] !== $track ? end($track) : null;
            if (is_array($ultima) && isset($ultima[1], $ultima[2])) {
                $lat = (float) $ultima[1];
                $lon = (float) $ultima[2];
            }
        }

        if (null === $lat || null === $lon) {
            return null;
        }

        return $this->aeroportos->findNearest($lat, $lon)?->getIcao();
    }
}
