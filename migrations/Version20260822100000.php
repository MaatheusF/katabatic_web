<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Converte `voo.dados` de `json` pra `jsonb` — só o tipo físico da
 * coluna no Postgres muda; a entidade (`App\Entity\Voo::$dados`)
 * continua mapeada como `Types::JSON` porque o tipo DBAL do Doctrine
 * faz `json_encode`/`json_decode` em PHP independente do subtipo
 * físico da coluna (ver docblock de `Voo::$dados`) — não precisa (e
 * não deve) virar outro tipo DBAL por causa disto.
 *
 * Por que agora: `jsonb` indexa e faz `->`/`->>`/`@>` sem reparsear o
 * texto a cada consulta, diferente de `json` (que só valida e guarda
 * como texto) — vale trocar antes de acumular muitos voos de verdade
 * e depender de consultas em cima de `dados` (ex.: relatórios por
 * condição/ocorrência, ver README "Próximos passos").
 *
 * `USING dados::jsonb` faz o Postgres reconverter as linhas
 * existentes na hora do ALTER — sem perda de dados, mesmo pros voos
 * que já têm telemetria gravada.
 */
final class Version20260822100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Converte voo.dados de json pra jsonb (mesmo conteúdo, coluna física mais rápida de consultar).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voo ALTER COLUMN dados TYPE jsonb USING dados::jsonb');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voo ALTER COLUMN dados TYPE json USING dados::json');
    }
}
