<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sexta fatia de backend — ACARS fase 3 (posição em tempo real). Cria
 * `posicao_ao_vivo` (última posição conhecida por aeronave, ver
 * `App\Entity\PosicaoAoVivo` pra por que é uma tabela à parte em vez de
 * colunas em `aeronave`) e adiciona `aeronave.ultimo_ping_em`, usado por
 * `Aeronave::getStatusEfetivo()` pra detectar uma sessão ACARS travada
 * em minutos em vez de horas quando o cliente manda heartbeat de
 * posição — ver README, "Backend: posição em tempo real (ACARS fase
 * 3)".
 */
final class Version20260822090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela posicao_ao_vivo e adiciona aeronave.ultimo_ping_em.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeronave ADD ultimo_ping_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE posicao_ao_vivo (
                id SERIAL NOT NULL,
                aeronave_id INT NOT NULL,
                lat DOUBLE PRECISION NOT NULL,
                lon DOUBLE PRECISION NOT NULL,
                alt_ft INT DEFAULT NULL,
                hdg_true DOUBLE PRECISION DEFAULT NULL,
                gs_kt INT DEFAULT NULL,
                ias_kt INT DEFAULT NULL,
                vs_fpm INT DEFAULT NULL,
                on_ground BOOLEAN DEFAULT NULL,
                registrada_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                recebida_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_posicao_aeronave ON posicao_ao_vivo (aeronave_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE posicao_ao_vivo
                ADD CONSTRAINT fk_posicao_aeronave
                FOREIGN KEY (aeronave_id) REFERENCES aeronave (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE posicao_ao_vivo');
        $this->addSql('ALTER TABLE aeronave DROP ultimo_ping_em');
    }
}
