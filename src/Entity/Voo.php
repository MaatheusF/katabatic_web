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
 * quando ela existe — fica dentro de `dados` (coluna `jsonb`, ver
 * `Version20260822100000`), num formato bem próximo do que
 * `flights.json` já usava. Ver `VooRepository`,
 * `PortalController::logbookViewModel()` e
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
 *
 * **Atualizado: marcar como acidentado.** Ver `$status` abaixo —
 * substituiu o antigo `VooController::excluir()` (hard-delete, ver git
 * log). Um voo `acidentado` continua na tabela pra sempre (histórico,
 * auditoria), só sai das contagens/estatísticas do piloto
 * (`VooRepository::countsByPilot()`/`countForPilot()`) e ganha um selo
 * visual no relatório e, opcionalmente, na tela de histórico da
 * aeronave (ver `AeronaveController::legViewModel()`).
 *
 * **Atualizado: pouso alternativo (diversão).** `destino` continua
 * sendo o que o piloto declarou no plano de voo (rota pretendida, o que
 * o Logbook sempre mostrou) — `$destinoReal` abaixo é preenchido só
 * quando `AcarsIngestaoController::ingerir()` detecta que o pouso de
 * verdade (telemetria) aconteceu num aeroporto diferente do declarado.
 * Ver `hasPousoAlternativo()` e `AeroportoRepository::findNearest()`.
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

    /**
     * ICAO onde a aeronave pousou de verdade, quando difere de `destino`
     * (pouso alternativo/diversão) — `null` na grande maioria dos voos
     * (pousou onde o plano dizia, ou não tem telemetria pra saber). Ver
     * `hasPousoAlternativo()` e docblock da classe.
     */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $destinoReal = null;

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
     * O tipo DBAL continua `Types::JSON` de propósito, mesmo com a
     * coluna física sendo `jsonb` no Postgres (ver
     * `Version20260822100000`): o tipo `json` do Doctrine só faz
     * `json_encode`/`json_decode` em PHP, não olha o subtipo físico da
     * coluna — então `jsonb` (indexa, faz `->`/`->>`/`@>` sem
     * reparsear) não exige (nem deve virar) outro `ORM\Column`. Se um
     * `doctrine:migrations:diff` no futuro sugerir voltar isto pra
     * `json`, é falso positivo — ignore o diff nessa coluna.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $dados = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public const STATUS_VALIDO = 'valido';
    public const STATUS_ACIDENTADO = 'acidentado';

    /**
     * `valido` (padrão) ou `acidentado` — marcado pelo próprio piloto
     * quando a perna não devia ter contado (acidente no meio do
     * trajeto, sessão corrompida, etc. — ver
     * `VooController::marcarAcidentado()`). Substitui o hard-delete que
     * esta tela tinha antes: o voo continua na tabela (auditoria), só
     * fica marcado e some das contagens de horas/voos do piloto.
     */
    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_VALIDO;

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

    public function getDestinoReal(): ?string
    {
        return $this->destinoReal;
    }

    public function setDestinoReal(?string $destinoReal): static
    {
        $this->destinoReal = $destinoReal;

        return $this;
    }

    /**
     * true quando o pouso de verdade (telemetria) aconteceu num
     * aeroporto diferente do `destino` declarado no plano de voo — ver
     * `AcarsIngestaoController::ingerir()`, que é quem preenche
     * `destinoReal` (nunca este método).
     */
    public function hasPousoAlternativo(): bool
    {
        return null !== $this->destinoReal && $this->destinoReal !== $this->destino;
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

    /**
     * Fotos anexadas pelo piloto ao relatório deste voo — mesma ideia
     * do relato acima: mora dentro de `dados` (nunca vem do ACARS,
     * `AcarsIngestaoController::ingerir()` nunca escreve esta chave),
     * uma lista de referências, não os arquivos em si (esses ficam em
     * disco, ver `App\Service\FotoVooUploader` e
     * `VooController::adicionarFotos()`/`removerFoto()`).
     *
     * Sem trava de visibilidade: quem já pode ver o relatório deste
     * voo (hoje, só o próprio piloto — o Logbook ainda não tem uma
     * visão pública entre pilotos, ver docblock de
     * `VooController::telemetria()`) vê as fotos junto, sem checagem
     * extra. Se um dia existir uma visão de Logbook cross-piloto, as
     * fotos já aparecem nela de graça.
     *
     * @return list<array{arquivo: string, enviadoEm: string}>
     */
    public function getFotos(): array
    {
        return $this->dados['fotos'] ?? [];
    }

    /**
     * @param list<array{arquivo: string, enviadoEm: string}> $fotos
     */
    public function setFotos(array $fotos): static
    {
        $this->dados['fotos'] = $fotos;

        return $this;
    }

    /**
     * Link do plano de voo no SimBrief (OFP) pra ESTE voo especificamente
     * — colado pelo próprio piloto (ver `VooController::salvarSimbrief()`),
     * não derivado de nenhum ID cadastrado no perfil. SimBrief não expõe
     * uma URL pública estável pra "o plano de voo Xis de tal data" (só
     * "o último plano gerado por tal Pilot ID", que fica desatualizado
     * assim que o piloto gera outro OFP) — pedir pro piloto colar o link
     * de verdade que o próprio SimBrief entrega ao gerar o plano é o
     * único jeito confiável de linkar o OFP certo. Mesma ideia de
     * `pilotReport`: mora dentro de `dados`, nunca vem do ACARS.
     */
    public function getSimbriefLink(): ?string
    {
        return $this->dados['simbriefLink'] ?? null;
    }

    public function setSimbriefLink(?string $simbriefLink): static
    {
        $this->dados['simbriefLink'] = $simbriefLink;

        return $this;
    }

    /**
     * PDF do plano de voo (OFP, tipicamente exportado do SimBrief)
     * anexado pelo piloto a este voo — diferente de `$simbriefLink`
     * acima (aquele é só um link pra fora; este é o arquivo de verdade,
     * servido direto por esta aplicação, ver
     * `VooController::adicionarPlanoVoo()` e `App\Service\
     * PlanoVooUploader`). Um só por voo (não é galeria, como
     * `$fotos`): anexar um novo substitui o anterior. Mora dentro de
     * `dados`, mesma pasta em disco que as fotos
     * (`public/uploads/voos/{codigo}/`), então `VooController::excluir()`
     * já limpa o arquivo de graça junto com a galeria, sem código
     * extra.
     *
     * @return array{arquivo: string, nomeOriginal: ?string, enviadoEm: string}|null
     */
    public function getPlanoVooPdf(): ?array
    {
        return $this->dados['planoVooPdf'] ?? null;
    }

    /**
     * @param array{arquivo: string, nomeOriginal: ?string, enviadoEm: string}|null $planoVooPdf
     */
    public function setPlanoVooPdf(?array $planoVooPdf): static
    {
        $this->dados['planoVooPdf'] = $planoVooPdf;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isAcidentado(): bool
    {
        return self::STATUS_ACIDENTADO === $this->status;
    }

    /**
     * Marca este voo como acidentado — não desfaz sozinho o efeito na
     * aeronave (horas/posição), isso é responsabilidade de quem chama
     * (ver `VooController::marcarAcidentado()`).
     */
    public function marcarAcidentado(): static
    {
        $this->status = self::STATUS_ACIDENTADO;

        return $this;
    }
}
