<?php

namespace App\Repository;

use App\Entity\Aeronave;
use App\Entity\PosicaoAoVivo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PosicaoAoVivo>
 */
class PosicaoAoVivoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PosicaoAoVivo::class);
    }

    public function findOneByAeronave(Aeronave $aeronave): ?PosicaoAoVivo
    {
        return $this->findOneBy(['aeronave' => $aeronave]);
    }

    /**
     * Upsert: acha a linha existente da aeronave (uma por matrícula, ver
     * docblock da entidade) ou cria uma nova — quem chama ainda precisa
     * dar `flush()` (mesmo padrão do resto do app, pra poder combinar
     * com outras mudanças na mesma transação, ex.: `Aeronave::ultimoPingEm`
     * em `AcarsIngestaoController::posicao()`).
     */
    public function upsert(Aeronave $aeronave): PosicaoAoVivo
    {
        $posicao = $this->findOneByAeronave($aeronave);
        if (null === $posicao) {
            $posicao = new PosicaoAoVivo($aeronave);
            $this->getEntityManager()->persist($posicao);
        }

        return $posicao;
    }

    /**
     * Todas as posições ao vivo indexadas por `reg` da aeronave — usado
     * por `MapaAoVivoController` pra montar a lista de "Em voo" sem um
     * N+1 (uma consulta só, em vez de uma por aeronave em voo).
     *
     * @param list<Aeronave> $aeronaves
     *
     * @return array<string, PosicaoAoVivo>
     */
    public function findByAeronaves(array $aeronaves): array
    {
        if ([] === $aeronaves) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->addSelect('a')
            ->join('p.aeronave', 'a')
            ->andWhere('p.aeronave IN (:aeronaves)')
            ->setParameter('aeronaves', $aeronaves)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $posicao) {
            $out[$posicao->getAeronave()->getReg()] = $posicao;
        }

        return $out;
    }
}
