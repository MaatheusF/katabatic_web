<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segunda fatia de backend: liga Adesão (`/adesao`) a Solicitações
 * (`/solicitacoes`) de verdade — ver App\Entity\MembershipRequest,
 * AdesaoController::submit() e SolicitacoesController::aprovar()/
 * rejeitar(). Antes desta migration os dois lados eram conjuntos mock
 * independentes (ver README, seção "Administração").
 *
 * Também adiciona `base`/`active` em `pilot`, usadas no grid de
 * Pilotos em Solicitações (preenchidas quando um pedido é aprovado —
 * ver App\Entity\Pilot).
 */
final class Version20260821130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela membership_request e adiciona pilot.base/pilot.active.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pilot ADD base VARCHAR(10) NOT NULL DEFAULT 'PAFA'");
        $this->addSql('ALTER TABLE pilot ADD active BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE pilot ALTER base DROP DEFAULT');
        $this->addSql('ALTER TABLE pilot ALTER active DROP DEFAULT');

        $this->addSql(<<<'SQL'
            CREATE TABLE membership_request (
                id SERIAL NOT NULL,
                name VARCHAR(150) NOT NULL,
                email VARCHAR(180) NOT NULL,
                cid VARCHAR(16) NOT NULL,
                experience VARCHAR(30) NOT NULL,
                base_pref VARCHAR(30) NOT NULL,
                discord VARCHAR(100) DEFAULT NULL,
                heard_about VARCHAR(100) DEFAULT NULL,
                motivation TEXT NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_membership_request_status ON membership_request (status)');
        $this->addSql('CREATE INDEX idx_membership_request_cid ON membership_request (cid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE membership_request');
        $this->addSql('ALTER TABLE pilot DROP base');
        $this->addSql('ALTER TABLE pilot DROP active');
    }
}
