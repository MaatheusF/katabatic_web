<?php

namespace App\Repository;

use App\Entity\Aeroporto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Aeroporto>
 */
class AeroportoRepository extends ServiceEntityRepository
{
    /**
     * ICAO das seis bases da rede - mesma lista que
     * `NovaAeronaveController::BASES_VALIDAS`/`AeroportoController::BASES_VALIDAS`/
     * `AdesaoController::VALID_BASE_PREF`, repetida aqui (não importada de
     * lá) pra não criar uma dependência de Controller dentro do
     * Repository só por uma constante pequena - mesma decisão de sempre,
     * ver docblock de `App\Entity\Aeroporto`.
     *
     * **Atualizado: bases sazonais.** PAFA/SCCI eram as duas únicas até
     * esta fatia; SLLP (La Paz/El Alto, Bolívia), VNKT (Tribhuvan Intl.,
     * Catmandu, Nepal), WAJW (Wamena, Nova Guiné) e VQPR (Paro, Butão)
     * entraram como bases principais novas - locais extremos de
     * propósito (altitude, relevo, aproximação sem instrumento), pra dar
     * variedade "sazonal" de tema/identidade à rede sem nenhum trava de
     * calendário (ver `app:importar-bases-sazonais` e README, "Bases
     * sazonais"). A base do Nepal é Catmandu, não Lukla (VNLK) - Lukla
     * é destino, não hub (pista de mão única, sem infraestrutura pra
     * basear frota); entra como posto avançado de VNKT, mesma
     * classificação de qualquer outro posto. BGSF (Kangerlussuaq,
     * Groenlândia) é só posto avançado de PAFA, e cada uma das quatro
     * bases novas ganhou 3 postos avançados próprios - nenhum desses
     * entra aqui, continua a mesma regra de sempre: virar posto avançado
     * de uma base não torna esse ICAO uma base nova.
     */
    private const BASES = ['PAFA', 'SCCI', 'SLLP', 'VNKT', 'WAJW', 'VQPR'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Aeroporto::class);
    }

    public function findOneByIcao(string $icao): ?Aeroporto
    {
        return $this->findOneBy(['icao' => strtoupper($icao)]);
    }

    public function existsByIcao(string $icao): bool
    {
        return null !== $this->findOneByIcao($icao);
    }

    /**
     * @return list<Aeroporto>
     */
    public function findAllOrderedByIcao(): array
    {
        return $this->findBy([], ['icao' => 'ASC']);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Todo ICAO já cadastrado, num set (`['PAFA' => true, ...]`) em vez de
     * lista - pensado pro import em massa (`ImportarAeroportosOurairportsCommand`)
     * checar "esse ICAO já existe?" com `isset()` em memória em vez de uma
     * query `existsByIcao()` por linha do CSV (milhares de round-trips ao
     * banco só pra dedup seria a parte mais lenta do import de longe).
     *
     * @return array<string, true>
     */
    public function findTodosIcaosComoSet(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.icao')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['icao']] = true;
        }

        return $out;
    }

    /**
     * As duas bases (PAFA/SCCI) mais todo aeroporto marcado como posto
     * avançado de uma delas - o conjunto "de referência" que faz sentido
     * mostrar sem o piloto pedir (mapas, catálogo default da tela admin).
     * Pequeno e estável por definição (cresce só quando um admin marca
     * mais um posto avançado em `/aeroportos`), ao contrário do catálogo
     * inteiro - ver `findCatalogoReferenciaArray()`.
     *
     * @return list<Aeroporto>
     */
    public function findBasesEPostosAvancados(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.icao IN (:bases)')
            ->orWhere('a.postoAvancadoDe IN (:bases)')
            ->setParameter('bases', self::BASES)
            ->orderBy('a.icao', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca por ICAO (prefixo) ou nome/cidade (substring), case-insensitive,
     * limitada a `$limite` resultados - alimenta tanto o combobox de
     * origem/destino do Agendamento quanto a busca da tela admin de
     * Aeroportos (ver `AeroportoController::buscar()`). Sem essa busca,
     * um catálogo de milhares de linhas (depois do import global via
     * OurAirports) não teria como ser navegado por um `<select>` nem por
     * uma tabela sem paginação - ver README, "Backend: importação global
     * de aeroportos".
     *
     * ICAO como prefixo (não substring) porque é assim que um piloto
     * digita quando já sabe o código (ex.: "PA" pra ver os PAxx do
     * Alasca) - nome/cidade como substring porque aí a busca costuma ser
     * por uma palavra no meio ("Natales", não "Puerto").
     *
     * @return list<Aeroporto>
     */
    public function buscar(string $q, int $limite = 20): array
    {
        $q = trim($q);
        if ('' === $q) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->where('a.icao LIKE :prefixo')
            ->orWhere('a.nome LIKE :substring')
            ->orWhere('a.cidade LIKE :substring')
            ->setParameter('prefixo', strtoupper($q).'%')
            ->setParameter('substring', '%'.$q.'%')
            ->orderBy('a.icao', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Formato `{ ICAO: { name, city, lat, lon, postoAvancadoDe, icaoOficial } }`
     * que `public/assets/data/airports.json` sempre teve (mais
     * `icaoOficial`, adicionado com as pistas sem ICAO das regiões de
     * missão — ver README, "Pistas sem ICAO nas regiões de missão")
     * - `catalogoArrayFor()` faz a conversão pra reaproveitar entre esta
     * (catálogo inteiro - hoje só usada internamente/pra depuração, não é
     * mais o que `AeroportoController::catalogo()` serve pras telas, ver
     * `findCatalogoReferenciaArray()`) e a versão pequena.
     *
     * @return array<string, array{name: string, city: string, lat: float, lon: float, postoAvancadoDe: ?string, icaoOficial: bool, isBase: bool}>
     */
    public function findAllAsCatalogArray(): array
    {
        return $this->catalogoArrayFor($this->findAllOrderedByIcao());
    }

    /**
     * **O que `AeroportoController::catalogo()` de fato serve pras telas**
     * desde a importação global (ver README) - só bases + postos
     * avançados, no mesmo formato de sempre. Antes do import global o
     * catálogo inteiro cabia tranquilo num JSON embutido em toda página
     * de mapa; com milhares de aeroportos isso deixou de ser viável (JSON
     * de alguns KB viraria vários MB baixados em toda visita a
     * `/aeronave/{reg}`, `/mapa-ao-vivo` e `/agendamentos`, e o Mapa ao
     * vivo desenharia um ponto pra cada aeroporto do mundo, não só os da
     * rede). Um ICAO fora desse conjunto pequeno ainda é encontrável via
     * `buscar()` - é assim que o combobox de origem/destino do
     * Agendamento e a busca da tela admin alcançam o catálogo inteiro sob
     * demanda, sem embutir tudo de cara.
     *
     * @return array<string, array{name: string, city: string, lat: float, lon: float, postoAvancadoDe: ?string, icaoOficial: bool, isBase: bool}>
     */
    public function findCatalogoReferenciaArray(): array
    {
        return $this->catalogoArrayFor($this->findBasesEPostosAvancados());
    }

    /**
     * Mesmo formato de `findCatalogoReferenciaArray()` (bases + postos
     * avançados), mas GARANTE que todo ICAO em `$icaosExtras` também
     * entra, mesmo que não seja nenhum dos dois — usado por
     * `MapaAoVivoController` pra montar o catálogo daquela tela
     * especificamente, nunca `AeroportoController::catalogo()` (que
     * continua só bases + postos, de propósito, pras outras telas que
     * o consomem). Sem isso, uma aeronave estacionada (ou um voo com
     * origem/destino) num aeroporto qualquer do catálogo grande — a
     * imensa maioria, desde a importação do OurAirports — nunca teria
     * coordenada pro Mapa ao vivo desenhar, e sumiria do mapa em
     * silêncio (`AIRPORTS[pa.pos]` undefined em `mapa-ao-vivo.js`, ver
     * README). Uma única query extra, só pelos ICAOs que faltam (nunca
     * N+1) — o conjunto continua pequeno (bases/postos + o tamanho da
     * frota), longe de despejar o catálogo inteiro.
     *
     * @param list<string> $icaosExtras
     *
     * @return array<string, array{name: string, city: string, lat: float, lon: float, postoAvancadoDe: ?string, icaoOficial: bool, isBase: bool}>
     */
    public function findCatalogoReferenciaArrayComExtras(array $icaosExtras): array
    {
        $catalogo = $this->findCatalogoReferenciaArray();

        $faltando = [];
        foreach ($icaosExtras as $icao) {
            $icao = strtoupper(trim($icao));
            if ('' !== $icao && !isset($catalogo[$icao])) {
                $faltando[$icao] = true;
            }
        }
        if ([] === $faltando) {
            return $catalogo;
        }

        $extras = $this->createQueryBuilder('a')
            ->andWhere('a.icao IN (:icaos)')
            ->setParameter('icaos', array_keys($faltando))
            ->getQuery()
            ->getResult();

        return $catalogo + $this->catalogoArrayFor($extras);
    }

    /**
     * @param list<Aeroporto> $lista
     *
     * @return array<string, array{name: string, city: string, lat: float, lon: float, postoAvancadoDe: ?string, icaoOficial: bool, isBase: bool}>
     */
    /**
     * `isBase` foi acrescentado ao formato pra `mapa-ao-vivo.js`/`aeronave.js`
     * poderem desenhar a bolinha das seis bases principais maior que a dos
     * postos avançados (pedido do piloto) sem duplicar a lista `self::BASES`
     * em JS — `postoAvancadoDe === null` sozinho não serve pra isso porque
     * também é null em qualquer aeroporto "extra" que não é base nem posto
     * (ver `findCatalogoReferenciaArrayComExtras()`), então o JS calcularia
     * "é base" errado pra esses casos.
     */
    private function catalogoArrayFor(array $lista): array
    {
        $out = [];
        foreach ($lista as $a) {
            $out[$a->getIcao()] = [
                'name' => $a->getNome(),
                'city' => $a->getCidade(),
                'lat' => $a->getLat(),
                'lon' => $a->getLon(),
                'postoAvancadoDe' => $a->getPostoAvancadoDe(),
                'icaoOficial' => $a->isIcaoOficial(),
                'isBase' => \in_array($a->getIcao(), self::BASES, true),
            ];
        }

        return $out;
    }

    /**
     * Postos avançados de uma base (`$baseIcao` = 'PAFA' ou 'SCCI'),
     * ordenados por ICAO — usado pela aba Bases do Portal
     * (`PortalController::bases()`) pra listar as "Estações avançadas"
     * de cada base a partir do catálogo de verdade, em vez do array
     * mock fixo que existia antes. Cresce sozinho: um admin cadastrando
     * um aeroporto novo em `/aeroportos` e marcando-o como posto
     * avançado já aparece aqui, sem precisar mexer em código.
     *
     * @return list<Aeroporto>
     */
    public function findPostosAvancadosDe(string $baseIcao): array
    {
        return $this->findBy(['postoAvancadoDe' => strtoupper($baseIcao)], ['icao' => 'ASC']);
    }

    /**
     * Raio de busca padrão pro aeroporto real mais próximo de um ponto
     * de pouso (ver `findNearest()`) — bem folgado pro tamanho normal de
     * um aeródromo mais a imprecisão de usar a última amostra de
     * telemetria (1 Hz) em vez do toque exato quando não há evento
     * `touchdown` (ver `AcarsIngestaoController::ingerir()`), mas ainda
     * bem menor que a distância típica entre dois aeroportos vizinhos
     * nesta rede (dezenas a centenas de nm, ver README "Bases") — não
     * dá pra confundir um pouso em PFYU com um em PABT por acidente.
     */
    public const RAIO_PADRAO_KM = 15.0;

    /**
     * Aeroporto cadastrado mais próximo de `(lat, lon)`, dentro de
     * `$raioKm` — `null` quando nenhum está perto o bastante (pouso fora
     * de qualquer aeródromo conhecido: água, campo aberto, ou uma pista
     * que ainda não foi cadastrada).
     *
     * **Atualizado pro catálogo global.** Antes do import via OurAirports
     * (ver README) o catálogo inteiro cabia numa dúzia de linhas e um
     * loop de Haversine em PHP sobre `findAll()` era trivial; com
     * milhares de linhas isso viraria a query mais lenta do fluxo de
     * ACARS (chamada em todo fim de voo, ver `AcarsIngestaoController::
     * ingerir()`). Pré-filtra por uma caixa (bounding box) de lat/lon em
     * SQL antes do Haversine exato - a caixa é sempre um pouco maior que
     * `$raioKm` (graus de longitude "encolhem" perto dos polos, ver
     * `latCorr`) então nunca descarta um candidato válido, só reduz
     * quantos pontos o Haversine em PHP precisa medir de verdade. Ainda
     * sem índice espacial/PostGIS de propósito (ver docblock da
     * entidade) - a caixa sozinha já é suficiente pro volume desta rede.
     */
    public function findNearest(float $lat, float $lon, float $raioKm = self::RAIO_PADRAO_KM): ?Aeroporto
    {
        $latCorr = max(0.15, cos(deg2rad($lat))); // graus de longitude "encolhem" perto dos polos - nunca deixa a caixa estreitar demais perto deles
        $deltaLat = $raioKm / 111.0; // ~111 km por grau de latitude
        $deltaLon = $raioKm / (111.0 * $latCorr);

        $candidatos = $this->createQueryBuilder('a')
            ->where('a.lat BETWEEN :latMin AND :latMax')
            ->andWhere('a.lon BETWEEN :lonMin AND :lonMax')
            ->setParameter('latMin', $lat - $deltaLat)
            ->setParameter('latMax', $lat + $deltaLat)
            ->setParameter('lonMin', $lon - $deltaLon)
            ->setParameter('lonMax', $lon + $deltaLon)
            ->getQuery()
            ->getResult();

        $melhor = null;
        $melhorDist = null;

        foreach ($candidatos as $a) {
            $dist = self::haversineKm($lat, $lon, $a->getLat(), $a->getLon());
            if ($dist <= $raioKm && (null === $melhorDist || $dist < $melhorDist)) {
                $melhor = $a;
                $melhorDist = $dist;
            }
        }

        return $melhor;
    }

    /**
     * Distância em km, mesma fórmula (raio médio da Terra, 6371 km) que
     * qualquer conversão nm↔km do projeto já assume.
     */
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($h)));
    }
}
