<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Explicit annual limit configuration and rebuildable request occupancy, preserving the immutable ledger and initial balances.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leave_account ADD annual_limit_hours INT DEFAULT NULL');
        // Existing grants and opening snapshots configure the year, including a zero opening limit.
        $this->addSql('UPDATE leave_account a SET annual_limit_hours = COALESCE((SELECT SUM(l.allocated_delta) FROM ledger_entry l INNER JOIN hour_pool p ON p.id = l.pool_id WHERE l.account_id = a.id AND p.origin_year = a.leave_year), 0) WHERE EXISTS (SELECT 1 FROM ledger_entry l INNER JOIN hour_pool p ON p.id = l.pool_id WHERE l.account_id = a.id AND p.origin_year = a.leave_year AND l.allocated_delta <> 0) OR EXISTS (SELECT 1 FROM opening_balance o WHERE o.user_id = a.user_id AND YEAR(o.effective_date) = a.leave_year)');
        $this->addSql('CREATE TABLE request_accounting_state (id INT AUTO_INCREMENT NOT NULL, request_id INT NOT NULL, occupancy JSON NOT NULL, UNIQUE INDEX UNIQ_ACCOUNTING_REQUEST (request_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE request_accounting_state ADD CONSTRAINT FK_ACCOUNTING_REQUEST FOREIGN KEY (request_id) REFERENCES leave_request (id) ON DELETE RESTRICT');
        $this->addSql("INSERT INTO request_accounting_state (request_id, occupancy) SELECT q.id, COALESCE((SELECT JSON_OBJECTAGG(x.day_date, x.hours) FROM (SELECT r.request_id, DATE_FORMAT(d.date, '%Y-%m-%d') AS day_date, MAX(d.hours) AS hours FROM request_revision r INNER JOIN request_day d ON d.revision_id = r.id LEFT JOIN request_decision rd ON rd.revision_id = r.id WHERE rd.id IS NULL OR (rd.outcome = 'approved' AND r.kind <> 'cancellation' AND r.revision = (SELECT MAX(r2.revision) FROM request_revision r2 INNER JOIN request_decision rd2 ON rd2.revision_id = r2.id WHERE r2.request_id = r.request_id AND rd2.outcome = 'approved')) GROUP BY r.request_id, d.date) x WHERE x.request_id = q.id), JSON_OBJECT()) FROM leave_request q");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Annual configuration and accounting projections must not be silently removed.');
    }
}
