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

    /**
     * Nome do tipo -> categoria ('Aviao'/'Helicoptero'), pra quem só
     * precisa rotular a categoria de cada aeronave da frota sem carregar
     * a entidade inteira (ver `PortalController::fleetViewModel()`,
     * `AeronaveController::index()`, `NovoVooController::aircraftViewModel()`)
     * — evita um `findOneByNome()` por aeronave (N+1) quando o chamador
     * está montando uma lista.
     *
     * @return array<string, string>
     */
    public function findCategoriasPorNome(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.nome AS nome', 't.categoria AS categoria')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['nome']] = $row['categoria'];
        }

        return $out;
    }
}
