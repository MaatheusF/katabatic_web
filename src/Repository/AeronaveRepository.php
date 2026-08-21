<?php

namespace App\Repository;

use App\Entity\Aeronave;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Aeronave>
 */
class AeronaveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Aeronave::class);
    }

    public function findOneByReg(string $reg): ?Aeronave
    {
        return $this->findOneBy(['reg' => $reg]);
    }

    public function existsByReg(string $reg): bool
    {
        return null !== $this->findOneByReg($reg);
    }

    /**
     * Frota inteira, agrupada por base e depois por matrícula — mesma
     * ordem visual que o array mock antigo tinha (Portal/Novo voo).
     *
     * @return list<Aeronave>
     */
    public function findAllOrderedByBaseAndReg(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.base', 'ASC')
            ->addOrderBy('a.reg', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Filtra em PHP por `Aeronave::getStatusEfetivo()`, não pela coluna
     * `status` crua na query — é `getStatusEfetivo()` quem sabe
     * "esconder" uma sessão ACARS travada (ver docblock da entidade),
     * e a frota é pequena o bastante pra não valer duplicar essa regra
     * em SQL só por causa de performance.
     *
     * @return list<Aeronave>
     */
    public function findAllEmVoo(): array
    {
        $todas = $this->findBy([], ['reg' => 'ASC']);

        return array_values(array_filter($todas, static fn (Aeronave $a) => 'Em voo' === $a->getStatusEfetivo()));
    }

    /**
     * @return list<Aeronave>
     */
    public function findAllNotEmVoo(): array
    {
        $todas = $this->findBy([], ['reg' => 'ASC']);

        return array_values(array_filter($todas, static fn (Aeronave $a) => 'Em voo' !== $a->getStatusEfetivo()));
    }
}
