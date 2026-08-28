<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona `aeronave.limite_g_negativo` — limite de fator de carga
 * negativo (G, estrutural), opcional, espelhando `aeronave.limite_g`
 * (positivo) que já existia — ver `App\Entity\Aeronave::$limiteG` e
 * `TelemetryDeriver::deriveEvents()`, que passa a checar excedência dos
 * dois lados (`gmax > limiteG` já existia; `gmin < limiteGNegativo` é
 * novo).
 *
 * `DEFAULT NULL` de propósito, sem backfill — mesma razão de sempre
 * neste projeto ("nunca um número inventado"): não existe um valor de
 * fábrica seguro pra aplicar a uma aeronave existente sem checar o POH
 * de verdade. Fica em branco até alguém cadastrar o valor real.
 *
 * **Atenção:** não existe rota de edição pra `Aeronave` hoje (só
 * criação, em `NovaAeronaveController::submit()` — mesma limitação que
 * já valia pra `limite_g`/`vs_limite_fpm`). Preencher este campo numa
 * aeronave já cadastrada exige um UPDATE manual (`dbal:run-sql`) até
 * essa tela existir.
 */
final class Version20260828090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona aeronave.limite_g_negativo (limite opcional de G negativo, espelhando limite_g).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeronave ADD limite_g_negativo DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeronave DROP limite_g_negativo');
    }
}
