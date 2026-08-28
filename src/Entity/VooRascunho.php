<?php

namespace App\Entity;

use App\Repository\VooRascunhoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rascunho do formulário de "Novo voo" — substitui o `alert()` mock que
 * "Salvar rascunho" sempre foi (ver `NovoVooController`, `novo-voo.js`).
 *
 * **Por que uma entidade separada, e não um status novo em `Voo`.** O
 * docblock antigo de `NovoVooController` cogitava "um status novo em
 * `Voo` (hoje só `valido`/`acidentado`)" — mas o construtor de `Voo`
 * exige callsign/tipo/origem/destino/aeronave/data-hora/duração/
 * dificuldade, todos não-nulos: um rascunho de verdade (o caso mais
 * comum de querer salvar um) é exatamente um formulário AINDA
 * incompleto, então reaproveitar `Voo` exigiria tornar todas essas
 * colunas opcionais só para acomodar o estado de rascunho — mudança
 * bem maior, e que complica toda leitura de `Voo` no resto do app com
 * checagens de nulidade que só fariam sentido pra essa minoria de
 * linhas. Uma tabela à parte, solta (sem nenhuma coluna obrigatória
 * além do piloto), resolve sem tocar em `Voo`.
 *
 * **Um rascunho por piloto** (constraint de unicidade em `pilot_id`) —
 * salvar de novo sobrescreve o anterior. Não existe (ainda) uma tela de
 * "lista de rascunhos"; a própria tela `/novo-voo` é quem restaura
 * (`NovoVooController::index()` busca o rascunho do piloto logado e
 * `novo-voo.js` pré-preenche o formulário com ele ao carregar) — é
 * literalmente "continuar de onde parou", não um arquivo à parte pra
 * gerenciar.
 *
 * **Escopo: só os campos do modo "Registro manual"** (mais
 * `tipoOperacao`/`aeronaveReg`/`origem`/`destino`, compartilhados com o
 * modo "Importar telemetria"). O modo importar em si NÃO é rascunhável
 * — o `upload_payload.json` que o piloto seleciona não é resalvo aqui
 * (arquivo já existe no disco do piloto, reimportar é trivial, e
 * guardar a telemetria inteira só pra um rascunho seria peso demais
 * pra este caso de uso). `dados['mode']` guarda qual dos dois modos
 * estava ativo, só pra `novo-voo.js` saber pra qual aba voltar ao
 * restaurar — não impede restaurar os campos comuns no modo importar
 * também.
 */
#[ORM\Entity(repositoryClass: VooRascunhoRepository::class)]
#[ORM\Table(name: 'voo_rascunho')]
#[ORM\UniqueConstraint(name: 'uniq_voo_rascunho_pilot', columns: ['pilot_id'])]
class VooRascunho
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Pilot::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Pilot $pilot;

    /**
     * Snapshot cru do formulário — mesmo formato que `collectManualPayload()`
     * monta em `novo-voo.js` (`callsignNum`/`tipoOperacao`/`aeronaveReg`/
     * `origem`/`destino`/`data`/`hora`/`duracao`/`condicao`/`ocorrencias`/
     * `dificuldade`/`objetivo`/`relato`/`simbrief`/`visibilidade`), mais
     * `mode` ('import'/'manual') — nunca validado/normalizado aqui
     * (validação de verdade só roda ao publicar, ver
     * `NovoVooController::publicar()`/`importarPublicar()`); um rascunho
     * pode estar (e provavelmente está) incompleto.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $dados = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $dados
     */
    public function __construct(Pilot $pilot, array $dados)
    {
        $this->pilot = $pilot;
        $this->dados = $dados;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPilot(): Pilot
    {
        return $this->pilot;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDados(): array
    {
        return $this->dados;
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function setDados(array $dados): static
    {
        $this->dados = $dados;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
