<?php

namespace App\Entity;

use App\Repository\TipoAeronaveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Perfil de performance de um TIPO de aeronave (não de uma aeronave
 * individual) — peso vazio/MTOW, combustível, distância de decolagem/
 * pouso de referência (nível do mar, ISA, sem vento), usados pelas
 * calculadoras de "Ferramentas do piloto" (`FerramentasController`):
 * peso e balanceamento, e distância de decolagem/pouso ajustada.
 *
 * Ligado a `Aeronave::$tipo` por VALOR de string (`$nome` aqui precisa
 * bater exatamente com o texto salvo em `aeronave.tipo`), não por FK —
 * mesmo padrão que `Voo::$aeronaveReg`↔`Aeronave::$reg` já usa pra
 * relação "fraca" (ver docblock de `Voo`). Faz sentido aqui pelo mesmo
 * motivo: `Aeronave::$tipo` já é texto livre (inclui "outro tipo"
 * digitado à mão em `NovaAeronaveController`), então uma FK travaria
 * exatamente o caso que esse campo já permite hoje. O formulário de
 * cadastro (`/tipos-aeronave`) oferece um `<select>` com os tipos que já
 * existem na frota (ver `AeronaveRepository::findDistinctTipos()`) pra
 * reduzir o risco de erro de digitação que quebraria esse casamento por
 * string — mas nada impede cadastrar um tipo que ainda não tem nenhuma
 * aeronave (ex.: preparar o perfil antes de comprar/importar a
 * aeronave).
 *
 * **Todos os campos de performance nascem `null`, de propósito.** Esta
 * tabela é só a estrutura — quem preenche os números reais (peso vazio,
 * MTOW, distâncias etc., tirados do POH/manual de cada tipo) é o admin,
 * pelo formulário em `/tipos-aeronave`. Nenhum dado foi pré-cadastrado
 * aqui.
 *
 * Unidades: libras (peso), galões (combustível), pés (distância) — os
 * mesmos que o POH/AFM de cada fabricante usa, pra copiar direto sem
 * converter.
 *
 * Escopo deliberadamente limitado: sem envelope de CG/momento (peso e
 * balanceamento aqui é só "peso total vs. MTOW", não substitui o
 * manifesto de peso e balanceamento real de cada voo) e sem tabela de
 * performance completa por altitude/temperatura (distância ajustada usa
 * regra de bolso sobre a distância de referência, não interpolação de
 * gráfico do POH) — ver `FerramentasController`.
 */
#[ORM\Entity(repositoryClass: TipoAeronaveRepository::class)]
#[ORM\Table(name: 'tipo_aeronave')]
#[ORM\UniqueConstraint(name: 'uniq_tipo_aeronave_nome', columns: ['nome'])]
class TipoAeronave
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Precisa bater exatamente com `Aeronave::$tipo` — ver docblock da classe. */
    #[ORM\Column(length: 60)]
    private string $nome;

    /** Peso vazio (basic empty weight), em libras. */
    #[ORM\Column(nullable: true)]
    private ?int $pesoVazioLb = null;

    /** Peso máximo de decolagem (MTOW), em libras. */
    #[ORM\Column(nullable: true)]
    private ?int $pesoMaxDecolagemLb = null;

    /** Capacidade máxima de combustível utilizável, em galões. */
    #[ORM\Column(nullable: true)]
    private ?int $combustivelMaxGal = null;

    /** Consumo médio de cruzeiro, em galões/hora — referência, não usado pra estrutura de peso ainda. */
    #[ORM\Column(nullable: true)]
    private ?float $consumoGph = null;

    /** Distância de decolagem de referência (ground roll ou balanced field, nível do mar, ISA, sem vento, peso máximo), em pés. */
    #[ORM\Column(nullable: true)]
    private ?int $decolagemDistanciaFt = null;

    /** Distância de pouso de referência (nível do mar, ISA, sem vento, peso de pouso típico), em pés. */
    #[ORM\Column(nullable: true)]
    private ?int $pousoDistanciaFt = null;

    /** Notas internas (fonte dos dados, ressalvas, variante do tipo) — não aparece nas calculadoras do piloto. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observacoes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $nome)
    {
        $this->nome = $nome;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = $nome;

        return $this;
    }

    public function getPesoVazioLb(): ?int
    {
        return $this->pesoVazioLb;
    }

    public function setPesoVazioLb(?int $pesoVazioLb): static
    {
        $this->pesoVazioLb = $pesoVazioLb;

        return $this;
    }

    public function getPesoMaxDecolagemLb(): ?int
    {
        return $this->pesoMaxDecolagemLb;
    }

    public function setPesoMaxDecolagemLb(?int $pesoMaxDecolagemLb): static
    {
        $this->pesoMaxDecolagemLb = $pesoMaxDecolagemLb;

        return $this;
    }

    public function getCombustivelMaxGal(): ?int
    {
        return $this->combustivelMaxGal;
    }

    public function setCombustivelMaxGal(?int $combustivelMaxGal): static
    {
        $this->combustivelMaxGal = $combustivelMaxGal;

        return $this;
    }

    public function getConsumoGph(): ?float
    {
        return $this->consumoGph;
    }

    public function setConsumoGph(?float $consumoGph): static
    {
        $this->consumoGph = $consumoGph;

        return $this;
    }

    public function getDecolagemDistanciaFt(): ?int
    {
        return $this->decolagemDistanciaFt;
    }

    public function setDecolagemDistanciaFt(?int $decolagemDistanciaFt): static
    {
        $this->decolagemDistanciaFt = $decolagemDistanciaFt;

        return $this;
    }

    public function getPousoDistanciaFt(): ?int
    {
        return $this->pousoDistanciaFt;
    }

    public function setPousoDistanciaFt(?int $pousoDistanciaFt): static
    {
        $this->pousoDistanciaFt = $pousoDistanciaFt;

        return $this;
    }

    public function getObservacoes(): ?string
    {
        return $this->observacoes;
    }

    public function setObservacoes(?string $observacoes): static
    {
        $this->observacoes = $observacoes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
}
