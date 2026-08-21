<?php

namespace App\Entity;

use App\Repository\VooRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um voo do Logbook — substitui os dois lugares que existiam antes
 * (array mock em `PortalController::logbook()` e o arquivo estático
 * `public/assets/data/flights.json`) por uma única fonte de verdade.
 *
 * As colunas de verdade (`codigo`, `callsign`, `tipoOperacao`, `origem`,
 * `destino`, `aeronaveReg`, `startedAt`, `tempoMin`, `dificuldade`) são
 * o que a tela de Logbook filtra/ordena; o resto — incluindo a
 * telemetria inteira (track/prof/env/eventos/fases/parcelas/toque)
 * quando ela existe — fica dentro de `dados` (coluna `json`), num
 * formato bem próximo do que `flights.json` já usava. Ver
 * `VooRepository`, `PortalController::logbookViewModel()` e
 * `VooController::telemetriaViewModel()` pra como cada lado lê esse
 * blob.
 *
 * `codigo` só existe pros voos que têm telemetria de verdade gravada
 * (os 3 voos de teste do ACARS, ver README) — os outros (histórico só
 * narrativo, sem gravação) ficam com `codigo` null e sem chave
 * `telemetria` dentro de `dados`; é assim que `VooController` decide
 * quais voos aparecem na lista de telemetria (só quem tem `codigo`) e
 * `portal.js` decide quais linhas do Logbook são clicáveis (mesma
 * lógica de antes, só que lendo do banco agora).
 */
#[ORM\Entity(repositoryClass: VooRepository::class)]
#[ORM\Table(name: 'voo')]
#[ORM\UniqueConstraint(name: 'uniq_voo_codigo', columns: ['codigo'])]
class Voo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pilot::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Pilot $pilot;

    /** ID de sessão do ACARS (ex.: '20260819_032200_KBT118') — null quando não há telemetria gravada. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $codigo = null;

    #[ORM\Column(length: 16)]
    private string $callsign;

    /** 'Carga' / 'Pesquisa' / 'Pessoal' / 'Reposicionamento'. */
    #[ORM\Column(length: 30)]
    private string $tipoOperacao;

    #[ORM\Column(length: 8)]
    private string $origem;

    #[ORM\Column(length: 8)]
    private string $destino;

    /** Matrícula da aeronave (ver Frota — ainda mock, ver README). */
    #[ORM\Column(length: 16)]
    private string $aeronaveReg;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    /** Duração total narrativa do voo, em minutos (vira "H:MM" na view model). */
    #[ORM\Column]
    private int $tempoMin;

    /** 0-100 — 'dif' no Logbook, 'score' no relatório de voo. */
    #[ORM\Column]
    private int $dificuldade;

    /**
     * Tudo que não precisa ser coluna de verdade pra filtrar/ordenar o
     * Logbook: rota, modelo, condição/tag, ocorrências, distância,
     * combustível, carga, tempos de solo/ar, METAR, o relato do piloto
     * (`pilotReport`, editável de verdade — ver VooController::relato())
     * e, quando existe telemetria, a chave `telemetria` com o mesmo
     * formato de objeto que `flights.json` usava (label/wx/track/prof/
     * env/events/phases/parcels/score/dur/... /td/orig/dest).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $dados = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Pilot $pilot,
        string $callsign,
        string $tipoOperacao,
        string $origem,
        string $destino,
        string $aeronaveReg,
        \DateTimeImmutable $startedAt,
        int $tempoMin,
        int $dificuldade,
    ) {
        $this->pilot = $pilot;
        $this->callsign = $callsign;
        $this->tipoOperacao = $tipoOperacao;
        $this->origem = $origem;
        $this->destino = $destino;
        $this->aeronaveReg = $aeronaveReg;
        $this->startedAt = $startedAt;
        $this->tempoMin = $tempoMin;
        $this->dificuldade = $dificuldade;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPilot(): Pilot
    {
        return $this->pilot;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(?string $codigo): static
    {
        $this->codigo = $codigo;

        return $this;
    }

    public function getCallsign(): string
    {
        return $this->callsign;
    }

    public function getTipoOperacao(): string
    {
        return $this->tipoOperacao;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function getDestino(): string
    {
        return $this->destino;
    }

    public function getAeronaveReg(): string
    {
        return $this->aeronaveReg;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getTempoMin(): int
    {
        return $this->tempoMin;
    }

    public function getDificuldade(): int
    {
        return $this->dificuldade;
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

        return $this;
    }

    /**
     * true quando este voo tem telemetria de verdade gravada (chave
     * `telemetria` dentro de `dados`) — ver docblock da classe.
     */
    public function hasTelemetria(): bool
    {
        return null !== $this->codigo && isset($this->dados['telemetria']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTelemetria(): ?array
    {
        return $this->dados['telemetria'] ?? null;
    }

    /**
     * Relato escrito pelo piloto sobre o voo (debrief) — a única parte
     * desta tela que é editável pelo próprio piloto depois do voo
     * encerrado. Antes desta fatia, "Salvar relato" só mudava
     * `F.pilot_report` em memória no navegador (ver voo.js/git log);
     * agora é uma coluna de verdade (dentro de `dados`, ver
     * VooController::relato()).
     */
    public function getPilotReport(): ?string
    {
        return $this->dados['pilotReport'] ?? null;
    }

    public function setPilotReport(?string $pilotReport): static
    {
        $this->dados['pilotReport'] = $pilotReport;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
