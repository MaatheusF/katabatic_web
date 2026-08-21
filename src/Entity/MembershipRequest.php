<?php

namespace App\Entity;

use App\Repository\MembershipRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pedido de adesão enviado pelo formulário público `/adesao` (ver
 * AdesaoController::submit()). Antes desta fatia, o formulário e o
 * grid de Solicitações na área logada (SolicitacoesController) eram
 * dois conjuntos mock independentes — esta tabela é o que finalmente
 * liga os dois: o que chega aqui é o que aparece lá.
 *
 * `status` começa sempre `'pendente'` e só muda pra `'aprovado'`/
 * `'rejeitado'` via SolicitacoesController::aprovar()/rejeitar() — ver
 * lá pra como aprovar cria um `Pilot` de verdade.
 */
#[ORM\Entity(repositoryClass: MembershipRequestRepository::class)]
#[ORM\Table(name: 'membership_request')]
class MembershipRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 16)]
    private string $cid;

    /** Um de 'Iniciante' / 'Intermediário' / 'Experiente' — mesmo texto usado nos chips do formulário. */
    #[ORM\Column(length: 30)]
    private string $experience;

    /** Um de 'PAFA' / 'SCCI' / 'Sem preferência'. */
    #[ORM\Column(length: 30)]
    private string $basePref;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $discord = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $heardAbout = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $motivation;

    /** 'pendente' / 'aprovado' / 'rejeitado'. */
    #[ORM\Column(length: 20)]
    private string $status = 'pendente';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(
        string $name,
        string $email,
        string $cid,
        string $experience,
        string $basePref,
        ?string $discord,
        ?string $heardAbout,
        string $motivation,
    ) {
        $this->name = $name;
        $this->email = $email;
        $this->cid = $cid;
        $this->experience = $experience;
        $this->basePref = $basePref;
        $this->discord = $discord;
        $this->heardAbout = $heardAbout;
        $this->motivation = $motivation;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCid(): string
    {
        return $this->cid;
    }

    public function getExperience(): string
    {
        return $this->experience;
    }

    public function getBasePref(): string
    {
        return $this->basePref;
    }

    public function getDiscord(): ?string
    {
        return $this->discord;
    }

    public function getHeardAbout(): ?string
    {
        return $this->heardAbout;
    }

    public function getMotivation(): string
    {
        return $this->motivation;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }
}
