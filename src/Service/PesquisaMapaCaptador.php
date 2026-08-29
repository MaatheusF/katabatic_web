<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Baixa e armazena PERMANENTEMENTE, a cada captura de um voo de
 * Pesquisa, uma grade de tiles de mapa base (CARTO) + radar de
 * precipitação (RainViewer) ao redor da posição da aeronave, mais uma
 * imagem composta (thumbnail) pra timeline de captura da área
 * científica (ver `PesquisaCapturaOrquestrador`).
 *
 * **Por que baixar e guardar, nunca só referenciar a URL ao vivo.**
 * RainViewer só expõe uma janela curta (~2h) de frames — não tem
 * arquivo histórico; e mesmo o CARTO (que tem uptime/estilo estável)
 * poderia mudar a URL/chave no futuro. Pra "ver essa condição no
 * futuro sem problemas" (pedido explícito em conversa), os tiles de
 * verdade — não só a imagem composta — são baixados como arquivo aqui,
 * pra o mapa científico (fase 5) poder recriar uma camada de tiles
 * navegável (zoom/pan) de qualquer captura antiga, exatamente como
 * apareceu na hora.
 *
 * Grade de tamanho configurável ao redor da aeronave, zoom fixo — o raio
 * (`ConfiguracaoPesquisa::$mapaGridRaio`, 1 tile de cada lado por
 * padrão = grade 3×3) decide quanto além do entorno imediato a captura
 * cobre. Pedido em conversa: "não quero apenas o tile em volta do voo,
 * mas toda a região... poderia ser uma configuração".
 *
 * **Custo cresce quadraticamente com o raio** — grade = (2×raio+1)²
 * tiles, então raio 10 já são 441 tiles (até 882 downloads somando base
 * + radar) POR captura, contra 9 do padrão. Três efeitos concretos de
 * subir o raio, nessa ordem de "dói primeiro":
 * 1. **Tempo da requisição.** `baixar()` é `curl_exec()` síncrono, um
 *    tile de cada vez, chamado de dentro do heartbeat ACARS
 *    (`PesquisaCapturaOrquestrador::capturarSeNecessario()`, dentro do
 *    ciclo de request/response de `AcarsIngestaoController::posicao()`)
 *    — uma grade grande pode estourar o `max_execution_time` do PHP
 *    antes de terminar.
 * 2. **Memória do GD.** Cada tile é desenhado no canvas final (`grade×256`
 *    pixels de lado) e liberado (`imagedestroy()`) IMEDIATAMENTE depois —
 *    nunca ficam todos os tiles decodificados na memória ao mesmo tempo
 *    (bug real encontrado em produção: com raio 5/grade 11×11, manter os
 *    121+121 tiles decodificados vivos até o fim do download, só pra
 *    depois desenhar tudo no canvas, ultrapassava o `memory_limit` padrão
 *    (128M) em boa parte das execuções — captura falhava de forma
 *    intermitente, `imagemComposta`/`imagemMapa` saíam `null` mesmo com
 *    os 121 tiles baixados com sucesso). Ainda assim, o canvas em si
 *    cresce com o raio — em raio 10 (grade 21×21) já passa de 30MB só o
 *    canvas, então valores muito altos continuam arriscados.
 * 3. **Disco.** Cada tile baixado fica permanentemente em
 *    `public/uploads/pesquisa-captura/...` (nunca é limpo) — grade maior
 *    × captura recorrente × vários voos de pesquisa soma espaço rápido.
 *
 * **Melhor esforço por tile.** Uma falha (timeout, tile 404, GD
 * ausente) derruba só aquele tile/composição — nunca a captura
 * inteira. Ver `PesquisaCapturaOrquestrador`, que já embrulha a
 * chamada inteira num try/catch por segurança.
 */
class PesquisaMapaCaptador
{
    private const ZOOM = 7;
    private const TILE_PX = 256;
    private const RADAR_INDEX_URL = 'https://api.rainviewer.com/public/weather-maps.json';
    private const CONNECT_TIMEOUT_S = 4;
    private const TOTAL_TIMEOUT_S = 8;

    /**
     * Lado (px) da miniatura pra timeline — a tira de capturas mostra
     * várias lado a lado numa caixa CSS de 84px (ver .pc-thumb), então
     * não faz sentido guardar em resolução alta ali; um pouco acima do
     * dobro do tamanho de tela cobre telas retina sem pesar o arquivo.
     */
    private const THUMB_LADO = 220;

