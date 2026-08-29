<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona `configuracao_pesquisa.mapa_grid_raio` — raio, em tiles, da
 * grade de mapa baixada a cada captura de pesquisa meteorológica (ver
 * `App\Entity\ConfiguracaoPesquisa::$mapaGridRaio` e
 * `App\Service\PesquisaMapaCaptador::capturar()`). Pedido em conversa:
 * "não quero apenas o tile em volta do voo, mas toda a região... poderia
 * ser uma configuração".
 *
 * Default 1 (grade 3×3) — o comportamento de antes desta migração,
 * intocado até o admin decidir aumentar em `/configuracoes/pesquisa`.
 */
final class Version20260829150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona configuracao_pesquisa.mapa_grid_raio (raio em tiles da grade de mapa por captura).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuracao_pesquisa ADD mapa_grid_raio INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE configuracao_pesquisa ALTER COLUMN mapa_grid_raio DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuracao_pesquisa DROP mapa_grid_raio');
    }
}
