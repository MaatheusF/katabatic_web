<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona `aeroporto.icao_oficial` — `true` quando `aeroporto.icao` é um
 * código ICAO de verdade, `false` quando é um código local/FAA/gps sem
 * ICAO oficial (pistas de bush flying nas regiões de missão sem ICAO
 * cadastrado, ver `App\Entity\Aeroporto` e
 * `ImportarAeroportosOurairportsCommand`).
 *
 * `DEFAULT TRUE` de propósito — toda linha já existente (os 11
 * hand-cadastrados + o que já foi importado com ICAO real) fica `true`
 * sem precisar de backfill manual; só as pistas locais que passam a ser
 * importadas depois desta migration entram com `false`.
 */
final class Version20260822170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona aeroporto.icao_oficial (false pra código local/FAA/gps sem ICAO oficial).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeroporto ADD icao_oficial BOOLEAN NOT NULL DEFAULT true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aeroporto DROP icao_oficial');
    }
}