    /**
     * Teto (px) do lado da imagem usada como overlay no mapa GRANDE
     * (`imagemMapa`) — diferente da miniatura, essa precisa aguentar
     * zoom. Antes as duas usavam a MESMA imagem de 480px (pensada só
     * pra miniatura); numa grade grande (ex.: raio 5 = tela nativa de
     * 2816px) isso jogava fora >80% da resolução baixada, ficando
     * borrado/pixelado ao dar zoom no mapa — pedido em conversa:
     * "aumentar a qualidade do mapa de precipitação, ao dar zoom fica
     * muito distorcido".
     *
     * Era 1600 — mesmo depois do ajuste acima, uma grade de raio 5
     * (tela nativa 2816px, o raio configurado hoje) ainda era
     * REDUZIDA em ~43% antes de virar `imagemMapa`, perdendo nitidez à
     * toa (pedido em conversa: "ainda está com uma qualidade meia
     * duvidosa durante o zoom"). Subido pra cobrir raio 5 sem redução
     * nenhuma (grade 11×11 = 2816px, abaixo do teto). Ainda cabe um
     * teto (não usa a resolução nativa sempre) porque uma grade de
     * raio 10 nativa passaria de 5000px de lado — JPEG gigante por
     * captura sem ganho real de nitidez na tela.
     */
    private const MAPA_LADO_MAX = 3000;

