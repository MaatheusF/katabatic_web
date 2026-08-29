<?php

namespace App\Repository;

use App\Entity\PesquisaAmostra;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PesquisaAmostra>
 */
class PesquisaAmostraRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PesquisaAmostra::class);
    }

    /**
     * A amostra mais recente desta matrícula — usada por
     * `PesquisaAmbienteCaptador` pra decidir se já passou tempo
     * suficiente (`ConfiguracaoPesquisa::$amostraCadenciaMin`) desde a
     * última captura antes de gastar uma chamada à Open-Meteo de novo.
     */
    public function findMaisRecenteByAeronaveReg(string $reg): ?PesquisaAmostra
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.aeronaveReg = :reg')
            ->setParameter('reg', $reg)
            ->orderBy('a.capturadoEm', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todas as amostras de uma matrícula dentro da janela de um voo
     * (`[startedAt, encerradoEm]`) — é o que `AcarsIngestaoController::
     * ingerir()` congela em `Voo::$dados['pesquisa']` no fechamento. Ver
     * docblock de `PesquisaAmostra` pro porquê da janela por tempo em
     * vez de FK direta.
     *
     * @return list<PesquisaAmostra>
     */
    public function findParaVoo(string $reg, \DateTimeImmutable $startedAt, \DateTimeImmutable $encerradoEm): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.aeronaveReg = :reg')
            ->andWhere('a.capturadoEm >= :inicio')
            ->andWhere('a.capturadoEm <= :fim')
            ->setParameter('reg', $reg)
            ->setParameter('inicio', $startedAt)
            ->setParameter('fim', $encerradoEm)
            ->orderBy('a.capturadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
