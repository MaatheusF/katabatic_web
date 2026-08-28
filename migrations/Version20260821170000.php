<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quinta fatia de backend (fase 2 — status "Em voo" em tempo real): só
 * adiciona `aeronave.em_voo_desde`, usado por
 * `Aeronave::getStatusEfetivo()` pra detectar uma sessão ACARS travada
 * (PC do piloto caiu no meio do voo, nunca chegou o `POST .../voos` de
 * fechamento) e "esconder" o status `'Em voo'` sozinho depois de um
 * tempo — ver docblock da entidade e README, "Backend: ingestão ACARS
 * (MVP)".
 */
final class Version20260821170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona aeronave.em_voo_desde (status "Em voo" em tempo real via ACARS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeronave ADD em_voo_desde TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeronave DROP em_voo_desde');
    }
}
