<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona `pilot.aviodeck_username` — usuário do piloto no AvioDeck
 * (sem o `@`), opcional, preenchido em `/perfil`. Usado só pra montar
 * um link real (`aviodeck.app/@{usuario}`) no card "Referências
 * externas" do relatório de voo (ver `App\Entity\Pilot`,
 * `PerfilController`, `VooController`) — substitui o mock que esse
 * card sempre teve (`href="#"`).
 *
 * `DEFAULT NULL` de propósito — nenhum piloto existente tem essa
 * informação hoje; fica em branco até cada um preencher no próprio
 * perfil.
 */
final class Version20260822210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona pilot.aviodeck_username (opcional, pro link real de AvioDeck no relatório de voo).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pilot ADD aviodeck_username VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pilot DROP aviodeck_username');
    }
}
