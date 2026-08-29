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
 *
 * **Atualizado (ACARS fase 3 — posição em tempo real, ver README
 * "Backend: posição em tempo real (ACARS fase 3)"):** `ultimoPingEm`
 * guarda quando o servidor recebeu o heartbeat mais recente (`POST
 * .../voos/iniciar` ou `.../voos/posicao`) — não é mais só o *início* do
 * voo, e sim "ainda está vivo até quando". Isso deixa
 * `getStatusEfetivo()` detectar uma sessão travada muito mais rápido
 * (minutos, não horas) pra quem já manda o heartbeat de posição; quem
 * ainda usa um cliente sem isso continua caindo no comportamento antigo
 * (timeout de `EM_VOO_MAX_HORAS` a partir de `emVooDesde`) — ver
 * `getStatusEfetivo()`. A posição em si (lat/lon/alt/proa) não mora
 * aqui, mora em `App\Entity\PosicaoAoVivo` (uma tabela à parte, de
 * propósito — ver docblock dela).
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

    /** Base de operação — uma das seis bases válidas (ver `NovaAeronaveController::BASES_VALIDAS`). */
    #[ORM\Column(length: 8)]
    private string $base;

    /** ICAO de onde a aeronave está agora — começa igual à base. */
    #[ORM\Column(length: 8)]
    private string $posIcao;

    /** 'Em voo' / 'Disponível' / 'Fora de base'. */
    #[ORM\Column(length: 20)]
    private string $status;

    /** Limite de fator de carga (G) positivo, usado no índice de dificuldade — opcional. */
    #[ORM\Column(nullable: true)]
    private ?float $limiteG = null;

    /**
     * Limite de fator de carga (G) negativo, estrutural — opcional,
     * espelha `$limiteG` (positivo). **Atualizado:** usado por
     * `TelemetryDeriver::deriveEvents()` pra sinalizar excedência do
     * lado negativo (`gmin < limiteGNegativo`), igual ao que `$limiteG`
     * já fazia só pro lado positivo (`gmax > limiteG`).
     *
     * **Atualizado:** coluna nomeada explicitamente (`limite_g_negativo`)
     * — sem isso a estratégia de nomenclatura padrão do Doctrine deriva
     * `limite_gnegativo` (só insere `_` antes de maiúscula que segue
     * minúscula, então o "N" de "GNegativo", colado ao "G" maiúsculo
     * anterior, não ganha separador), divergindo da coluna real criada
     * pela migration (`Version20260828090000`, `limite_g_negativo`) e
     * quebrando toda query que toca `Aeronave` com
     * `SQLSTATE[42703]: column a0_.limite_gnegativo does not exist`.
     */
    #[ORM\Column(name: 'limite_g_negativo', nullable: true)]
    private ?float $limiteGNegativo = null;

    /** VS máxima de pouso, em fpm — opcional. */
    #[ORM\Column(nullable: true)]
    private ?int $vsLimiteFpm = null;

    /** Horômetro — horas totais na frota (ponto de partida no cadastro; não é recalculado a partir do Logbook ainda). */
    #[ORM\Column]
    private int $horas = 0;

    /** Notas internas (manutenção, particularidades do addon) — visível na Frota do Portal (`PortalController::fleetViewModel()`), mas só a piloto logado, nunca numa página pública sem sessão. */
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

    /**
     * Último heartbeat ACARS recebido pra esta aeronave (`iniciar` ou
     * `posicao`) — ver docblock da classe e `getStatusEfetivo()`. `null`
     * sempre que `status` não é `'Em voo'`, ou quando o cliente que
     * mandou `iniciar` ainda não manda heartbeat de posição (fica só no
     * timeout antigo baseado em `emVooDesde`).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $ultimoPingEm = null;

    /**
     * `tipoOperacao` da sessão ACARS em andamento — sidecar de mesma
     * natureza que `emVooDesde`/`ultimoPingEm` acima, mas alimentando a
     * camada de pesquisa meteorológica, não o status "Em voo". `POST
     * .../voos/iniciar` grava aqui quando o payload manda `tipo_operacao`
     * (campo opcional — cliente antigo que não manda continua
     * funcionando igual, só sem captura de pesquisa); `null` sempre que
     * `status` não é `'Em voo'`.
     *
     * É o que permite `.../voos/posicao` (chamado várias vezes durante o
     * voo, muito antes do `Voo` existir de verdade — só nasce no
     * fechamento) decidir se este heartbeat pertence a um voo de
     * Pesquisa e deve disparar `PesquisaAmbienteCaptador`. Ver docblock
     * de `App\Entity\PesquisaAmostra` pro porquê das amostras serem
     * casadas com o `Voo` por matrícula+janela de tempo em vez de FK
     * direta (o `Voo` não existe ainda no momento da captura).
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $emVooTipoOperacao = null;

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

    public function getUltimoPingEm(): ?\DateTimeImmutable
    {
        return $this->ultimoPingEm;
    }

    public function setUltimoPingEm(?\DateTimeImmutable $ultimoPingEm): static
    {
        $this->ultimoPingEm = $ultimoPingEm;

        return $this;
    }

    public function getEmVooTipoOperacao(): ?string
    {
        return $this->emVooTipoOperacao;
    }

    public function setEmVooTipoOperacao(?string $emVooTipoOperacao): static
    {
        $this->emVooTipoOperacao = $emVooTipoOperacao;

        return $this;
    }

    /**
     * true quando a sessão ACARS em andamento nesta aeronave é um voo
     * de Pesquisa — é o guard que `AcarsIngestaoController::posicao()`
     * usa antes de chamar `PesquisaAmbienteCaptador`.
     */
    public function isEmVooDePesquisa(): bool
    {
        return 'Pesquisa' === $this->emVooTipoOperacao;
    }

    /**
     * Quanto tempo uma sessão ACARS pode ficar "Em voo" antes de ser
     * tratada como travada (PC do piloto caiu, nunca chegou o `POST
     * .../voos` de fechamento) — ver `getStatusEfetivo()`. Bem acima do
     * voo mais longo que já existiu no Logbook (~3 h) de propósito: é
     * melhor um voo real e longo nunca ser marcado errado do que essa
     * autocorreção disparar cedo demais.
     *
     * Só vale pra quem NÃO manda heartbeat de posição (`ultimoPingEm`
     * nulo) — é o fallback pro comportamento da fase 2, mantido pra não
     * quebrar um cliente que só chama `iniciar`/fechamento e nunca
     * `posicao`.
     */
    private const EM_VOO_MAX_HORAS = 8;

    /**
     * Quantos minutos sem um heartbeat de posição (`ultimoPingEm`) até
     * tratar a sessão como travada — bem acima do intervalo de ping
     * recomendado pro cliente (10-15 s, ver
     * `docs/payload-telemetria-acars.md` e README), pra tolerar uma
     * rede ruim sem "piscar" o status a cada ping perdido, mas ainda
     * assim detectar um PC travado em minutos em vez de horas.
     */
    private const PING_MAX_MINUTOS = 10;

    /**
     * `status` "de verdade" pra exibir — igual ao valor cru, exceto
     * quando está `'Em voo'` há tempo demais sem confirmação (sessão
     * ACARS travada): nesse caso volta `'Disponível'` sozinho, sem
     * precisar de nenhum job/cron rodando pra corrigir a coluna. A
     * coluna em si só é reescrita quando um novo evento ACARS chega
     * (`POST .../voos/iniciar`, `.../voos/posicao` ou `.../voos`) — até
     * lá, esta função é a única coisa que "esconde" o estado travado da
     * UI.
     *
     * Duas janelas de tolerância, preferindo a mais precisa quando
     * disponível: com heartbeat de posição (`ultimoPingEm` preenchido,
     * cliente atualizado — ver ACARS fase 3), o timeout é
     * `PING_MAX_MINUTOS` desde o último ping; sem heartbeat nenhum
     * (cliente antigo, só chamou `iniciar`), cai no timeout antigo de
     * `EM_VOO_MAX_HORAS` desde o início do voo (`emVooDesde`).
     */
    public function getStatusEfetivo(): string
    {
        if ('Em voo' !== $this->status) {
            return $this->status;
        }
        if (null !== $this->ultimoPingEm) {
            $limite = $this->ultimoPingEm->modify('+'.self::PING_MAX_MINUTOS.' minutes');

            return $limite < new \DateTimeImmutable() ? 'Disponível' : 'Em voo';
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

    public function getLimiteGNegativo(): ?float
    {
        return $this->limiteGNegativo;
    }

    public function setLimiteGNegativo(?float $limiteGNegativo): static
    {
        $this->limiteGNegativo = $limiteGNegativo;

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
