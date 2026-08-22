<?php

namespace App\Repository;

use App\Entity\TipoAeronave;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TipoAeronave>
 */
class TipoAeronaveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TipoAeronave::class);
    }

    public function findOneByNome(string $nome): ?TipoAeronave
    {
        return $this->findOneBy(['nome' => $nome]);
    }

    public function existsByNome(string $nome): bool
    {
        return null !== $this->findOneByNome($nome);
    }

    /**
     * @return list<TipoAeronave>
     */
    public function findAllOrderedByNome(): array
    {
        return $this->findBy([], ['nome' => 'ASC']);
    }
}
