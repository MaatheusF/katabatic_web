<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona `aeroporto.pista_principal_heading_mag` — heading magnético
 * (0-359) da pista principal, opcional, usado pra calcular o través
 * (windc) contra a pista de verdade em vez do heading da aeronave no
 * toque — ver `App\Entity\Aeroporto` e
 * `TelemetryDeriver::recomputeWindcComHeadingDePista()`.
 *
 * `DEFAULT NULL` de propósito — nenhum aeroporto existente (os 11
 * hand-cadastrados, o import global, nem os já marcados como posto
 * avançado) tem essa informação hoje; fica em branco até um admin
 * preencher em `/aeroportos` (só o campo, não migration de dado).
 */
final class Version20260822190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona aeroporto.pista_principal_heading_mag (heading opcional da pista principal, pra través de verdade).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeroporto ADD pista_principal_heading_mag INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeroporto DROP pista_principal_heading_mag');
    }
}
