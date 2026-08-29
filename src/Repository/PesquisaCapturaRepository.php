<?php

namespace App\Repository;

use App\Entity\PesquisaCaptura;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PesquisaCaptura>
 */
class PesquisaCapturaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PesquisaCaptura::class);
    }

    /**
     * Mesmo papel de `PesquisaAmostraRepository::
     * findMaisRecenteByAeronaveReg()` — decide se já passou a cadência
     * configurada (`ConfiguracaoPesquisa::$capturaCadenciaMin`) desde a
     * última captura de mapa/vento antes de gastar rede/disco de novo.
     */
    public function findMaisRecenteByAeronaveReg(string $reg): ?PesquisaCaptura
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.aeronaveReg = :reg')
            ->setParameter('reg', $reg)
            ->orderBy('c.capturadoEm', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todas as capturas de uma matrícula dentro da janela de um voo —
     * mesmo princípio de `PesquisaAmostraRepository::findParaVoo()`.
     *
     * @return list<PesquisaCaptura>
     */
    public function findParaVoo(string $reg, \DateTimeImmutable $startedAt, \DateTimeImmutable $encerradoEm): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.aeronaveReg = :reg')
            ->andWhere('c.capturadoEm >= :inicio')
            ->andWhere('c.capturadoEm <= :fim')
            ->setParameter('reg', $reg)
            ->setParameter('inicio', $startedAt)
            ->setParameter('fim', $encerradoEm)
            ->orderBy('c.capturadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
