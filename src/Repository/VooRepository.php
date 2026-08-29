<?php

namespace App\Repository;

use App\Entity\Pilot;
use App\Entity\Voo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Voo>
 */
class VooRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Voo::class);
    }

    /**
     * Logbook inteiro do piloto (com e sem telemetria gravada), mais
     * recente primeiro — mesma ordem que o array mock tinha.
     *
     * @return list<Voo>
     */
    public function findAllForPilot(Pilot $pilot): array
    {
        return $this->findBy(['pilot' => $pilot], ['startedAt' => 'DESC']);
    }

    /**
     * Só os voos com telemetria de verdade gravada (`codigo` não nulo)
     * — é a lista que alimenta `/voo` (ver VooController), no mesmo
     * formato que `flights.json` tinha.
     *
     * @return list<Voo>
     */
    public function findComTelemetriaForPilot(Pilot $pilot): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.pilot = :pilot')
            ->andWhere('v.codigo IS NOT NULL')
            ->setParameter('pilot', $pilot)
            ->orderBy('v.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCodigoForPilot(string $codigo, Pilot $pilot): ?Voo
    {
        return $this->findOneBy(['codigo' => $codigo, 'pilot' => $pilot]);
    }

    /**
     * Sem filtro de piloto — usado pela ingestão ACARS
     * (`AcarsIngestaoController`) pra checar idempotência antes de
     * criar: o `codigo` que o script manda é único por natureza (nome
     * da pasta de gravação, `AAAAMMDD_HHMMSS_CALLSIGN`), então um POST
     * repetido (falha de rede no cliente, retry manual) deve encontrar
     * o voo já criado em vez de duplicar — nesse ponto ainda não se
     * sabe de qual piloto é, é isso que este método resolve antes de
     * `findOneByCodigoForPilot()` fazer sentido.
     */
    public function findOneByCodigo(string $codigo): ?Voo
    {
        return $this->findOneBy(['codigo' => $codigo]);
    }

    /**
     * Usado só pelo import de voos legados (`app:importar-voos-legados`)
     * pra não duplicar as linhas sem `codigo` (que não têm um
     * identificador único de negócio pra deduplicar por ele) se o
     * comando rodar de novo.
     */
    public function existsForPilotCallsignAndStart(Pilot $pilot, string $callsign, \DateTimeImmutable $startedAt): bool
    {
        return null !== $this->findOneBy([
            'pilot' => $pilot,
            'callsign' => $callsign,
            'startedAt' => $startedAt,
        ]);
    }

    /**
     * Todas as pernas de uma matrícula, de qualquer piloto que a tenha
     * voado, mais recente primeiro — é o Histórico da frota
     * (`AeronaveController`). Diferente de `findAllForPilot()`: aqui a
     * pergunta é "o que essa aeronave voou", não "o que esse piloto
     * voou", então não filtra por piloto.
     *
     * @return list<Voo>
     */
    public function findAllByAeronaveReg(string $reg): array
    {
        return $this->findBy(['aeronaveReg' => $reg], ['startedAt' => 'DESC']);
    }

    /**
     * O voo com telemetria gravada mais recente de uma matrícula,
     * independente do piloto — usado pelo Mapa ao vivo pra achar qual
     * gravação repetir em loop pra uma aeronave marcada "Em voo" (ver
     * `MapaAoVivoController::liveFlights()`).
     */
    public function findMaisRecenteComTelemetriaByAeronaveReg(string $reg): ?Voo
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.aeronaveReg = :reg')
            ->andWhere('v.codigo IS NOT NULL')
            ->setParameter('reg', $reg)
            ->orderBy('v.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Quantos voos cada piloto tem no Logbook, todos de uma vez — é o
     * que alimenta a coluna "Voos" do grid de Pilotos em Solicitações
     * (`SolicitacoesController::index()`); uma consulta agregada em vez
     * de um `COUNT` por piloto (mesmo espírito de
     * `PosicaoAoVivoRepository::findByAeronaves()`, evitar N+1). Piloto
     * sem nenhum voo não gera linha no `GROUP BY` — quem lê o resultado
     * trata ausência como 0 (ver `pilotViewModel()`).
     *
     * Só conta voos `status = 'valido'` — um voo marcado acidentado
     * (ver `Voo::marcarAcidentado()`) continua no Logbook pra
     * auditoria, mas não deveria inflar a contagem de voos do piloto.
     *
     * @return array<int, int> pilot_id => contagem
     */
    public function countsByPilot(): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.pilot) AS pilotId', 'COUNT(v.id) AS total')
            ->andWhere('v.status = :status')
            ->setParameter('status', Voo::STATUS_VALIDO)
            ->groupBy('v.pilot')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['pilotId']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Os N voos mais recentes com telemetria de verdade, de QUALQUER
     * piloto — alimenta "Operações recentes" na home pública (ver
     * `HomeController::recentFlights()`). Sem filtro de piloto
     * (diferente de `findComTelemetriaForPilot()`): a home é uma
     * vitrine da empresa, não do Logbook de ninguém. Só `valido` —
     * um voo acidentado (`marcarAcidentado()`) continua no banco pra
     * auditoria, mas não é isso que a home deveria exibir como
     * "operação recente".
     *
     * @return list<Voo>
     */
    public function findRecentesComTelemetria(int $limit): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.codigo IS NOT NULL')
            ->andWhere('v.status = :status')
            ->setParameter('status', Voo::STATUS_VALIDO)
            ->orderBy('v.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Contagem de voos de um piloto só — usado onde não vale a pena
     * buscar o mapa inteiro (`SolicitacoesController::aprovar()`, que já
     * tem o `Pilot` em mãos e está tratando um só de cada vez). Mesmo
     * filtro de `status` que `countsByPilot()` — ver docblock lá.
     */
    public function countForPilot(Pilot $pilot): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.pilot = :pilot')
            ->andWhere('v.status = :status')
            ->setParameter('pilot', $pilot)
            ->setParameter('status', Voo::STATUS_VALIDO)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Todo par (callsign, origem, destino) já usado por algum voo, de
     * qualquer piloto — alimenta o gerador de callsign/número de voo em
     * Ferramentas (ver `FerramentasController` e `ferramentas.js`), que
     * usa isto pra evitar sugerir um número que outra rota já usa
     * (mesma rota reusar o mesmo número continua sendo o esperado, só
     * rota DIFERENTE colidindo é que o gerador tenta evitar). `DISTINCT`
     * na tripla inteira: uma rota voada 50 vezes com o mesmo callsign
     * vira 1 linha só, não 50 — sem filtro de status/piloto de propósito
     * (até um voo de teste ou acidentado "reserva" o número pra quem
     * olhar essa lista).
     *
     * @return list<array{callsign: string, origem: string, destino: string}>
     */
    public function findCallsignsRotasUsados(): array
    {
        return $this->createQueryBuilder('v')
            ->select('v.callsign AS callsign', 'v.origem AS origem', 'v.destino AS destino')
            ->distinct()
            ->getQuery()
            ->getArrayResult();
    }
}
