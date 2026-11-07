<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Administration concurrency control and versioned per-employment absence names; preserve existing accounts and requests.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE employment_type ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE absence_type_version ADD names_by_employment_type JSON DEFAULT NULL');
        $this->addSql("UPDATE absence_type_version SET names_by_employment_type = JSON_OBJECT() WHERE names_by_employment_type IS NULL");
        $this->addSql('ALTER TABLE absence_type_version MODIFY names_by_employment_type JSON NOT NULL');
        $this->addSql('CREATE TABLE administration_lock (id INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO administration_lock (id) VALUES (1)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Administrative versions and historical display names must not be discarded.');
    }
}
