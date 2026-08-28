<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona `voo.destino_real` — ICAO onde a aeronave pousou de verdade,
 * preenchido só quando difere do `destino` declarado no plano de voo
 * (pouso alternativo/diversão). Ver `App\Entity\Voo::$destinoReal`,
 * `hasPousoAlternativo()` e `AcarsIngestaoController::ingerir()`, que é
 * quem resolve isso via `AeroportoRepository::findNearest()` contra o
 * ponto de pouso real (telemetria) em vez de confiar cegamente no
 * `destino` que o payload declarou.
 *
 * Nullable, sem `DEFAULT` — toda linha existente fica `NULL` (não temos
 * como saber retroativamente se algum voo já gravado divergiu; só os
 * voos ingeridos depois desta fatia passam pela detecção).
 */
final class Version20260822160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona voo.destino_real (pouso alternativo/diversão, preenchido quando diverge do destino declarado).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voo ADD destino_real VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voo DROP destino_real');
    }
}