    public function __construct(
        #[Autowire('%env(CARTO_API_KEY)%')] private readonly string $cartoApiKey,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * @param int $gridRaio Raio da grade em tiles, cada direção — ver
     *                       `ConfiguracaoPesquisa::$mapaGridRaio`.
     *                       Grade final = (2×$gridRaio+1)² tiles.
     *                       Valores negativos são tratados como 1 (nunca
     *                       menos que a grade mínima 3×3).
     *
     * @return array{zoom: int, grid: int, centroX: int, centroY: int, imagemComposta: ?string, imagemMapa: ?string, tilesBase: list<array{x: int, y: int, caminho: string}>, tilesRadar: list<array{x: int, y: int, caminho: string}>}|null
     *         Caminhos devolvidos são públicos (servíveis direto,
     *         começam com `/uploads/...`) — mesma convenção de
     *         `FotoVooUploader`. `null` só quando NADA pôde ser salvo
     *         (pasta de destino inacessível, por exemplo). `imagemComposta`
     *         é a miniatura pra timeline (`THUMB_LADO`); `imagemMapa` é a
     *         versão maior pro overlay no mapa grande (`MAPA_LADO_MAX`) —
     *         mesmo conteúdo, resoluções diferentes.
     */
    public function capturar(string $aeronaveReg, float $lat, float $lon, \DateTimeImmutable $momento, int $gridRaio = 1): ?array
    {
        if (!\function_exists('curl_init')) {
            return null;
        }

        $offset = max(1, $gridRaio);
        $grid = 2 * $offset + 1;

        $centroX = $this->lonParaTileX($lon, self::ZOOM);
        $centroY = $this->latParaTileY($lat, self::ZOOM);

        $pastaRelativa = sprintf(
            '/uploads/pesquisa-captura/%s/%s',
            preg_replace('/[^A-Z0-9]/', '', strtoupper($aeronaveReg)) ?: 'XXXXX',
            $momento->format('Ymd_His')
        );
        $pastaAbsoluta = $this->projectDir.'/public'.$pastaRelativa;
        if (!is_dir($pastaAbsoluta.'/tiles') && !mkdir($pastaAbsoluta.'/tiles', 0775, true) && !is_dir($pastaAbsoluta.'/tiles')) {
            return null;
        }

        $radar = $this->quadroRadarAtual();

        $tilesBase = [];
        $tilesRadar = [];
        $gdOk = $this->gdDisponivel();

        // Canvas criado ANTES do download e desenhado tile a tile, à
        // medida que cada um chega — cada tile decodificado é jogado no
        // canvas e liberado (`imagedestroy()`) na hora, nunca acumulado
        // num array até o fim da grade toda. Bug real corrigido aqui:
        // com a versão anterior (decodifica tudo, desenha tudo só no
        // fim), uma grade grande (raio 5 = 121+121 tiles) mantinha todos
        // os tiles decodificados vivos ao mesmo tempo — passava do
        // `memory_limit` padrão (128M) com frequência, e a captura
        // falhava de forma intermitente mesmo com os 121 tiles baixados
        // com sucesso (`imagemComposta`/`imagemMapa` saíam `null`, ou só
        // uma das duas). Ver bullet 2 do docblock da classe.
        $lado = $grid * self::TILE_PX;
        $canvas = null;
        if ($gdOk) {
            $canvas = imagecreatetruecolor($lado, $lado);
            imagealphablending($canvas, true);
            imagesavealpha($canvas, true);
        }

        for ($i = 0; $i < $grid; ++$i) {
            for ($j = 0; $j < $grid; ++$j) {
                $x = $centroX - $offset + $j;
                $y = $centroY - $offset + $i;

                $baseBytes = $this->baixar($this->urlTileBase($x, $y));
                if (null !== $baseBytes) {
                    $caminho = sprintf('%s/tiles/base_%d_%d.png', $pastaAbsoluta, $x, $y);
                    if (false !== @file_put_contents($caminho, $baseBytes)) {
                        $tilesBase[] = ['x' => $x, 'y' => $y, 'caminho' => sprintf('%s/tiles/base_%d_%d.png', $pastaRelativa, $x, $y)];
                        if (null !== $canvas) {
                            $img = @imagecreatefromstring($baseBytes);
                            if (false !== $img) {
                                imagecopy($canvas, $img, $j * self::TILE_PX, $i * self::TILE_PX, 0, 0, self::TILE_PX, self::TILE_PX);
                                imagedestroy($img);
                            }
                        }
                    }
                }

                if (null !== $radar) {
                    $radarBytes = $this->baixar($this->urlTileRadar($radar, $x, $y));
                    if (null !== $radarBytes) {
                        $caminho = sprintf('%s/tiles/radar_%d_%d.png', $pastaAbsoluta, $x, $y);
                        if (false !== @file_put_contents($caminho, $radarBytes)) {
                            $tilesRadar[] = ['x' => $x, 'y' => $y, 'caminho' => sprintf('%s/tiles/radar_%d_%d.png', $pastaRelativa, $x, $y)];
                            if (null !== $canvas) {
                                $img = @imagecreatefromstring($radarBytes);
                                if (false !== $img) {
                                    imagealphablending($img, true);
                                    imagesavealpha($img, true);
                                    imagecopy($canvas, $img, $j * self::TILE_PX, $i * self::TILE_PX, 0, 0, self::TILE_PX, self::TILE_PX);
                                    imagedestroy($img);
                                }
                            }
                        }
                    }
                }
            }
        }

        $imagemComposta = null;
        $imagemMapa = null;
        if (null !== $canvas && [] !== $tilesBase) {
            [$imagemComposta, $imagemMapa] = $this->finalizarComposicao($canvas, $lado, $pastaAbsoluta, $pastaRelativa, $lat, $lon, $centroX, $centroY, $offset);
        } elseif (null !== $canvas) {
            imagedestroy($canvas);
        }

        if ([] === $tilesBase && [] === $tilesRadar) {
            return null;
        }

        return [
            'zoom' => self::ZOOM,
            'grid' => $grid,
            'centroX' => $centroX,
            'centroY' => $centroY,
            'imagemComposta' => $imagemComposta,
            'imagemMapa' => $imagemMapa,
            'tilesBase' => $tilesBase,
            'tilesRadar' => $tilesRadar,
        ];
    }

    public function gdDisponivel(): bool
    {
        return \function_exists('imagecreatetruecolor') && \function_exists('imagecreatefromstring') && \function_exists('imagejpeg');
    }

    /**
     * Converte `$gridRaio` (raio em TILES, mesma unidade usada aqui em
     * `capturar()`) num raio equivalente em GRAUS de longitude, no ZOOM
     * fixo desta classe — pra quem busca a grade de vento
     * (`OpenMeteoClient::gradeVento()`) poder cobrir a MESMA área que a
     * imagem de mapa/precipitação, em vez de ficar presa nos valores
     * fixos antigos da Open-Meteo (±1°, bem menor que a largura de 1 só
     * tile neste zoom). Bug real encontrado em produção: `mapaGridRaio`
     * só era usado pela captura de imagem — o vento nunca respeitava a
     * configuração, então a área de vento "parecia 1 tile" mesmo com o
     * raio configurado bem maior. Pedido em conversa: "porque não está
     * exibindo todos os tiles que configurei? está com 5 marcado" (sobre
     * o vento, não a imagem).
     */
    public function grauPorRaioTiles(int $gridRaio): float
    {
        return max(1, $gridRaio) * 360.0 / (2 ** self::ZOOM);
    }

    /**
     * Recebe o canvas JÁ com todos os tiles desenhados (ver `capturar()`
     * — tiles são pintados e liberados um a um durante o download, não
     * mais aqui) e só cuida do que precisa do canvas PRONTO: o marcador
     * da aeronave por cima de tudo, e as duas saídas redimensionadas.
     *
     * @return array{0: ?string, 1: ?string} [miniatura (THUMB_LADO), mapa grande (MAPA_LADO_MAX)]
     */
    private function finalizarComposicao(\GdImage $canvas, int $lado, string $pastaAbsoluta, string $pastaRelativa, float $lat, float $lon, int $centroX, int $centroY, int $offset): array
    {
        // Marcador da aeronave — posição fracionária dentro da grade de
        // tiles (não só o centro do tile central), pra ficar preciso.
        $px = ($this->lonParaTileXFracionario($lon, self::ZOOM) - ($centroX - $offset)) * self::TILE_PX;
        $py = ($this->latParaTileYFracionario($lat, self::ZOOM) - ($centroY - $offset)) * self::TILE_PX;
        $laranja = imagecolorallocate($canvas, 255, 140, 0);
        $branco = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledellipse($canvas, (int) round($px), (int) round($py), 14, 14, $branco);
        imagefilledellipse($canvas, (int) round($px), (int) round($py), 10, 10, $laranja);

        // Duas saídas do MESMO canvas (mesmo desenho, resoluções
        // diferentes) — antes só existia uma, de 480px, pensada pra
        // miniatura da timeline mas reaproveitada também como overlay do
        // mapa grande, onde ficava borrada/pixelada ao dar zoom.
        $thumb = $this->salvarRedimensionada($canvas, $lado, min($lado, self::THUMB_LADO), $pastaAbsoluta, $pastaRelativa, 'composta.jpg', 82);
        $mapa = $this->salvarRedimensionada($canvas, $lado, min($lado, self::MAPA_LADO_MAX), $pastaAbsoluta, $pastaRelativa, 'mapa.jpg', 90);
        imagedestroy($canvas);

        return [$thumb, $mapa];
    }

    private function salvarRedimensionada(\GdImage $canvas, int $ladoOrigem, int $ladoDestino, string $pastaAbsoluta, string $pastaRelativa, string $nomeArquivo, int $qualidade): ?string
    {
        $reduzida = imagecreatetruecolor($ladoDestino, $ladoDestino);
        imagecopyresampled($reduzida, $canvas, 0, 0, 0, 0, $ladoDestino, $ladoDestino, $ladoOrigem, $ladoOrigem);

        $caminhoAbsoluto = $pastaAbsoluta.'/'.$nomeArquivo;
        $ok = imagejpeg($reduzida, $caminhoAbsoluto, $qualidade);
        imagedestroy($reduzida);

        return $ok ? $pastaRelativa.'/'.$nomeArquivo : null;
    }

    /**
     * @return array{host: string, path: string}|null
     */
    private function quadroRadarAtual(): ?array
    {
        $body = $this->baixar(self::RADAR_INDEX_URL);
        if (null === $body) {
            return null;
        }
        $json = json_decode($body, true);
        if (!\is_array($json) || !isset($json['host'], $json['radar']['past']) || !\is_array($json['radar']['past']) || [] === $json['radar']['past']) {
            return null;
        }
        $ultimo = end($json['radar']['past']);
        if (!\is_array($ultimo) || !isset($ultimo['path'])) {
            return null;
        }

        return ['host' => (string) $json['host'], 'path' => (string) $ultimo['path']];
    }

    private function urlTileBase(int $x, int $y): string
    {
        $chave = rawurlencode($this->cartoApiKey);

        return "https://a.basemaps.cartocdn.com/dark_all/".self::ZOOM."/{$x}/{$y}.png?key={$chave}";
    }

    /**
     * @param array{host: string, path: string} $radar
     */
    private function urlTileRadar(array $radar, int $x, int $y): string
    {
        return $radar['host'].$radar['path'].'/256/'.self::ZOOM."/{$x}/{$y}/2/1_1.png";
    }

    private function baixar(string $url): ?string
    {
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
            \CURLOPT_USERAGENT => 'Katabatic/1.0 (pesquisa-mapa; +ACARS)',
            \CURLOPT_FAILONERROR => true,
        ]);
        $body = curl_exec($ch);
        $erro = curl_error($ch);
        curl_close($ch);

        return (false !== $body && '' === $erro && '' !== $body) ? $body : null;
    }

    private function lonParaTileX(float $lon, int $zoom): int
    {
        return (int) floor($this->lonParaTileXFracionario($lon, $zoom));
    }

    private function lonParaTileXFracionario(float $lon, int $zoom): float
    {
        return (($lon + 180) / 360) * (2 ** $zoom);
    }

    private function latParaTileY(float $lat, int $zoom): int
    {
        return (int) floor($this->latParaTileYFracionario($lat, $zoom));
    }

    private function latParaTileYFracionario(float $lat, int $zoom): float
    {
        $rad = deg2rad(max(-85.05, min(85.05, $lat)));

        return (1 - log(tan($rad) + 1 / cos($rad)) / \M_PI) / 2 * (2 ** $zoom);
    }
}
