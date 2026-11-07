<?php

declare(strict_types=1);

// This probe deliberately writes only stage-4 columns before the stage-5 migration.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
$database = $connection->getDatabase();
if ('test' !== getenv('APP_ENV') || !is_string($database) || !str_ends_with($database, '_test')) {
    throw new RuntimeException('Only a dedicated synthetic test database is supported.');
}
$snapshotPath = sys_get_temp_dir().'/urlopy-stage5-upgrade.json';
$phase = $argv[1] ?? '';
if ('seed' === $phase) {
    $rows = [];
    $insert = static function (string $table, array $data) use ($connection, &$rows): int {
        $connection->insert($table, $data);
        $id = (int) $connection->lastInsertId();
        $rows[] = ['table' => $table, 'id' => $id];
        return $id;
    };
    $user = $insert('app_user', ['first_name' => 'Synthetic', 'last_name' => 'AccountingUpgrade', 'email' => 'stage5-upgrade@example.test', 'active' => 1, 'is_administrator' => 0, 'must_change_password' => 1, 'version' => 1, 'assignment_revision' => 1, 'credential_version' => 1]);
    $team = $insert('team', ['code' => 'stage5_upgrade', 'name' => 'Synthetic accounting upgrade', 'active' => 1, 'version' => 1]);
    $form = $insert('employment_type', ['code' => 'stage5_upgrade_uop', 'name' => 'Synthetic UoP', 'carries_unused_hours' => 1, 'active' => 1, 'version' => 1]);
    $assignment = $insert('staff_assignment', ['user_id' => $user, 'team_id' => $team, 'employment_type_id' => $form, 'role' => 'employee', 'valid_from' => '2026-01-01']);
    $type = $insert('absence_type', ['code' => 'stage5_upgrade_paid', 'active' => 1, 'latest_revision' => 1, 'version' => 1]);
    $policy = $insert('absence_type_version', ['type_id' => $type, 'revision' => 1, 'name' => 'Synthetic historical leave', 'deducts_balance' => 1, 'names_by_employment_type' => '{}']);
    $connection->insert('absence_type_availability', ['version_id' => $policy, 'employment_type_id' => $form]);
    $event = $insert('audit_event', ['operation_key' => 'stage5.upgrade.opening', 'action' => 'opening.recorded', 'target_type' => 'user', 'target_reference' => (string) $user, 'occurred_at' => '2026-11-01 08:00:00', 'before_data' => '{}', 'after_data' => '{"annual_hours":80}', 'actor_id' => $user, 'subject_id' => $user]);
    $account = $insert('leave_account', ['user_id' => $user, 'leave_year' => 2026, 'version' => 4, 'movement_revision' => 4]);
    $unconfigured = $insert('leave_account', ['user_id' => $user, 'leave_year' => 2027, 'version' => 1, 'movement_revision' => 0]);
    $pool = $insert('hour_pool', ['user_id' => $user, 'origin_year' => 2026, 'version' => 3, 'movement_revision' => 2]);
    $carryPool = $insert('hour_pool', ['user_id' => $user, 'origin_year' => 2025, 'version' => 3, 'movement_revision' => 2]);
    $opening = $insert('opening_balance', ['user_id' => $user, 'source_id' => $event, 'effective_date' => '2026-11-01', 'annual_hours' => 80, 'current_year_used_hours' => 8]);
    $insert('opening_carry', ['opening_balance_id' => $opening, 'origin_year' => 2025, 'remaining_hours' => 16]);
    foreach ([[1, 'grant', $pool, 80, 0, 0], [2, 'opening_used', $pool, 0, 0, 8], [3, 'grant', $carryPool, 16, 0, 0]] as [$line, $kind, $origin, $allocated, $reserved, $charged]) {
        $insert('ledger_entry', ['account_id' => $account, 'pool_id' => $origin, 'event_id' => $event, 'line_number' => $line, 'kind' => $kind, 'allocated_delta' => $allocated, 'reserved_delta' => $reserved, 'charged_delta' => $charged]);
    }
    $submitted = $insert('audit_event', ['operation_key' => 'stage5.upgrade.submitted', 'action' => 'request.submitted', 'target_type' => 'user', 'target_reference' => (string) $user, 'occurred_at' => '2026-11-02 08:00:00', 'before_data' => '{}', 'after_data' => '{"hours":8}', 'actor_id' => $user, 'subject_id' => $user]);
    $request = $insert('leave_request', ['user_id' => $user, 'reference' => '55000000000000000000000000000001', 'created_at' => '2026-11-02 08:00:00', 'version' => 2, 'mutation_revision' => 1]);
    $revision = $insert('request_revision', ['request_id' => $request, 'policy_id' => $policy, 'source_id' => $submitted, 'revision' => 1, 'kind' => 'initial', 'mode' => 'full_days', 'start_date' => '2026-12-15', 'end_date' => '2026-12-15']);
    $day = $insert('request_day', ['revision_id' => $revision, 'assignment_id' => $assignment, 'date' => '2026-12-15', 'hours' => 8]);
    $insert('ledger_entry', ['account_id' => $account, 'pool_id' => $carryPool, 'event_id' => $submitted, 'day_id' => $day, 'line_number' => 1, 'kind' => 'reserve', 'allocated_delta' => 0, 'reserved_delta' => 8, 'charged_delta' => 0]);
    $insert('daily_occupancy', ['user_id' => $user, 'date' => '2026-12-15', 'hours' => 8, 'version' => 2]);
    $insert('holiday', ['date' => '2026-12-14', 'name' => 'Synthetic historical company day', 'active' => 1]);
    foreach ($rows as &$row) {
        $row['values'] = $connection->fetchAssociative('SELECT * FROM '.$row['table'].' WHERE id = ?', [$row['id']]);
    }
    unset($row);
    file_put_contents($snapshotPath, json_encode(['rows' => $rows, 'account' => $account, 'unconfigured' => $unconfigured], JSON_THROW_ON_ERROR));
    echo "Synthetic stage 4 opening, pools, pending request and ledger saved before migration.\n";
} elseif ('verify' === $phase) {
    $snapshot = json_decode(file_get_contents($snapshotPath), true, flags: JSON_THROW_ON_ERROR);
    $allowed = ['app_user', 'team', 'employment_type', 'staff_assignment', 'absence_type', 'absence_type_version', 'audit_event', 'leave_account', 'hour_pool', 'opening_balance', 'opening_carry', 'ledger_entry', 'leave_request', 'request_revision', 'request_day', 'daily_occupancy', 'holiday'];
    foreach ($snapshot['rows'] as $row) {
        if (!in_array($row['table'], $allowed, true) || !is_int($row['id'])) {
            throw new RuntimeException('Invalid synthetic snapshot.');
        }
        $actual = $connection->fetchAssociative('SELECT * FROM '.$row['table'].' WHERE id = ?', [$row['id']]);
        foreach ($row['values'] as $column => $expected) {
            if (!$actual || !array_key_exists($column, $actual) || $actual[$column] !== $expected) {
                throw new RuntimeException('Historical column changed: '.$row['table'].'.'.$column);
            }
        }
    }
    $configured = $connection->fetchOne('SELECT annual_limit_hours FROM leave_account WHERE id = ?', [$snapshot['account']]);
    $unconfigured = $connection->fetchOne('SELECT annual_limit_hours FROM leave_account WHERE id = ?', [$snapshot['unconfigured']]);
    if (80 !== (int) $configured || null !== $unconfigured) {
        throw new RuntimeException('Annual limit backfill did not distinguish the existing allocation from a missing limit.');
    }
    echo "Stage 4 accounting, request history, occupancy and calendar preserved; annual limit safely backfilled.\n";
} else {
    throw new InvalidArgumentException('Expected seed or verify phase.');
}
