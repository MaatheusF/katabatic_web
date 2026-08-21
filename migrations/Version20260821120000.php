<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria a tabela `pilot` (login e Perfil de verdade - ver
 * App\Entity\Pilot, App\Security\LoginFormAuthenticator e README,
 * seção "Backend: login e perfil") e semeia o piloto mock que já
 * existia (CID 1234567), agora com senha real.
 *
 * Credencial de dev pra esse piloto semeado: CID 1234567, senha
 * "katabatic-dev" - o hash abaixo foi gerado com
 * password_hash('katabatic-dev', PASSWORD_BCRYPT) direto no PHP (não
 * precisa do Symfony rodando pra gerar). TROQUE essa senha (ou apague
 * a linha do INSERT) antes de qualquer coisa que não seja a sua
 * máquina de desenvolvimento.
 */
final class Version20260821120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela pilot e semeia o piloto de desenvolvimento (CID 1234567) com senha real.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE pilot (
                id SERIAL NOT NULL,
                cid VARCHAR(16) NOT NULL,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(180) NOT NULL,
                password VARCHAR(255) NOT NULL,
                photo VARCHAR(255) DEFAULT NULL,
                admin BOOLEAN NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_pilot_cid ON pilot (cid)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pilot_email ON pilot (email)');

        // Hash de "katabatic-dev" - ver aviso no docblock da classe.
        $devPasswordHash = '$2y$12$IT6faE.961UOyiEB.kfKxujl4tSHoOkD2VQ6yTaj2fO4XsVg34b6m';

        $this->addSql(
            'INSERT INTO pilot (cid, name, email, password, photo, admin, created_at) '.
            "VALUES ('1234567', 'Comandante', 'comandante@katabatic.vg', '".$devPasswordHash."', NULL, true, NOW())"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pilot');
    }
}
