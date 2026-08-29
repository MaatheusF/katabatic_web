<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona `tipo_aeronave.categoria` ('Aviao'/'Helicoptero') — pedido em
 * conversa: "precisamos preparar a plataforma pra receber voos de
 * helicoptero também" (piloto está prestes a adquirir uma aeronave assim).
 * Ver `App\Entity\TipoAeronave::$categoria`.
 *
 * Default 'Aviao' pra toda a frota já cadastrada (100% asa fixa até hoje)
 * — ninguém precisa voltar em `/tipos-aeronave` pra recategorizar nada que
 * já existia; só passa a valer escolher 'Helicoptero' pros tipos novos.
 */
final class Version20260829160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Adiciona tipo_aeronave.categoria ('Aviao'/'Helicoptero'), default Aviao pros tipos existentes.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tipo_aeronave ADD categoria VARCHAR(20) NOT NULL DEFAULT 'Aviao'");
        $this->addSql('ALTER TABLE tipo_aeronave ALTER COLUMN categoria DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tipo_aeronave DROP categoria');
    }
}
