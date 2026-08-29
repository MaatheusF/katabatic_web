<?php

namespace App\Repository;

use App\Entity\ConfiguracaoPesquisa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfiguracaoPesquisa>
 */
class ConfiguracaoPesquisaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoPesquisa::class);
    }

    /**
     * Devolve a linha única de configuração, criando-a com os valores
     * padrão da entidade na primeira leitura — quem consome esta
     * configuração (o hook de captura ACARS, o gerador de relatório)
     * nunca precisa tratar "ainda não configurado" como caso especial.
     * Ver docblock de `ConfiguracaoPesquisa`.
     */
    public function obterOuCriar(EntityManagerInterface $em): ConfiguracaoPesquisa
    {
        $config = $this->createQueryBuilder('c')
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null !== $config) {
            return $config;
        }

        $config = new ConfiguracaoPesquisa();
        $em->persist($config);
        $em->flush();

        return $config;
    }
}
