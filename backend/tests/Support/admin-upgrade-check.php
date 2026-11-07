<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
/** @var Doctrine\Persistence\ManagerRegistry $registry */
$registry = $kernel->getContainer()->get('doctrine');
$connection = $registry->getConnection();
$database = $connection->getDatabase();
if ('test' !== getenv('APP_ENV') || !is_string($database) || !str_ends_with($database, '_test')) {
    throw new RuntimeException('Only a dedicated synthetic test database is supported.');
}
$phase = $argv[1] ?? '';
if ('seed' === $phase) {
    // Deliberately use the stage-3 columns, before the administrative migration exists.
    $connection->insert('team', ['code' => 'stage4_upgrade_team', 'name' => 'Synthetic historical team', 'active' => 0]);
    $connection->insert('employment_type', ['code' => 'stage4_upgrade_form', 'name' => 'Synthetic historical form', 'active' => 0, 'carries_unused_hours' => 0]);
    $employmentId = (int) $connection->lastInsertId();
    $connection->insert('absence_type', ['code' => 'stage4_upgrade_absence', 'active' => 0, 'latest_revision' => 1, 'version' => 7]);
    $typeId = (int) $connection->lastInsertId();
    $connection->insert('absence_type_version', ['type_id' => $typeId, 'revision' => 1, 'name' => 'Synthetic historical absence', 'deducts_balance' => 1]);
    $policyId = (int) $connection->lastInsertId();
    $connection->insert('absence_type_availability', ['version_id' => $policyId, 'employment_type_id' => $employmentId]);
    echo "Synthetic stage 3 dictionaries seeded before migration.\n";
} elseif ('verify' === $phase) {
    $team = $connection->fetchAssociative("SELECT name,active,version FROM team WHERE code = 'stage4_upgrade_team'");
    $employment = $connection->fetchAssociative("SELECT name,active,version,carries_unused_hours FROM employment_type WHERE code = 'stage4_upgrade_form'");
    $policy = $connection->fetchAssociative("SELECT t.active,t.latest_revision,t.version,v.name,v.deducts_balance,v.names_by_employment_type,(SELECT COUNT(*) FROM absence_type_availability a WHERE a.version_id=v.id) available FROM absence_type t JOIN absence_type_version v ON v.type_id=t.id WHERE t.code='stage4_upgrade_absence'");
    if (!$team || 'Synthetic historical team' !== $team['name'] || 0 !== (int) $team['active'] || 1 !== (int) $team['version']
        || !$employment || 'Synthetic historical form' !== $employment['name'] || 0 !== (int) $employment['active'] || 1 !== (int) $employment['version'] || 0 !== (int) $employment['carries_unused_hours']
        || !$policy || 'Synthetic historical absence' !== $policy['name'] || 0 !== (int) $policy['active'] || 1 !== (int) $policy['latest_revision'] || 7 !== (int) $policy['version']
        || 1 !== (int) $policy['deducts_balance'] || [] !== json_decode($policy['names_by_employment_type'], true, flags: JSON_THROW_ON_ERROR) || 1 !== (int) $policy['available']
        || 1 !== (int) $connection->fetchOne('SELECT COUNT(*) FROM administration_lock WHERE id = 1')) {
        throw new RuntimeException('Stage 4 migration changed historical dictionary values or failed to initialize defaults.');
    }
    echo "Stage 3 dictionary history, availability, active flags and versions preserved by stage 4 migration.\n";
} else {
    throw new InvalidArgumentException('Expected seed or verify phase.');
}
