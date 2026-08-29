<?php

namespace App\Entity;

use App\Repository\PesquisaCapturaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma captura de mapa (tiles de base+radar armazenados em disco,
 * mais uma imagem composta) + grade de vento por altitude, tirada ao
 * redor da posição da aeronave durante um voo de Pesquisa — cadência
 * mais espaçada que `PesquisaAmostra` (`ConfiguracaoPesquisa::
 * $capturaCadenciaMin`, ~30 min por padrão), porque cada captura baixa
 * e guarda vários arquivos de imagem.
 *
 * Mesmo desenho de `PesquisaAmostra`: sem FK pra `Voo` (não existe
 * ainda no momento da captura), casada por `aeronaveReg` + janela de
 * tempo no fechamento (`PesquisaVooAggregator`). Ver docblock de lá
 * pro raciocínio completo.
 *
 * `manifestoMapa` guarda os CAMINHOS dos arquivos em disco (ver
 * `App\Service\PesquisaMapaCaptador`) — os arquivos em si moram fora do
 * banco, em `public/uploads/pesquisa-captura/...`. `gradeVento` guarda
 * a grade de vento por nível de pressão inteira como JSON (é dado
 * estruturado pequeno, não um blob binário — ver `App\Service\
 * OpenMeteoClient::gradeVento()`), diferente das imagens.
 */
#[ORM\Entity(repositoryClass: PesquisaCapturaRepository::class)]
#[ORM\Table(name: 'pesquisa_captura')]
#[ORM\Index(name: 'idx_pesquisa_captura_reg_capturado', columns: ['aeronave_reg', 'capturado_em'])]
class PesquisaCaptura
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

    /**
     * Ver `App\Service\PesquisaMapaCaptador::capturar()` pro formato —
     * `null` quando a captura de mapa falhou por completo (rede fora,
     * sem GD e sem nenhum tile baixado) mas a grade de vento deu certo.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $manifestoMapa = null;

    /**
     * Ver `App\Service\OpenMeteoClient::gradeVento()` pro formato —
     * `null` quando a Open-Meteo falhou nesse ciclo.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $gradeVento = null;

    public function __construct(string $aeronaveReg, \DateTimeImmutable $capturadoEm, float $lat, float $lon)
    {
        $this->aeronaveReg = $aeronaveReg;
        $this->capturadoEm = $capturadoEm;
        $this->lat = $lat;
        $this->lon = $lon;
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

    /**
     * @return array<string, mixed>|null
     */
    public function getManifestoMapa(): ?array
    {
        return $this->manifestoMapa;
    }

    /**
     * @param array<string, mixed>|null $manifestoMapa
     */
    public function setManifestoMapa(?array $manifestoMapa): static
    {
        $this->manifestoMapa = $manifestoMapa;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGradeVento(): ?array
    {
        return $this->gradeVento;
    }

    /**
     * @param array<string, mixed>|null $gradeVento
     */
    public function setGradeVento(?array $gradeVento): static
    {
        $this->gradeVento = $gradeVento;

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
            'mapa' => $this->manifestoMapa,
            'vento' => $this->gradeVento,
        ];
    }
}
