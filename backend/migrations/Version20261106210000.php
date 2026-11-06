<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stage 2: staff history, versioned leave requests, opening balances, hour ledger and audit';
    }

    public function isTransactional(): bool
    {
        // MySQL DDL commits implicitly; no transactional guarantee is claimed here.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL 8.0.32 or compatible newer MySQL.');
        // Fail before touching an existing/partially migrated application schema.
        $ownedTables = ['absence_type', 'absence_type_version', 'absence_type_availability', 'audit_event', 'daily_occupancy', 'employment_type', 'holiday', 'hour_pool', 'leave_account', 'leave_request', 'ledger_entry', 'opening_balance', 'opening_carry', 'request_day', 'request_decision', 'request_revision', 'staff_assignment', 'team', 'app_user'];
        $existing = array_intersect($ownedTables, $schema->getTableNames());
        $this->abortIf([] !== $existing, 'Application tables already exist: '.implode(', ', $existing).'. Inspect the database; do not reset or overwrite data.');
        $this->addSql('CREATE TABLE absence_type (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, active TINYINT NOT NULL, latest_revision INT NOT NULL, version INT DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_FBCF99B677153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE absence_type_version (id INT AUTO_INCREMENT NOT NULL, revision INT NOT NULL, name VARCHAR(255) NOT NULL, deducts_balance TINYINT NOT NULL, type_id INT NOT NULL, UNIQUE INDEX uniq_absence_type_revision (type_id, revision), INDEX IDX_7EC7135CC54C8C93 (type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE absence_type_availability (version_id INT NOT NULL, employment_type_id INT NOT NULL, INDEX IDX_4991C0A64BBC2705 (version_id), INDEX IDX_4991C0A61BCDC34A (employment_type_id), PRIMARY KEY (version_id, employment_type_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_event (id INT AUTO_INCREMENT NOT NULL, operation_key VARCHAR(100) NOT NULL, action VARCHAR(80) NOT NULL, target_type VARCHAR(40) NOT NULL, target_reference VARCHAR(255) NOT NULL, occurred_at DATETIME NOT NULL, before_data JSON NOT NULL, after_data JSON NOT NULL, actor_id INT DEFAULT NULL, subject_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_3CE56E5095B1BD95 (operation_key), INDEX idx_audit_subject_time (subject_id, occurred_at), INDEX IDX_3CE56E5010DAF24A (actor_id), INDEX IDX_3CE56E5023EDC87 (subject_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE daily_occupancy (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, hours INT NOT NULL, version INT DEFAULT 1 NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_user_occupancy_date (user_id, date), INDEX IDX_40A49ECA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE employment_type (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, carries_unused_hours TINYINT NOT NULL, active TINYINT NOT NULL, UNIQUE INDEX UNIQ_AA55F63077153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE holiday (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, name VARCHAR(255) NOT NULL, active TINYINT NOT NULL, UNIQUE INDEX UNIQ_DC9AB234AA9E377A (date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hour_pool (id INT AUTO_INCREMENT NOT NULL, version INT DEFAULT 1 NOT NULL, movement_revision INT NOT NULL, origin_year INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_hour_pool_user_origin (user_id, origin_year), INDEX IDX_C455F8C3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE leave_account (id INT AUTO_INCREMENT NOT NULL, version INT DEFAULT 1 NOT NULL, movement_revision INT NOT NULL, leave_year INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_leave_account_user_year (user_id, leave_year), INDEX IDX_3B692E43A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE leave_request (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, mutation_revision INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_7DC8F778AEA34913 (reference), INDEX IDX_7DC8F778A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ledger_entry (id INT AUTO_INCREMENT NOT NULL, line_number INT NOT NULL, kind VARCHAR(20) NOT NULL, allocated_delta INT NOT NULL, reserved_delta INT NOT NULL, charged_delta INT NOT NULL, account_id INT NOT NULL, pool_id INT NOT NULL, event_id INT NOT NULL, day_id INT DEFAULT NULL, INDEX idx_ledger_account_pool (account_id, pool_id), UNIQUE INDEX uniq_ledger_event_line (event_id, line_number), INDEX IDX_64272A699B6B5FBA (account_id), INDEX IDX_64272A697B3406DF (pool_id), INDEX IDX_64272A6971F7E88B (event_id), INDEX IDX_64272A699C24126 (day_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE opening_balance (id INT AUTO_INCREMENT NOT NULL, effective_date DATE NOT NULL, annual_hours INT NOT NULL, current_year_used_hours INT NOT NULL, user_id INT NOT NULL, source_id INT NOT NULL, UNIQUE INDEX UNIQ_989EB17EA76ED395 (user_id), INDEX IDX_989EB17E953C1C61 (source_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE opening_carry (id INT AUTO_INCREMENT NOT NULL, origin_year INT NOT NULL, remaining_hours INT NOT NULL, opening_balance_id INT NOT NULL, UNIQUE INDEX uniq_opening_carry_origin (opening_balance_id, origin_year), INDEX IDX_54D578B2D247A148 (opening_balance_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_day (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, hours INT NOT NULL, revision_id INT NOT NULL, assignment_id INT NOT NULL, INDEX idx_request_day_date (date), UNIQUE INDEX uniq_revision_date (revision_id, date), INDEX IDX_288A68DC1DFA7C8F (revision_id), INDEX IDX_288A68DCD19302F8 (assignment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_decision (id INT AUTO_INCREMENT NOT NULL, outcome VARCHAR(16) NOT NULL, revision_id INT NOT NULL, event_id INT NOT NULL, UNIQUE INDEX UNIQ_71895E6D1DFA7C8F (revision_id), INDEX IDX_71895E6D71F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_revision (id INT AUTO_INCREMENT NOT NULL, revision INT NOT NULL, kind VARCHAR(16) NOT NULL, mode VARCHAR(16) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, request_id INT NOT NULL, policy_id INT NOT NULL, source_id INT NOT NULL, base_revision_id INT DEFAULT NULL, INDEX idx_revision_range (start_date, end_date), UNIQUE INDEX uniq_request_revision (request_id, revision), INDEX IDX_9846F5E9427EB8A5 (request_id), INDEX IDX_9846F5E92D29E3C6 (policy_id), INDEX IDX_9846F5E9953C1C61 (source_id), INDEX IDX_9846F5E914DF8A77 (base_revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE staff_assignment (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(16) NOT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, user_id INT NOT NULL, team_id INT NOT NULL, employment_type_id INT NOT NULL, INDEX idx_staff_assignment_team_period (team_id, valid_from, valid_until), UNIQUE INDEX uniq_staff_assignment_start (user_id, valid_from), INDEX IDX_D697546BA76ED395 (user_id), INDEX IDX_D697546B296CD8AE (team_id), INDEX IDX_D697546B1BCDC34A (employment_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE team (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, active TINYINT NOT NULL, UNIQUE INDEX UNIQ_C4E0A61F77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(254) NOT NULL, active TINYINT NOT NULL, is_administrator TINYINT NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, must_change_password TINYINT NOT NULL, version INT DEFAULT 1 NOT NULL, assignment_revision INT NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE absence_type_version ADD CONSTRAINT FK_7EC7135CC54C8C93 FOREIGN KEY (type_id) REFERENCES absence_type (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE absence_type_availability ADD CONSTRAINT FK_4991C0A64BBC2705 FOREIGN KEY (version_id) REFERENCES absence_type_version (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE absence_type_availability ADD CONSTRAINT FK_4991C0A61BCDC34A FOREIGN KEY (employment_type_id) REFERENCES employment_type (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE audit_event ADD CONSTRAINT FK_3CE56E5010DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE audit_event ADD CONSTRAINT FK_3CE56E5023EDC87 FOREIGN KEY (subject_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE daily_occupancy ADD CONSTRAINT FK_40A49ECA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE hour_pool ADD CONSTRAINT FK_C455F8C3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE leave_account ADD CONSTRAINT FK_3B692E43A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE leave_request ADD CONSTRAINT FK_7DC8F778A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT FK_64272A699B6B5FBA FOREIGN KEY (account_id) REFERENCES leave_account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT FK_64272A697B3406DF FOREIGN KEY (pool_id) REFERENCES hour_pool (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT FK_64272A6971F7E88B FOREIGN KEY (event_id) REFERENCES audit_event (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT FK_64272A699C24126 FOREIGN KEY (day_id) REFERENCES request_day (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE opening_balance ADD CONSTRAINT FK_989EB17EA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE opening_balance ADD CONSTRAINT FK_989EB17E953C1C61 FOREIGN KEY (source_id) REFERENCES audit_event (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE opening_carry ADD CONSTRAINT FK_54D578B2D247A148 FOREIGN KEY (opening_balance_id) REFERENCES opening_balance (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_day ADD CONSTRAINT FK_288A68DC1DFA7C8F FOREIGN KEY (revision_id) REFERENCES request_revision (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_day ADD CONSTRAINT FK_288A68DCD19302F8 FOREIGN KEY (assignment_id) REFERENCES staff_assignment (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_decision ADD CONSTRAINT FK_71895E6D1DFA7C8F FOREIGN KEY (revision_id) REFERENCES request_revision (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_decision ADD CONSTRAINT FK_71895E6D71F7E88B FOREIGN KEY (event_id) REFERENCES audit_event (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_revision ADD CONSTRAINT FK_9846F5E9427EB8A5 FOREIGN KEY (request_id) REFERENCES leave_request (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_revision ADD CONSTRAINT FK_9846F5E92D29E3C6 FOREIGN KEY (policy_id) REFERENCES absence_type_version (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_revision ADD CONSTRAINT FK_9846F5E9953C1C61 FOREIGN KEY (source_id) REFERENCES audit_event (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_revision ADD CONSTRAINT FK_9846F5E914DF8A77 FOREIGN KEY (base_revision_id) REFERENCES request_revision (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE staff_assignment ADD CONSTRAINT FK_D697546BA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE staff_assignment ADD CONSTRAINT FK_D697546B296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE staff_assignment ADD CONSTRAINT FK_D697546B1BCDC34A FOREIGN KEY (employment_type_id) REFERENCES employment_type (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE absence_type ADD CONSTRAINT chk_absence_type_version CHECK (latest_revision >= 0 AND version >= 1)');
        $this->addSql('ALTER TABLE absence_type ADD CONSTRAINT chk_absence_type_active CHECK (active IN (0, 1))');
        $this->addSql('ALTER TABLE absence_type_version ADD CONSTRAINT chk_absence_policy_revision CHECK (revision >= 1)');
        $this->addSql('ALTER TABLE absence_type_version ADD CONSTRAINT chk_absence_policy_deducts CHECK (deducts_balance IN (0, 1))');
        $this->addSql('ALTER TABLE daily_occupancy ADD CONSTRAINT chk_daily_hours CHECK (hours BETWEEN 0 AND 8)');
        $this->addSql('ALTER TABLE daily_occupancy ADD CONSTRAINT chk_daily_version CHECK (version >= 1)');
        $this->addSql('ALTER TABLE employment_type ADD CONSTRAINT chk_employment_flags CHECK (carries_unused_hours IN (0, 1) AND active IN (0, 1))');
        $this->addSql('ALTER TABLE holiday ADD CONSTRAINT chk_holiday_active CHECK (active IN (0, 1))');
        $this->addSql('ALTER TABLE hour_pool ADD CONSTRAINT chk_pool_year CHECK (origin_year BETWEEN 1000 AND 9999)');
        $this->addSql('ALTER TABLE hour_pool ADD CONSTRAINT chk_pool_version CHECK (version >= 1 AND movement_revision >= 0)');
        $this->addSql('ALTER TABLE leave_account ADD CONSTRAINT chk_account_year CHECK (leave_year BETWEEN 1000 AND 9999)');
        $this->addSql('ALTER TABLE leave_account ADD CONSTRAINT chk_account_version CHECK (version >= 1 AND movement_revision >= 0)');
        $this->addSql('ALTER TABLE leave_request ADD CONSTRAINT chk_request_version CHECK (version >= 1 AND mutation_revision >= 0)');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT chk_ledger_line CHECK (line_number >= 1)');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT chk_ledger_kind CHECK (BINARY kind IN (\'grant\', \'opening_used\', \'reserve\', \'release\', \'approve\', \'cancel\', \'adjustment\'))');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT chk_ledger_day CHECK ((BINARY kind NOT IN (\'reserve\', \'release\', \'approve\', \'cancel\') OR day_id IS NOT NULL) AND (BINARY kind <> \'opening_used\' OR day_id IS NULL))');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT chk_ledger_deltas CHECK ((BINARY kind = \'grant\' AND allocated_delta > 0 AND reserved_delta = 0 AND charged_delta = 0) OR (BINARY kind = \'opening_used\' AND allocated_delta = 0 AND reserved_delta = 0 AND charged_delta > 0) OR (BINARY kind = \'reserve\' AND allocated_delta = 0 AND reserved_delta > 0 AND charged_delta = 0) OR (BINARY kind = \'release\' AND allocated_delta = 0 AND reserved_delta < 0 AND charged_delta = 0) OR (BINARY kind = \'approve\' AND allocated_delta = 0 AND reserved_delta < 0 AND charged_delta = -reserved_delta) OR (BINARY kind = \'cancel\' AND allocated_delta = 0 AND reserved_delta = 0 AND charged_delta < 0) OR (BINARY kind = \'adjustment\' AND (allocated_delta <> 0 OR reserved_delta <> 0 OR charged_delta <> 0)))');
        $this->addSql('ALTER TABLE opening_balance ADD CONSTRAINT chk_opening_hours CHECK (annual_hours >= 0 AND current_year_used_hours >= 0)');
        $this->addSql('ALTER TABLE opening_carry ADD CONSTRAINT chk_opening_carry_hours CHECK (remaining_hours >= 0)');
        $this->addSql('ALTER TABLE opening_carry ADD CONSTRAINT chk_carry_year CHECK (origin_year BETWEEN 1000 AND 9999)');
        $this->addSql('ALTER TABLE request_day ADD CONSTRAINT chk_request_day_hours CHECK (hours BETWEEN 1 AND 8)');
        $this->addSql('ALTER TABLE request_decision ADD CONSTRAINT chk_decision_outcome CHECK (BINARY outcome IN (\'approved\', \'rejected\', \'withdrawn\', \'superseded\'))');
        $this->addSql('ALTER TABLE request_revision ADD CONSTRAINT chk_revision_number CHECK (revision >= 1)');
        $this->addSql('ALTER TABLE request_revision ADD CONSTRAINT chk_revision_range CHECK (end_date >= start_date)');
        $this->addSql('ALTER TABLE request_revision ADD CONSTRAINT chk_revision_mode CHECK (BINARY mode IN (\'full_days\', \'hourly\') AND (BINARY mode <> \'hourly\' OR start_date = end_date))');
        $this->addSql('ALTER TABLE request_revision ADD CONSTRAINT chk_revision_kind CHECK ((BINARY kind = \'initial\' AND base_revision_id IS NULL) OR (BINARY kind IN (\'change\', \'cancellation\') AND base_revision_id IS NOT NULL))');
        $this->addSql('ALTER TABLE staff_assignment ADD CONSTRAINT chk_assignment_period CHECK (valid_until IS NULL OR valid_until > valid_from)');
        $this->addSql('ALTER TABLE staff_assignment ADD CONSTRAINT chk_assignment_role CHECK (BINARY role IN (\'employee\', \'manager\', \'deputy\'))');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT chk_user_version CHECK (version >= 1 AND assignment_revision >= 0)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT chk_user_flags CHECK (active IN (0, 1) AND is_administrator IN (0, 1) AND must_change_password IN (0, 1))');
        $this->addSql('ALTER TABLE team ADD CONSTRAINT chk_team_active CHECK (active IN (0, 1))');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping this schema would delete staff and accounting history. Use a reviewed recovery procedure instead.');
    }
}
