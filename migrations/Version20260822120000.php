<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backend do Agendamento de voo — ver App\Entity\Agendamento e
 * README, "Agendamento de voo". Antes desta fatia, `AgendamentoController`
 * só montava um array mock (seis aeronaves, seis pernas) injetado no
 * template; esta migration cria a tabela de verdade.
 *
 * Sem dado semeado aqui (diferente de `voo`/`aeronave`, que tinham um
 * comando de import de dado legado próprio): as seis pernas mock só
 * existiam pra mostrar a tela funcionando com algo na frente, não são
 * um histórico real de nada — nascer vazia e deixar quem for testar
 * criar os próprios agendamentos é mais correto que inventar pernas
 * "de mentirinha" numa tabela real.
 */
final class Version20260822120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela agendamento (Agendamento de voo).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE agendamento (
                id SERIAL NOT NULL,
                aeronave_id INT NOT NULL,
                origem VARCHAR(8) NOT NULL,
                destino VARCHAR(8) NOT NULL,
                tipo_operacao VARCHAR(30) NOT NULL,
                piloto VARCHAR(120) NOT NULL,
                de TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                ate TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                notas TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        // Índice composto (não único: sobreposição é regra de negócio
        // validada em PHP via AgendamentoRepository::hasOverlap(), não
        // uma constraint de banco) - é a mesma consulta que roda a cada
        // POST de criar/editar, então vale ter índice pra ela desde já.
        $this->addSql('CREATE INDEX idx_agendamento_aeronave_janela ON agendamento (aeronave_id, de, ate)');
        $this->addSql(
            'ALTER TABLE agendamento ADD CONSTRAINT fk_agendamento_aeronave FOREIGN KEY (aeronave_id) '.
            'REFERENCES aeronave (id) NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE agendamento');
    }
}
