<?php

namespace App\Entity;

use App\Repository\ConfiguracaoPesquisaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Parâmetros operacionais da camada de pesquisa meteorológica (voos
 * `tipoOperacao === 'Pesquisa'`) — cadência de amostragem ambiente,
 * cadência de captura de mapa/vento, níveis de pressão monitorados e
 * limiares de classificação de severidade. Tabela enxuta e tipada (não
 * um key-value genérico, decisão tomada em conversa) — cada parâmetro é
 * uma coluna de verdade, validável no formulário.
 *
 * **Registro único.** Não existe tela de lista/CRUD — só um formulário
 * admin (`ConfiguracaoPesquisaController`) que edita a linha única.
 * `ConfiguracaoPesquisaRepository::obterOuCriar()` garante que ela
 * sempre existe (cria com os valores padrão abaixo na primeira leitura)
 * — quem consome esta configuração (o futuro hook de captura ligado em
 * `AcarsIngestaoController`) nunca precisa tratar "configuração ainda
 * não existe" como caso especial.
 *
 * Antes desta entidade, cadência/limiares deste tipo só existiam como
 * constante PHP espalhada pelo código ou variável de `.env` — esta é a
 * primeira tabela de configuração editável em runtime do sistema.
 */
#[ORM\Entity(repositoryClass: ConfiguracaoPesquisaRepository::class)]
#[ORM\Table(name: 'configuracao_pesquisa')]
class ConfiguracaoPesquisa
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** De quanto em quanto tempo uma amostra ambiente (vento/temp/pressão/precipitação) é capturada durante um voo de pesquisa, em minutos. */
    #[ORM\Column]
    private int $amostraCadenciaMin = 2;

    /** De quanto em quanto tempo o mapa (imagem composta + tiles) e a grade de vento são capturados e armazenados, em minutos. */
    #[ORM\Column]
    private int $capturaCadenciaMin = 30;

    /**
     * Raio, em tiles, da grade baixada ao redor da posição da aeronave a
     * cada captura de mapa — 1 = grade 3×3 (padrão, só o entorno
     * imediato), 10 = grade 21×21 (cobre uma região bem mais ampla).
     * Pedido em conversa: "não quero apenas o tile em volta do voo, mas
     * toda a região". Fica como configuração porque o custo cresce
     * quadraticamente (raio × 2 ≈ 4× mais tiles) — ver docblock de
     * `App\Service\PesquisaMapaCaptador::capturar()` pro efeito em rede/
     * disco/memória de valores altos.
     */
    #[ORM\Column]
    private int $mapaGridRaio = 1;

    /**
     * Níveis de pressão (hPa) consultados na grade de vento por
     * altitude a cada captura — do solo (~1000hPa) até a média
     * troposfera (~300hPa). Lista curta de propósito: cada nível a mais
     * multiplica o tamanho da grade armazenada por captura.
     *
     * @var list<int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $niveisPressaoHpa = [1000, 925, 850, 700, 500, 300];

    /** Vento sustentado (kt) a partir do qual uma amostra é classificada "moderada". */
    #[ORM\Column]
    private int $ventoModeradoKt = 25;

    /** Vento sustentado (kt) a partir do qual uma amostra é classificada "severa". */
    #[ORM\Column]
    private int $ventoSeveroKt = 40;

    /** Rajada (kt) a partir da qual uma amostra é classificada "severa", independente do vento sustentado. */
    #[ORM\Column]
    private int $rajadaSeveraKt = 50;

    /** Precipitação (mm/h) a partir da qual uma amostra é classificada "moderada". */
    #[ORM\Column]
    private float $precipModeradaMmH = 4.0;

    /** Precipitação (mm/h) a partir da qual uma amostra é classificada "severa". */
    #[ORM\Column]
    private float $precipSeveraMmH = 15.0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAmostraCadenciaMin(): int
    {
        return $this->amostraCadenciaMin;
    }

    public function setAmostraCadenciaMin(int $amostraCadenciaMin): static
    {
        $this->amostraCadenciaMin = $amostraCadenciaMin;

        return $this;
    }

    public function getCapturaCadenciaMin(): int
    {
        return $this->capturaCadenciaMin;
    }

    public function setCapturaCadenciaMin(int $capturaCadenciaMin): static
    {
        $this->capturaCadenciaMin = $capturaCadenciaMin;

        return $this;
    }

    public function getMapaGridRaio(): int
    {
        return $this->mapaGridRaio;
    }

    public function setMapaGridRaio(int $mapaGridRaio): static
    {
        $this->mapaGridRaio = $mapaGridRaio;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function getNiveisPressaoHpa(): array
    {
        return $this->niveisPressaoHpa;
    }

    /**
     * @param list<int> $niveisPressaoHpa
     */
    public function setNiveisPressaoHpa(array $niveisPressaoHpa): static
    {
        $this->niveisPressaoHpa = $niveisPressaoHpa;

        return $this;
    }

    public function getVentoModeradoKt(): int
    {
        return $this->ventoModeradoKt;
    }

    public function setVentoModeradoKt(int $ventoModeradoKt): static
    {
        $this->ventoModeradoKt = $ventoModeradoKt;

        return $this;
    }

    public function getVentoSeveroKt(): int
    {
        return $this->ventoSeveroKt;
    }

    public function setVentoSeveroKt(int $ventoSeveroKt): static
    {
        $this->ventoSeveroKt = $ventoSeveroKt;

        return $this;
    }

    public function getRajadaSeveraKt(): int
    {
        return $this->rajadaSeveraKt;
    }

    public function setRajadaSeveraKt(int $rajadaSeveraKt): static
    {
        $this->rajadaSeveraKt = $rajadaSeveraKt;

        return $this;
    }

    public function getPrecipModeradaMmH(): float
    {
        return $this->precipModeradaMmH;
    }

    public function setPrecipModeradaMmH(float $precipModeradaMmH): static
    {
        $this->precipModeradaMmH = $precipModeradaMmH;

        return $this;
    }

    public function getPrecipSeveraMmH(): float
    {
        return $this->precipSeveraMmH;
    }

    public function setPrecipSeveraMmH(float $precipSeveraMmH): static
    {
        $this->precipSeveraMmH = $precipSeveraMmH;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Classifica uma amostra ambiente em 'calmo' / 'moderado' / 'severo'
     * a partir do pior entre vento sustentado, rajada e precipitação —
     * mesmo espírito do `weatherCodeInfo()` de `mapa-ao-vivo.js`
     * (bucket por severidade), só que dirigido pelos limiares
     * configuráveis aqui em vez de hardcoded. Usado pelo futuro hook de
     * captura ambiente (ver `App\Service\PesquisaAmbienteCaptador`) e
     * pelo gerador de relatório determinístico.
     */
    public function classificarSeveridade(?float $ventoKt, ?float $rajadaKt, ?float $precipMmH): string
    {
        $severo = (null !== $ventoKt && $ventoKt >= $this->ventoSeveroKt)
            || (null !== $rajadaKt && $rajadaKt >= $this->rajadaSeveraKt)
            || (null !== $precipMmH && $precipMmH >= $this->precipSeveraMmH);
        if ($severo) {
            return 'severo';
        }

        $moderado = (null !== $ventoKt && $ventoKt >= $this->ventoModeradoKt)
            || (null !== $precipMmH && $precipMmH >= $this->precipModeradaMmH);

        return $moderado ? 'moderado' : 'calmo';
    }
}
