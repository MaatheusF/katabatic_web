<?php

namespace App\Repository;

use App\Entity\Pilot;
use App\Entity\VooRascunho;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VooRascunho>
 */
class VooRascunhoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VooRascunho::class);
    }

    public function findOneByPilot(Pilot $pilot): ?VooRascunho
    {
        return $this->findOneBy(['pilot' => $pilot]);
    }
}
