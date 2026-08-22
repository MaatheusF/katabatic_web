<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona `voo.status` ('valido' | 'acidentado') — troca o hard-delete
 * que a tela de voo tinha (`VooController::excluir()`, ver git log)
 * por uma marcação que preserva a linha (auditoria/histórico) em vez
 * de apagá-la. Ver `App\Entity\Voo::$status` e
 * `VooController::marcarAcidentado()`.
 *
 * `DEFAULT 'valido'` cobre as linhas existentes sem precisar de um
 * `UPDATE` separado — todo voo já gravado até aqui é, por definição,
 * um voo que ninguém marcou como inválido.
 */
final class Version20260822140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Adiciona voo.status ('valido'/'acidentado') pra marcar voos inválidos sem apagar a linha.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE voo ADD status VARCHAR(20) NOT NULL DEFAULT 'valido'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voo DROP status');
    }
}
