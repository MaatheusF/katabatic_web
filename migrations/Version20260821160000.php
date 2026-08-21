<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quarta fatia de backend: Frota — ver App\Entity\Aeronave,
 * NovaAeronaveController (agora grava de verdade), e
 * PortalController/NovoVooController/AeronaveController/
 * MapaAoVivoController, que todos consultam esta tabela em vez de
 * quatro cópias mock da mesma lista de 6 aeronaves.
 *
 * Só cria a tabela — as 6 aeronaves que existiam mock entram com
 * `bin/console app:importar-frota-legada` depois de migrar (mesmo
 * padrão do `app:importar-voos-legados` da fatia anterior).
 */
final class Version20260821160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela aeronave (Frota).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE aeronave (
                id SERIAL NOT NULL,
                reg VARCHAR(16) NOT NULL,
                pais VARCHAR(2) NOT NULL,
                tipo VARCHAR(60) NOT NULL,
                base VARCHAR(8) NOT NULL,
                pos_icao VARCHAR(8) NOT NULL,
                status VARCHAR(20) NOT NULL,
                limite_g DOUBLE PRECISION DEFAULT NULL,
                vs_limite_fpm INT DEFAULT NULL,
                horas INT NOT NULL,
                observacoes TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_aeronave_reg ON aeronave (reg)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE aeronave');
    }
}
