<?php

namespace App\Entity;

use App\Repository\AeronaveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma aeronave da frota — substitui os quatro lugares que guardavam
 * essencialmente a mesma lista mock (`PortalController::fleet()`,
 * `NovoVooController::aircraftFleet()`, `AeronaveController::fleet()`
 * e `MapaAoVivoController::liveFlights()`/`parkedAircraft()`) por uma
 * única fonte de verdade.
 *
 * `status`/`posIcao` são estado operacional (onde a aeronave está
 * agora, se está voando). **Atualizado (ingestão ACARS, fase 2 — ver
 * README "Backend: ingestão ACARS (MVP)"):** `status` agora é
 * atualizado de verdade por dois eventos — `POST .../voos/iniciar`
 * marca `'Em voo'` quando a gravação começa, `POST .../voos` (fim do
 * voo) devolve `'Disponível'` e atualiza `posIcao` pro destino. Uma
 * aeronave cadastrada manualmente sem nunca ter voado com ACARS
 * continua exatamente como antes (fixa em `'Disponível'`, posição =
 * base) — o único jeito de mudar isso ainda é o ACARS ou mexer direto
 * no banco.
 *
 * `emVooDesde` existe só pra `getStatusEfetivo()` conseguir se
 * autocorrigir se o cliente ACARS cair no meio do voo (PC do piloto
 * trava, perde internet e nunca manda o `POST .../voos` de fechamento):
 * sem isso, a aeronave ficaria marcada `'Em voo'` pra sempre. Ver
 * `getStatusEfetivo()`.
 */
#[ORM\Entity(repositoryClass: AeronaveRepository::class)]
#[ORM\Table(name: 'aeronave')]
#[ORM\UniqueConstraint(name: 'uniq_aeronave_reg', columns: ['reg'])]
class Aeronave
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Matrícula completa, com prefixo (ex.: 'CC-KBA', 'N208KB'). */
    #[ORM\Column(length: 16)]
    private string $reg;

    /** País de registro — 'CL' (prefixo CC-) ou 'US' (prefixo N). */
    #[ORM\Column(length: 2)]
    private string $pais;

    /** Tipo/modelo (ex.: 'Cessna 208B Grand Caravan') — texto livre, inclui "outro tipo" digitado à mão. */
    #[ORM\Column(length: 60)]
    private string $tipo;

    /** Base de operação — 'PAFA' ou 'SCCI'. */
    #[ORM\Column(length: 8)]
    private string $base;

    /** ICAO de onde a aeronave está agora — começa igual à base. */
    #[ORM\Column(length: 8)]
    private string $posIcao;

    /** 'Em voo' / 'Disponível' / 'Fora de base'. */
    #[ORM\Column(length: 20)]
    private string $status;

    /** Limite de fator de carga (G), usado no índice de dificuldade — opcional. */
    #[ORM\Column(nullable: true)]
    private ?float $limiteG = null;

    /** VS máxima de pouso, em fpm — opcional. */
    #[ORM\Column(nullable: true)]
    private ?int $vsLimiteFpm = null;

    /** Horômetro — horas totais na frota (ponto de partida no cadastro; não é recalculado a partir do Logbook ainda). */
    #[ORM\Column]
    private int $horas = 0;

    /** Notas internas (manutenção, particularidades do addon) — não aparece no site público. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observacoes = null;

    /**
     * Quando `POST .../voos/iniciar` marcou esta aeronave como `'Em
     * voo'` — só usado pra `getStatusEfetivo()` detectar sessão travada
     * (ver docblock da classe). `null` sempre que `status` não é
     * `'Em voo'`.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emVooDesde = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $reg, string $pais, string $tipo, string $base)
    {
        $this->reg = $reg;
        $this->pais = $pais;
        $this->tipo = $tipo;
        $this->base = $base;
        $this->posIcao = $base;
        $this->status = 'Disponível';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReg(): string
    {
        return $this->reg;
    }

    public function getPais(): string
    {
        return $this->pais;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getBase(): string
    {
        return $this->base;
    }

    public function getPosIcao(): string
    {
        return $this->posIcao;
    }

    public function setPosIcao(string $posIcao): static
    {
        $this->posIcao = $posIcao;

        return $this;
    }

    /**
     * `status` cru, exatamente o que está na coluna — usado só
     * internamente por `getStatusEfetivo()` e por quem grava o campo
     * (`AcarsIngestaoController`). O resto do app (view models,
     * `getStatusTag()`) sempre lê `getStatusEfetivo()`, nunca este.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getEmVooDesde(): ?\DateTimeImmutable
    {
        return $this->emVooDesde;
    }

    public function setEmVooDesde(?\DateTimeImmutable $emVooDesde): static
    {
        $this->emVooDesde = $emVooDesde;

        return $this;
    }

    /**
     * Quanto tempo uma sessão ACARS pode ficar "Em voo" antes de ser
     * tratada como travada (PC do piloto caiu, nunca chegou o `POST
     * .../voos` de fechamento) — ver `getStatusEfetivo()`. Bem acima do
     * voo mais longo que já existiu no Logbook (~3 h) de propósito: é
     * melhor um voo real e longo nunca ser marcado errado do que essa
     * autocorreção disparar cedo demais.
     */
    private const EM_VOO_MAX_HORAS = 8;

    /**
     * `status` "de verdade" pra exibir — igual ao valor cru, exceto
     * quando está `'Em voo'` há tempo demais sem confirmação (sessão
     * ACARS travada): nesse caso volta `'Disponível'` sozinho, sem
     * precisar de nenhum job/cron rodando pra corrigir a coluna. A
     * coluna em si só é reescrita quando um novo evento ACARS chega
     * (`POST .../voos/iniciar` ou `.../voos`) — até lá, esta função é a
     * única coisa que "esconde" o estado travado da UI.
     */
    public function getStatusEfetivo(): string
    {
        if ('Em voo' !== $this->status) {
            return $this->status;
        }
        if (null === $this->emVooDesde) {
            return 'Disponível';
        }
        $limite = $this->emVooDesde->modify('+'.self::EM_VOO_MAX_HORAS.' hours');

        return $limite < new \DateTimeImmutable() ? 'Disponível' : 'Em voo';
    }

    /**
     * Tag de cor pro `<span class="tag tag-...">` — 100% determinada
     * por `getStatusEfetivo()` (não pela coluna crua), nunca guardada
     * separada (evita as colunas saindo de sincronia).
     */
    public function getStatusTag(): string
    {
        return match ($this->getStatusEfetivo()) {
            'Em voo' => 'warn',
            'Fora de base' => 'bad',
            default => 'ok',
        };
    }

    public function getLimiteG(): ?float
    {
        return $this->limiteG;
    }

    public function setLimiteG(?float $limiteG): static
    {
        $this->limiteG = $limiteG;

        return $this;
    }

    public function getVsLimiteFpm(): ?int
    {
        return $this->vsLimiteFpm;
    }

    public function setVsLimiteFpm(?int $vsLimiteFpm): static
    {
        $this->vsLimiteFpm = $vsLimiteFpm;

        return $this;
    }

    public function getHoras(): int
    {
        return $this->horas;
    }

    public function setHoras(int $horas): static
    {
        $this->horas = $horas;

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
}
