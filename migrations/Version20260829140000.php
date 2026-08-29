<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria `pesquisa_captura` — série temporal de capturas de mapa (manifesto
 * de tiles em disco + imagem composta) e grade de vento por altitude,
 * tiradas ao vivo durante voos de Pesquisa. Ver `App\Entity\
 * PesquisaCaptura` e `App\Service\PesquisaCapturaOrquestrador`.
 */
final class Version20260829140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela pesquisa_captura (mapa + grade de vento por altitude, captura ao vivo).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE pesquisa_captura (
                id SERIAL NOT NULL,
                aeronave_reg VARCHAR(16) NOT NULL,
                capturado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                lat DOUBLE PRECISION NOT NULL,
                lon DOUBLE PRECISION NOT NULL,
                alt_ft INT DEFAULT NULL,
                manifesto_mapa JSON DEFAULT NULL,
                grade_vento JSON DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_pesquisa_captura_reg_capturado ON pesquisa_captura (aeronave_reg, capturado_em)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pesquisa_captura');
    }
}
