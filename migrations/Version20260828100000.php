<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria `voo_rascunho` — rascunho de verdade do formulário de "Novo
 * voo" (substitui o `alert()` mock que "Salvar rascunho" sempre foi -
 * ver `App\Entity\VooRascunho`, cujo docblock explica por que é uma
 * tabela separada e não um status novo em `voo`).
 *
 * Um rascunho por piloto (`UNIQUE` em `pilot_id`) — salvar de novo
 * sobrescreve o anterior, sem precisar de tela de lista. `ON DELETE
 * CASCADE`: se um piloto for removido (não existe rota pra isso hoje,
 * mas por segurança), o rascunho dele some junto, sem deixar linha
 * órfã.
 */
final class Version20260828100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela voo_rascunho (rascunho persistente de "Novo voo", um por piloto).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE voo_rascunho (
                id SERIAL NOT NULL,
                pilot_id INT NOT NULL,
                dados JSON NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_voo_rascunho_pilot ON voo_rascunho (pilot_id)');
        $this->addSql('ALTER TABLE voo_rascunho ADD CONSTRAINT fk_voo_rascunho_pilot FOREIGN KEY (pilot_id) REFERENCES pilot (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE voo_rascunho');
    }
}
