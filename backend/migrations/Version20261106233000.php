<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stage 3: credential revision for session revocation and temporary-password expiration';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');
        $this->addSql('ALTER TABLE app_user ADD credential_version INT DEFAULT 1 NOT NULL, ADD temporary_password_expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT chk_user_credential_version CHECK (credential_version >= 1)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT chk_user_temporary_expiry CHECK (temporary_password_expires_at IS NULL OR (must_change_password = 1 AND password_hash IS NOT NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping credential revision or expiry would remove authentication state. Use a reviewed forward migration.');
    }
}
