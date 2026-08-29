<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria `configuracao_pesquisa` — registro único (sem tela de lista) com
 * a cadência de amostragem/captura e os limiares de severidade da
 * camada de pesquisa meteorológica (voos `tipoOperacao === 'Pesquisa'`).
 * Ver `App\Entity\ConfiguracaoPesquisa`.
 *
 * Já insere a linha padrão aqui — `ConfiguracaoPesquisaRepository::
 * obterOuCriar()` também sabe criar essa linha sozinho se ela não
 * existir, mas semear direto na migração evita a primeira leitura pós-
 * deploy pagar o custo de um INSERT extra e deixa o `SELECT` inicial
 * (feito por qualquer request, não só a tela de admin) sempre
 * encontrando uma linha.
 */
final class Version20260829120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela configuracao_pesquisa (parâmetros da pesquisa meteorológica, registro único).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE configuracao_pesquisa (
                id SERIAL NOT NULL,
                amostra_cadencia_min INT NOT NULL,
                captura_cadencia_min INT NOT NULL,
                niveis_pressao_hpa JSON NOT NULL,
                vento_moderado_kt INT NOT NULL,
                vento_severo_kt INT NOT NULL,
                rajada_severa_kt INT NOT NULL,
                precip_moderada_mm_h DOUBLE PRECISION NOT NULL,
                precip_severa_mm_h DOUBLE PRECISION NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO configuracao_pesquisa
                (amostra_cadencia_min, captura_cadencia_min, niveis_pressao_hpa, vento_moderado_kt, vento_severo_kt, rajada_severa_kt, precip_moderada_mm_h, precip_severa_mm_h, updated_at)
            VALUES
                (2, 30, '[1000, 925, 850, 700, 500, 300]', 25, 40, 50, 4.0, 15.0, NOW())
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE configuracao_pesquisa');
    }
}
