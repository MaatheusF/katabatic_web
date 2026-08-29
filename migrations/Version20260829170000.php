<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona `voo.categoria_aeronave` ('Aviao'/'Helicoptero') — pedido em
 * conversa: "precisamos marcar o voo quando ele é feito com asa fixa e
 * asa rotativa". Ver `App\Entity\Voo::$categoriaAeronave`.
 *
 * Congelado no voo (não é um lookup ao vivo em `Aeronave`→`TipoAeronave`
 * a cada leitura) pelo mesmo motivo que `tipoOperacao`/`aeronaveReg` já
 * são colunas de verdade em vez de referência: o Logbook precisa
 * filtrar/mostrar isso sem depender do cadastro de tipo continuar
 * existindo/igual pra sempre (ver docblock da entidade).
 *
 * Default 'Aviao' pra todo voo já registrado até hoje — 100% da frota
 * era avião antes desta migração, então nenhum voo existente precisa de
 * correção manual.
 */
final class Version20260829170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Adiciona voo.categoria_aeronave ('Aviao'/'Helicoptero'), default Aviao pros voos existentes.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE voo ADD categoria_aeronave VARCHAR(20) NOT NULL DEFAULT 'Aviao'");
        $this->addSql('ALTER TABLE voo ALTER COLUMN categoria_aeronave DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voo DROP categoria_aeronave');
    }
}
