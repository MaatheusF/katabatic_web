<?php

namespace App\Entity;

use App\Repository\PesquisaAmostraRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma amostra ambiente (vento/temperatura/pressão/precipitação) ao
 * redor da aeronave, capturada ao vivo durante um voo de Pesquisa —
 * alimentada por `App\Service\PesquisaAmbienteCaptador`, chamado a cada
 * heartbeat de posição (`AcarsIngestaoController::posicao()`) que
 * respeita a cadência configurada em `ConfiguracaoPesquisa::
 * $amostraCadenciaMin`.
 *
 * **Por que não é uma FK pra `Voo`.** `Voo` só nasce no fechamento do
 * voo (`AcarsIngestaoController::ingerir()`) — no momento da captura,
 * ainda não existe nenhuma linha em `voo` pra apontar. Em vez disso,
 * cada amostra carrega `aeronaveReg` + `capturadoEm`; no fechamento,
 * `ingerir()` busca (`PesquisaAmostraRepository::findParaVoo()`) todas
 * as amostras daquela matrícula dentro da janela `[startedAt, agora]` e
 * congela um resumo delas dentro de `Voo::$dados['pesquisa']` — mesmo
 * princípio de "congelar no fechamento" que `metar`/`metarPouso` já
 * usam. As linhas aqui continuam existindo depois disso (não são
 * apagadas) — servem de granularidade fina por trás do resumo
 * congelado, caso um relatório futuro precise reprocessar.
 *
 * `severidade` é calculada uma vez, na captura, com
 * `ConfiguracaoPesquisa::classificarSeveridade()` — não é recalculada
 * se os limiares mudarem depois (mesmo motivo do congelamento acima:
 * um voo antigo não deveria mudar de classificação silenciosamente
 * porque o admin ajustou um limiar meses depois).
 */
#[ORM\Entity(repositoryClass: PesquisaAmostraRepository::class)]
#[ORM\Table(name: 'pesquisa_amostra')]
#[ORM\Index(name: 'idx_pesquisa_amostra_reg_capturado', columns: ['aeronave_reg', 'capturado_em'])]
class PesquisaAmostra
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $aeronaveReg;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $capturadoEm;

    #[ORM\Column]
    private float $lat;

    #[ORM\Column]
    private float $lon;

    #[ORM\Column(nullable: true)]
    private ?int $altFt = null;

    /** Vento sustentado no nível de voo, em kt — Open-Meteo. */
    #[ORM\Column(nullable: true)]
    private ?float $ventoKt = null;

    /** Direção do vento, em graus verdadeiros. */
    #[ORM\Column(nullable: true)]
    private ?int $ventoDir = null;

    /** Rajada, em kt. */
    #[ORM\Column(nullable: true)]
    private ?float $rajadaKt = null;

    /** Temperatura do ar, em °C, no nível de superfície mais próximo do ponto (Open-Meteo não dá temperatura por altitude de voo no plano gratuito). */
    #[ORM\Column(nullable: true)]
    private ?float $tempC = null;

    /** Pressão ao nível do mar, em hPa. */
    #[ORM\Column(nullable: true)]
    private ?float $pressaoHpa = null;

    /** Taxa de precipitação, em mm/h. */
    #[ORM\Column(nullable: true)]
    private ?float $precipMmH = null;

    /** Código de tempo WMO (`weather_code` da Open-Meteo) — mesmo catálogo que `mapa-ao-vivo.js` já bucketiza no cliente. */
    #[ORM\Column(nullable: true)]
    private ?int $weatherCode = null;

    /** 'calmo' / 'moderado' / 'severo' — ver `ConfiguracaoPesquisa::classificarSeveridade()`. */
    #[ORM\Column(length: 20)]
    private string $severidade;

    /**
     * Alerta oficial (NWS/api.weather.gov) cobrindo este ponto no
     * momento da captura, quando existir — só é preenchido dentro do
     * território dos EUA (hoje, só a base PAFA da rede tem cobertura).
     * Texto curto (evento + tipo), não o boletim inteiro.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $alertaOficial = null;

    public function __construct(
        string $aeronaveReg,
        \DateTimeImmutable $capturadoEm,
        float $lat,
        float $lon,
        string $severidade,
    ) {
        $this->aeronaveReg = $aeronaveReg;
        $this->capturadoEm = $capturadoEm;
        $this->lat = $lat;
        $this->lon = $lon;
        $this->severidade = $severidade;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAeronaveReg(): string
    {
        return $this->aeronaveReg;
    }

    public function getCapturadoEm(): \DateTimeImmutable
    {
        return $this->capturadoEm;
    }

    public function getLat(): float
    {
        return $this->lat;
    }

    public function getLon(): float
    {
        return $this->lon;
    }

    public function getAltFt(): ?int
    {
        return $this->altFt;
    }

    public function setAltFt(?int $altFt): static
    {
        $this->altFt = $altFt;

        return $this;
    }

    public function getVentoKt(): ?float
    {
        return $this->ventoKt;
    }

    public function setVentoKt(?float $ventoKt): static
    {
        $this->ventoKt = $ventoKt;

        return $this;
    }

    public function getVentoDir(): ?int
    {
        return $this->ventoDir;
    }

    public function setVentoDir(?int $ventoDir): static
    {
        $this->ventoDir = $ventoDir;

        return $this;
    }

    public function getRajadaKt(): ?float
    {
        return $this->rajadaKt;
    }

    public function setRajadaKt(?float $rajadaKt): static
    {
        $this->rajadaKt = $rajadaKt;

        return $this;
    }

    public function getTempC(): ?float
    {
        return $this->tempC;
    }

    public function setTempC(?float $tempC): static
    {
        $this->tempC = $tempC;

        return $this;
    }

    public function getPressaoHpa(): ?float
    {
        return $this->pressaoHpa;
    }

    public function setPressaoHpa(?float $pressaoHpa): static
    {
        $this->pressaoHpa = $pressaoHpa;

        return $this;
    }

    public function getPrecipMmH(): ?float
    {
        return $this->precipMmH;
    }

    public function setPrecipMmH(?float $precipMmH): static
    {
        $this->precipMmH = $precipMmH;

        return $this;
    }

    public function getWeatherCode(): ?int
    {
        return $this->weatherCode;
    }

    public function setWeatherCode(?int $weatherCode): static
    {
        $this->weatherCode = $weatherCode;

        return $this;
    }

    public function getSeveridade(): string
    {
        return $this->severidade;
    }

    public function getAlertaOficial(): ?string
    {
        return $this->alertaOficial;
    }

    public function setAlertaOficial(?string $alertaOficial): static
    {
        $this->alertaOficial = $alertaOficial;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'em' => $this->capturadoEm->format(\DateTimeInterface::ATOM),
            'lat' => $this->lat,
            'lon' => $this->lon,
            'altFt' => $this->altFt,
            'ventoKt' => $this->ventoKt,
            'ventoDir' => $this->ventoDir,
            'rajadaKt' => $this->rajadaKt,
            'tempC' => $this->tempC,
            'pressaoHpa' => $this->pressaoHpa,
            'precipMmH' => $this->precipMmH,
            'weatherCode' => $this->weatherCode,
            'severidade' => $this->severidade,
            'alertaOficial' => $this->alertaOficial,
        ];
    }
}
