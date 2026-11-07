<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Version company calendar entries without changing dates or existing request snapshots.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE holiday ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE TABLE request_assignment_context (id INT AUTO_INCREMENT NOT NULL, revision_id INT NOT NULL, assignment_id INT NOT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, INDEX IDX_95F1F8B41DFA7C8F (revision_id), INDEX IDX_95F1F8B4D19302F8 (assignment_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE request_assignment_context ADD CONSTRAINT FK_95F1F8B41DFA7C8F FOREIGN KEY (revision_id) REFERENCES request_revision (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_assignment_context ADD CONSTRAINT FK_95F1F8B4D19302F8 FOREIGN KEY (assignment_id) REFERENCES staff_assignment (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Calendar concurrency history must not be discarded.');
    }
}
