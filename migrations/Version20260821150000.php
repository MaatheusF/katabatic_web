<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Terceira fatia de backend: Logbook e telemetria de voo — ver
 * App\Entity\Voo, PortalController::logbook() (agora consulta
 * `voo` em vez do array mock) e VooController (agora serve a
 * telemetria de `voo.dados->>'telemetria'` em vez do arquivo estático
 * `public/assets/data/flights.json`).
 *
 * Esta migration só cria a tabela — os 12 voos que existiam como mock
 * (3 com telemetria real do ACARS, 9 só narrativos) são importados por
 * `bin/console app:importar-voos-legados` depois de migrar (dado
 * demais pra caber num INSERT de migration escrito à mão sem risco de
 * transcrever errado — ver README).
 */
final class Version20260821150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela voo (Logbook + telemetria).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE voo (
                id SERIAL NOT NULL,
                pilot_id INT NOT NULL,
                codigo VARCHAR(64) DEFAULT NULL,
                callsign VARCHAR(16) NOT NULL,
                tipo_operacao VARCHAR(30) NOT NULL,
                origem VARCHAR(8) NOT NULL,
                destino VARCHAR(8) NOT NULL,
                aeronave_reg VARCHAR(16) NOT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                tempo_min INT NOT NULL,
                dificuldade INT NOT NULL,
                dados JSON NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_voo_codigo ON voo (codigo)');
        $this->addSql('CREATE INDEX idx_voo_pilot ON voo (pilot_id)');
        $this->addSql('CREATE INDEX idx_voo_started_at ON voo (started_at)');
        $this->addSql(
            'ALTER TABLE voo ADD CONSTRAINT fk_voo_pilot FOREIGN KEY (pilot_id) '.
            'REFERENCES pilot (id) NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE voo');
    }
}
