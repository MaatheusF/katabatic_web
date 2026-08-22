<?php

namespace App\Entity;

use App\Repository\AgendamentoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma perna agendada pra uma aeronave da frota — reserva um voo
 * **futuro** ("vou pegar essa aeronave hoje à noite"), diferente de
 * `Voo` (que registra um voo que **já aconteceu**). Substitui o array
 * mock que `AgendamentoController` montava só pra popular o estado
 * inicial da tela — ver README, "Agendamento de voo".
 *
 * `aeronave` é FK de verdade (diferente de `Voo::$aeronaveReg`, que é
 * texto solto por ter sido criada antes de `Aeronave` existir como
 * tabela — ver docblock de lá): como `Agendamento` nasce depois da
 * frota já ser schema real, não faz sentido repetir aquele gap aqui.
 *
 * `piloto` continua texto livre (não é FK pra `Pilot`) de propósito:
 * o formulário só sugere o nome do piloto logado, mas permite
 * sobrescrever pra agendar em nome de outra pessoa (quem está de
 * fato vai voar essa perna pode não ser quem está com a sessão
 * aberta) — mesma liberdade que a versão mock sempre teve.
 *
 * Sem coluna de "quem criou" ou "status" (aberto/em andamento/
 * concluído) de propósito: esta tela não restringe quem edita/remove
 * o quê (qualquer piloto logado mexe em qualquer agendamento — é uma
 * agenda operacional compartilhada, não uma lista pessoal), e a
 * promoção automática pra "em andamento"/"concluído" quando o feed
 * real do ACARS casar com um agendamento é trabalho de uma fatia
 * futura (ver README, "Ligação com o ACARS") — adicionar a coluna
 * agora sem o código que a usa só criaria um campo morto.
 */
#[ORM\Entity(repositoryClass: AgendamentoRepository::class)]
#[ORM\Table(name: 'agendamento')]
class Agendamento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Aeronave::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Aeronave $aeronave;

    #[ORM\Column(length: 8)]
    private string $origem;

    #[ORM\Column(length: 8)]
    private string $destino;

    /** 'Carga' / 'Pesquisa' / 'Pessoal' / 'Reposicionamento' — mesmos valores de `Voo::$tipoOperacao`. */
    #[ORM\Column(length: 30)]
    private string $tipoOperacao;

    /** Nome livre, não FK — ver docblock da classe. */
    #[ORM\Column(length: 120)]
    private string $piloto;

    /** Início da janela de horário (UTC). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $de;

    /** Fim da janela de horário (UTC) — sempre depois de `de` (ver `AgendamentoController`, que garante isso na validação). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $ate;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notas = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Aeronave $aeronave,
        string $origem,
        string $destino,
        string $tipoOperacao,
        string $piloto,
        \DateTimeImmutable $de,
        \DateTimeImmutable $ate,
        ?string $notas = null,
    ) {
        $this->aeronave = $aeronave;
        $this->origem = $origem;
        $this->destino = $destino;
        $this->tipoOperacao = $tipoOperacao;
        $this->piloto = $piloto;
        $this->de = $de;
        $this->ate = $ate;
        $this->notas = $notas;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAeronave(): Aeronave
    {
        return $this->aeronave;
    }

    public function setAeronave(Aeronave $aeronave): static
    {
        $this->aeronave = $aeronave;

        return $this;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function setOrigem(string $origem): static
    {
        $this->origem = $origem;

        return $this;
    }

    public function getDestino(): string
    {
        return $this->destino;
    }

    public function setDestino(string $destino): static
    {
        $this->destino = $destino;

        return $this;
    }

    public function getTipoOperacao(): string
    {
        return $this->tipoOperacao;
    }

    public function setTipoOperacao(string $tipoOperacao): static
    {
        $this->tipoOperacao = $tipoOperacao;

        return $this;
    }

    public function getPiloto(): string
    {
        return $this->piloto;
    }

    public function setPiloto(string $piloto): static
    {
        $this->piloto = $piloto;

        return $this;
    }

    public function getDe(): \DateTimeImmutable
    {
        return $this->de;
    }

    public function setDe(\DateTimeImmutable $de): static
    {
        $this->de = $de;

        return $this;
    }

    public function getAte(): \DateTimeImmutable
    {
        return $this->ate;
    }

    public function setAte(\DateTimeImmutable $ate): static
    {
        $this->ate = $ate;

        return $this;
    }

    public function getNotas(): ?string
    {
        return $this->notas;
    }

    public function setNotas(?string $notas): static
    {
        $this->notas = $notas;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
