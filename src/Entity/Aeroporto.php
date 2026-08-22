<?php

namespace App\Entity;

use App\Repository\AeroportoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um aeroporto conhecido pelo sistema — substitui o catálogo fixo que
 * vivia em `public/assets/data/airports.json` (11 entradas, mantidas à
 * mão) por uma tabela de verdade, cadastrável (ver `AeroportoController`,
 * admin-only) em vez de exigir editar um arquivo estático e fazer
 * deploy toda vez que a rede ganha uma pista nova.
 *
 * **Por que isso precisou existir.** Duas dores concretas do catálogo
 * fixo: (1) um voo pra/de um ICAO fora das 11 entradas simplesmente não
 * aparecia no seletor de origem do Agendamento nem no mapa ao vivo — só
 * o Histórico de aeronave já contornava isso desenhando o trajeto real
 * (`AeronaveController::trackPoints()`), as outras telas ainda dependem
 * do catálogo pra saber que um ICAO existe; (2) não havia como saber
 * onde uma aeronave pousou de verdade quando o `destino` que o piloto
 * declarou no plano de voo não bate com a telemetria — ver
 * `AeroportoRepository::findNearest()` e
 * `AcarsIngestaoController::ingerir()`, que resolve o aeroporto real
 * mais próximo do ponto de pouso pra corrigir a posição da aeronave em
 * vez de confiar cegamente no `destino` do payload (pouso alternativo/
 * diversão).
 *
 * `postoAvancadoDe` é só rótulo/organização — um aeroporto marcado como
 * posto avançado de PAFA ou SCCI aparece com essa nota nos popups do
 * mapa (ver `aeronave.js`/`mapa-ao-vivo.js`), mas **não** libera essa
 * pista como opção de base ao cadastrar aeronave
 * (`NovaAeronaveController::BASES_VALIDAS` continua fixo em PAFA/SCCI de
 * propósito — decisão tomada em conversa, ver README).
 *
 * lat/lon são `float` simples, não geography/PostGIS — mesmo padrão que
 * `App\Entity\PosicaoAoVivo` já usa pra coordenada em tempo real; o
 * catálogo é pequeno (dezenas de linhas, não milhões), então
 * `AeroportoRepository::findNearest()` calcula distância Haversine em
 * PHP sobre `findAll()` em vez de depender de uma extensão espacial só
 * pra isso.
 *
 * **`icaoOficial`** distingue um código ICAO de verdade (`true`, o caso
 * de longe mais comum — os 11 hand-cadastrados e a maioria do import
 * global) de um código local/FAA/gps sem ICAO oficial (`false`), usado
 * só nas regiões de missão da rede (Ártico/Antártico + Cone Sul — ver
 * `ImportarAeroportosOurairportsCommand` e README, "Pistas sem ICAO nas
 * regiões de missão"). `$icao` guarda o código de qualquer jeito — não
 * tem um campo separado pra "código local" — porque todo o resto do
 * sistema (busca, popup de mapa, `findOneByIcao()`, correlação de pouso)
 * já trata esse campo como *o* identificador único do aeroporto; o que
 * muda é só que esse identificador, quando `icaoOficial=false`, não é
 * garantidamente único fora do banco (dois países podem usar o mesmo
 * código local por coincidência) nem reconhecido em cartas/simuladores
 * fora deste catálogo. UI mostra um selo discreto nesse caso pra não
 * confundir com um ICAO de verdade.
 */
#[ORM\Entity(repositoryClass: AeroportoRepository::class)]
#[ORM\Table(name: 'aeroporto')]
#[ORM\UniqueConstraint(name: 'uniq_aeroporto_icao', columns: ['icao'])]
class Aeroporto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Código ICAO (ex.: 'PAFA', 'SCNT') — sempre maiúsculo, ver AeroportoController::normalizarIcao(). */
    #[ORM\Column(length: 8)]
    private string $icao;

    #[ORM\Column(length: 120)]
    private string $nome;

    #[ORM\Column(length: 120)]
    private string $cidade;

    #[ORM\Column]
    private float $lat;

    #[ORM\Column]
    private float $lon;

    /**
     * 'PAFA' / 'SCCI' quando este aeroporto é um posto avançado de uma
     * das duas bases, `null` quando não tem essa associação (a maioria
     * — incluindo as próprias PAFA/SCCI, que não são posto avançado de
     * si mesmas). Só rótulo/organização, ver docblock da classe.
     */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $postoAvancadoDe = null;

    /** `true` quando `$icao` é um código ICAO de verdade, `false` quando é um código local/FAA/gps sem ICAO oficial — ver docblock da classe. */
    #[ORM\Column]
    private bool $icaoOficial;

    /**
     * Heading MAGNÉTICO (0-359) da pista principal, quando cadastrado —
     * `null` na maioria dos aeroportos (inclusive os 11 hand-cadastrados
     * e todo o import global, que não trazem essa informação). Usado só
     * por `TelemetryDeriver::recomputeWindcComHeadingDePista()`, chamado
     * de `AcarsIngestaoController::ingerir()` pro aeroporto de POUSO real
     * do voo: quando presente, substitui a aproximação padrão (heading
     * da própria aeronave no toque) pelo través calculado contra a pista
     * de verdade — ver docblock daquele método.
     *
     * Modela só UMA pista por aeroporto (a principal/mais usada, não
     * múltiplas pistas com orientações diferentes) — suficiente pras
     * pistas de bush flying da rede, que normalmente têm uma só. Também
     * **não corrige variação magnética** contra o heading TRUE que o
     * simulador manda (`hdg_true`) — se isso importar na prática (a
     * variação magnética é alta em boa parte do Ártico), é uma
     * imprecisão documentada, não um bug silencioso.
     */
    #[ORM\Column(nullable: true)]
    private ?int $pistaPrincipalHeadingMag = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $icao, string $nome, string $cidade, float $lat, float $lon, bool $icaoOficial = true)
    {
        $this->icao = $icao;
        $this->nome = $nome;
        $this->cidade = $cidade;
        $this->lat = $lat;
        $this->lon = $lon;
        $this->icaoOficial = $icaoOficial;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIcao(): string
    {
        return $this->icao;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getCidade(): string
    {
        return $this->cidade;
    }

    public function getLat(): float
    {
        return $this->lat;
    }

    public function getLon(): float
    {
        return $this->lon;
    }

    public function getPostoAvancadoDe(): ?string
    {
        return $this->postoAvancadoDe;
    }

    public function setPostoAvancadoDe(?string $postoAvancadoDe): static
    {
        $this->postoAvancadoDe = $postoAvancadoDe;

        return $this;
    }

    public function isIcaoOficial(): bool
    {
        return $this->icaoOficial;
    }

    public function getPistaPrincipalHeadingMag(): ?int
    {
        return $this->pistaPrincipalHeadingMag;
    }

    public function setPistaPrincipalHeadingMag(?int $pistaPrincipalHeadingMag): static
    {
        $this->pistaPrincipalHeadingMag = $pistaPrincipalHeadingMag;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
