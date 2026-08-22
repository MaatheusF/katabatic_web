<?php

namespace App\Repository;

use App\Entity\Aeronave;
use App\Entity\Agendamento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agendamento>
 */
class AgendamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agendamento::class);
    }

    /**
     * Todas as pernas agendadas, mais próxima primeiro — é o estado
     * inicial que `AgendamentoController::index()` injeta em
     * `window.KATABATIC_AG_AGENDAMENTOS`; `agendamento.js` reordena/
     * agrupa por aeronave e por dia no próprio navegador a partir
     * daí, então a ordem aqui só precisa ser uma ordem razoável, não
     * a ordem final de exibição.
     *
     * @return list<Agendamento>
     */
    public function findAllOrderedByWindow(): array
    {
        return $this->findBy([], ['de' => 'ASC']);
    }

    /**
     * true quando já existe outra perna agendada pra essa aeronave
     * cuja janela [de, ate) se sobrepõe à informada — mesma regra que
     * `agendamento.js::hasOverlap()` já checa no navegador, mas essa
     * validação no cliente sozinha não basta: duas abas/pilotos
     * diferentes podem cada um validar contra o próprio snapshot em
     * memória e ainda assim os dois POSTs criarem uma sobreposição de
     * verdade no banco. Esta é a validação que decide de fato (ver
     * `AgendamentoController`).
     *
     * `excludeId` tira o próprio agendamento da conta ao editar (senão
     * ele "colide" com ele mesmo).
     */
    public function hasOverlap(Aeronave $aeronave, \DateTimeImmutable $de, \DateTimeImmutable $ate, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.aeronave = :aeronave')
            ->andWhere('a.de < :ate')
            ->andWhere('a.ate > :de')
            ->setParameter('aeronave', $aeronave)
            ->setParameter('de', $de)
            ->setParameter('ate', $ate);

        if (null !== $excludeId) {
            $qb->andWhere('a.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
