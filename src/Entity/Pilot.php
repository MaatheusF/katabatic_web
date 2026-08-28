<?php

namespace App\Entity;

use App\Repository\PilotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Piloto com login de verdade (substitui o array mock que existia em
 * LoginController::findMockPilot() antes do backend). CID VATSIM é o
 * "username" do sistema (é o que a pessoa digita no login, e é único -
 * cada CID VATSIM já é único por natureza, não precisa reinventar um
 * identificador). `admin` continua o mesmo flag simples de antes, só
 * que agora persistido (ver SolicitacoesController pra quem usa isso).
 *
 * `photo` guarda o caminho público em public/uploads/avatars/ (mesma
 * convenção do PerfilController mock anterior) - continua nullable,
 * nem todo piloto tem foto.
 */
#[ORM\Entity(repositoryClass: PilotRepository::class)]
#[ORM\Table(name: 'pilot')]
#[ORM\UniqueConstraint(name: 'uniq_pilot_cid', columns: ['cid'])]
#[ORM\UniqueConstraint(name: 'uniq_pilot_email', columns: ['email'])]
class Pilot implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $cid;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    /** Hash bcrypt (ver password_hashers em config/packages/security.yaml) - nunca a senha em texto puro. */
    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column]
    private bool $admin = false;

    /**
     * Base preferida do piloto (uma das seis bases válidas — ver
     * `AdesaoController::VALID_BASE_PREF`) — usada no grid de Pilotos em
     * Solicitações. Preenchida a partir da `basePref` do pedido de
     * adesão quando aprovado (ver SolicitacoesController::aprovar());
     * 'Sem preferência' vira 'PAFA' nesse momento, então esta coluna
     * nunca guarda esse valor.
     */
    #[ORM\Column(length: 10)]
    private string $base = 'PAFA';

    /**
     * Flag simples de conta ativa/inativa (grid de Pilotos em
     * Solicitações) — sem fluxo de desativação ainda, só o campo
     * existe por enquanto (todo piloto novo nasce ativo).
     */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Usuário do piloto no AvioDeck (sem o `@`) — opcional, preenchido
     * em `/perfil`. Usado só pra montar um link real em "Referências
     * externas" no relatório de voo (`aviodeck.app/@{usuario}`, ver
     * `VooController`/`voo/index.html.twig`) — nunca validado contra a
     * API do AvioDeck (não existe integração de verdade, só o link).
     */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $aviodeckUsername = null;

    public function __construct(string $cid, string $name, string $email)
    {
        $this->cid = $cid;
        $this->name = $name;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCid(): string
    {
        return $this->cid;
    }

    public function setCid(string $cid): static
    {
        $this->cid = $cid;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): static
    {
        $this->admin = $admin;

        return $this;
    }

    public function getBase(): string
    {
        return $this->base;
    }

    public function setBase(string $base): static
    {
        $this->base = $base;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAviodeckUsername(): ?string
    {
        return $this->aviodeckUsername;
    }

    public function setAviodeckUsername(?string $aviodeckUsername): static
    {
        $this->aviodeckUsername = $aviodeckUsername;

        return $this;
    }

    /**
     * Iniciais derivadas do nome (ex.: "João da Silva" -> "JS") - usadas
     * no avatar de fallback (rail, Perfil) quando não há foto. De
     * propósito NÃO é uma coluna: sempre recalculado a partir do nome
     * atual, exatamente a mesma lógica que já existia em
     * PerfilController::initialsFrom() antes do backend.
     */
    public function getInitials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($parts)) {
            return '??';
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    // --- UserInterface / PasswordAuthenticatedUserInterface (Symfony Security) ---

    /**
     * Identificador único usado pelo Security (equivalente a "username").
     * Aqui é o CID VATSIM - é isso que a pessoa digita no formulário de
     * login (ver LoginFormAuthenticator).
     */
    public function getUserIdentifier(): string
    {
        return $this->cid;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_PILOT'];
        if ($this->admin) {
            $roles[] = 'ROLE_ADMIN';
        }

        return array_unique($roles);
    }

    /**
     * Nada a limpar aqui (não guardamos a senha em texto puro em
     * nenhum momento) - mas o método é exigido pela interface.
     */
    public function eraseCredentials(): void
    {
    }
}
