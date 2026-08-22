<?php

namespace App\Entity;

use App\Repository\PosicaoAoVivoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Última posição real conhecida de uma aeronave "Em voo", vinda do
 * heartbeat de posição do ACARS (`POST /api/acars/v1/voos/posicao`) —
 * sexta fatia de backend, ver README "Backend: posição em tempo real
 * (ACARS fase 3)".
 *
 * **Decisão deliberada, mesmo espírito de `Voo::$dados`:** esta tabela
 * guarda só a posição MAIS RECENTE por aeronave (upsert, uma linha por
 * `aeronave`, nunca cresce) — não um histórico de amostras. Normalizar
 * um histórico de pings (com `session_id`, sequência, etc., como o
 * contrato completo em `docs/payload-telemetria-acars.md` descreve)
 * seria prematuro sem volume real de uso pra guiar o schema — mesma
 * razão que já vale pra `Voo::$dados['telemetria']`. Se um dia fizer
 * falta (replay de posição real em vez de loop, análise de rota
 * voada), a migração natural é uma tabela nova ao lado desta (`sessao`/
 * `posicao_amostra`), sem precisar tocar aqui — é por isso que esta
 * entidade fica isolada de `Aeronave` (FK própria, não colunas
 * embutidas nela): trocar "como a posição ao vivo é guardada" no
 * futuro não deve exigir mexer na entidade da frota.
 *
 * Relação 1:1 com `Aeronave` só por convenção de uso atual (um upsert
 * por matrícula) — a constraint de unicidade em `aeronave_id` é o que
 * IMPÕE isso; removê-la um dia (pra guardar histórico) é a única
 * mudança de schema necessária, sem precisar recriar a tabela.
 */
#[ORM\Entity(repositoryClass: PosicaoAoVivoRepository::class)]
#[ORM\Table(name: 'posicao_ao_vivo')]
#[ORM\UniqueConstraint(name: 'uniq_posicao_aeronave', columns: ['aeronave_id'])]
class PosicaoAoVivo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Aeronave::class)]
    #[ORM\JoinColumn(name: 'aeronave_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Aeronave $aeronave;

    #[ORM\Column(type: 'float')]
    private float $lat;

    #[ORM\Column(type: 'float')]
    private float $lon;

    #[ORM\Column(nullable: true)]
    private ?int $altFt = null;

    /** Proa verdadeira (`hdg_true` do contrato) — usada pra orientar o ícone no mapa. */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $hdgTrue = null;

    #[ORM\Column(nullable: true)]
    private ?int $gsKt = null;

    #[ORM\Column(nullable: true)]
    private ?int $iasKt = null;

    #[ORM\Column(nullable: true)]
    private ?int $vsFpm = null;

    #[ORM\Column(nullable: true)]
    private ?bool $onGround = null;

    /** Horário do payload (`at`) — o relógio do PC do piloto, não o do servidor. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registradaEm;

    /**
     * Horário em que o SERVIDOR recebeu este ping — independente do
     * relógio do cliente (que pode estar errado ou atrasado por causa
     * de fila/retry). É este campo, não `registradaEm`, que deve ser
     * usado pra decidir se um ping está "velho demais" (cliente parou
     * de mandar) — ver uso em `MapaAoVivoController`.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $recebidaEm;

    public function __construct(Aeronave $aeronave)
    {
        $this->aeronave = $aeronave;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAeronave(): Aeronave
    {
        return $this->aeronave;
    }

    public function getLat(): float
    {
        return $this->lat;
    }

    public function setLat(float $lat): static
    {
        $this->lat = $lat;

        return $this;
    }

    public function getLon(): float
    {
        return $this->lon;
    }

    public function setLon(float $lon): static
    {
        $this->lon = $lon;

        return $this;
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

    public function getHdgTrue(): ?float
    {
        return $this->hdgTrue;
    }

    public function setHdgTrue(?float $hdgTrue): static
    {
        $this->hdgTrue = $hdgTrue;

        return $this;
    }

    public function getGsKt(): ?int
    {
        return $this->gsKt;
    }

    public function setGsKt(?int $gsKt): static
    {
        $this->gsKt = $gsKt;

        return $this;
    }

    public function getIasKt(): ?int
    {
        return $this->iasKt;
    }

    public function setIasKt(?int $iasKt): static
    {
        $this->iasKt = $iasKt;

        return $this;
    }

    public function getVsFpm(): ?int
    {
        return $this->vsFpm;
    }

    public function setVsFpm(?int $vsFpm): static
    {
        $this->vsFpm = $vsFpm;

        return $this;
    }

    public function isOnGround(): ?bool
    {
        return $this->onGround;
    }

    public function setOnGround(?bool $onGround): static
    {
        $this->onGround = $onGround;

        return $this;
    }

    public function getRegistradaEm(): \DateTimeImmutable
    {
        return $this->registradaEm;
    }

    public function setRegistradaEm(\DateTimeImmutable $registradaEm): static
    {
        $this->registradaEm = $registradaEm;

        return $this;
    }

    public function getRecebidaEm(): \DateTimeImmutable
    {
        return $this->recebidaEm;
    }

    public function setRecebidaEm(\DateTimeImmutable $recebidaEm): static
    {
        $this->recebidaEm = $recebidaEm;

        return $this;
    }
}
