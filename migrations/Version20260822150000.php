<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria a tabela `aeroporto` — ver App\Entity\Aeroporto,
 * AeroportoController (cadastro admin-only) e
 * AeroportoRepository::findNearest() (resolução de pouso alternativo em
 * AcarsIngestaoController). Substitui o catálogo fixo
 * `public/assets/data/airports.json` (11 entradas hardcoded) por uma
 * tabela cadastrável.
 *
 * Só cria a tabela — as 11 entradas que existiam no JSON entram com
 * `bin/console app:importar-aeroportos-legado` depois de migrar (mesmo
 * padrão do `app:importar-frota-legada`).
 */
final class Version20260822150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela aeroporto (catálogo de aeroportos, substitui airports.json).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE aeroporto (
                id SERIAL NOT NULL,
                icao VARCHAR(8) NOT NULL,
                nome VARCHAR(120) NOT NULL,
                cidade VARCHAR(120) NOT NULL,
                lat DOUBLE PRECISION NOT NULL,
                lon DOUBLE PRECISION NOT NULL,
                posto_avancado_de VARCHAR(8) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_aeroporto_icao ON aeroporto (icao)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE aeroporto');
    }
}
