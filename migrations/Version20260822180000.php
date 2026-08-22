<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria `tipo_aeronave` — perfil de performance por TIPO de aeronave
 * (peso vazio/MTOW, combustível, distâncias de decolagem/pouso de
 * referência), usado pelas calculadoras de "Ferramentas do piloto"
 * (ver App\Entity\TipoAeronave e App\Controller\FerramentasController).
 * Ligada a `aeronave.tipo` por valor de string (`nome`), não por FK —
 * ver docblock da entidade.
 *
 * Todos os campos de performance entram `NULL` — esta migration só cria
 * a estrutura, sem nenhum dado de seed (nem os tipos que já existem na
 * frota, ver `ImportarFrotaLegadaCommand`). Quem cadastra cada tipo e
 * preenche os números é o admin, pelo formulário em `/tipos-aeronave`.
 */
final class Version20260822180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela tipo_aeronave (perfil de performance por tipo, ligado a aeronave.tipo por nome).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tipo_aeronave (
                id SERIAL NOT NULL,
                nome VARCHAR(60) NOT NULL,
                peso_vazio_lb INT DEFAULT NULL,
                peso_max_decolagem_lb INT DEFAULT NULL,
                combustivel_max_gal INT DEFAULT NULL,
                consumo_gph DOUBLE PRECISION DEFAULT NULL,
                decolagem_distancia_ft INT DEFAULT NULL,
                pouso_distancia_ft INT DEFAULT NULL,
                observacoes TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_tipo_aeronave_nome ON tipo_aeronave (nome)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tipo_aeronave');
    }
}
