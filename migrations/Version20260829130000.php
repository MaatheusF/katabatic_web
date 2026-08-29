<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Duas peças da camada de pesquisa meteorológica ao vivo:
 *
 * 1. `aeronave.em_voo_tipo_operacao` — sidecar da sessão ACARS em
 *    andamento (mesma família de `em_voo_desde`/`ultimo_ping_em`), ver
 *    `App\Entity\Aeronave::$emVooTipoOperacao`.
 * 2. `pesquisa_amostra` — série temporal de amostras ambiente
 *    capturadas ao vivo durante voos de Pesquisa, ver
 *    `App\Entity\PesquisaAmostra`.
 */
final class Version20260829130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona aeronave.em_voo_tipo_operacao e cria a tabela pesquisa_amostra (captura ambiente ao vivo).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeronave ADD em_voo_tipo_operacao VARCHAR(30) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE pesquisa_amostra (
                id SERIAL NOT NULL,
                aeronave_reg VARCHAR(16) NOT NULL,
                capturado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                lat DOUBLE PRECISION NOT NULL,
                lon DOUBLE PRECISION NOT NULL,
                alt_ft INT DEFAULT NULL,
                vento_kt DOUBLE PRECISION DEFAULT NULL,
                vento_dir INT DEFAULT NULL,
                rajada_kt DOUBLE PRECISION DEFAULT NULL,
                temp_c DOUBLE PRECISION DEFAULT NULL,
                pressao_hpa DOUBLE PRECISION DEFAULT NULL,
                precip_mm_h DOUBLE PRECISION DEFAULT NULL,
                weather_code INT DEFAULT NULL,
                severidade VARCHAR(20) NOT NULL,
                alerta_oficial VARCHAR(120) DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_pesquisa_amostra_reg_capturado ON pesquisa_amostra (aeronave_reg, capturado_em)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeronave DROP em_voo_tipo_operacao');
        $this->addSql('DROP TABLE pesquisa_amostra');
    }
}
