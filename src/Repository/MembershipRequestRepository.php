<?php

namespace App\Repository;

use App\Entity\MembershipRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MembershipRequest>
 */
class MembershipRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembershipRequest::class);
    }

    /**
     * Mesma ordem que o grid mock antigo tinha (mais recente primeiro).
     *
     * @return list<MembershipRequest>
     */
    public function findAllOrderedByRequestDate(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC']);
    }

    /**
     * Usado pelo formulário público (`AdesaoController::submit()`) pra
     * recusar um segundo pedido do mesmo CID enquanto o primeiro ainda
     * não foi decidido — evita duplicar linha no grid de Solicitações.
     */
    public function hasPendingForCid(string $cid): bool
    {
        return null !== $this->findOneBy(['cid' => $cid, 'status' => 'pendente']);
    }
}
