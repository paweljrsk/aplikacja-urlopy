<?php

namespace App\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\MySQLSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Provider\LazySchema;
use Doctrine\Migrations\Query\Query;
use DoctrineMigrations\Version20261106210000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20261106210000.php';

final class MigrationPreflightTest extends TestCase
{
    public function testAnExistingApplicationTableAbortsBeforeAnySqlIsQueued(): void
    {
        $schema = new Schema();
        $existingTable = $schema->createTable('app_user');
        $migration = $this->migration();

        try {
            $migration->up($schema);
            self::fail('An existing application table must prevent the migration.');
        } catch (AbortMigration $exception) {
            self::assertStringContainsString('app_user', $exception->getMessage());
            self::assertSame([], $migration->getSql());
            self::assertSame($existingTable, $schema->getTable('app_user'));
        }
    }

    public function testAnEmptyLazySchemaQueuesTheApplicationDdl(): void
    {
        // Doctrine Migrations passes this proxy to up(); exercise the installed DBAL API.
        $schema = LazySchema::createLazyProxy(static fn (): Schema => new Schema());
        $migration = $this->migration();

        $migration->up($schema);

        self::assertNotEmpty($migration->getSql());
        self::assertStringStartsWith('CREATE TABLE ', $migration->getSql()[0]->getStatement());
        self::assertFalse($migration->isTransactional());
    }

    public function testAnUnrelatedTableDoesNotChangeThePlannedMigration(): void
    {
        $emptyMigration = $this->migration();
        $emptyMigration->up(new Schema());
        $schema = new Schema();
        $unrelatedTable = $schema->createTable('external_reporting');
        $migration = $this->migration();

        $migration->up($schema);

        self::assertSame(
            array_map(static fn (Query $query): string => $query->getStatement(), $emptyMigration->getSql()),
            array_map(static fn (Query $query): string => $query->getStatement(), $migration->getSql()),
        );
        self::assertSame([$unrelatedTable], $schema->getTables());
    }

    private function migration(): Version20261106210000
    {
        $platform = new MySQL80Platform();
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('createSchemaManager')->willReturn(new MySQLSchemaManager($connection, $platform));
        $connection->expects(self::never())->method('executeStatement');

        return new Version20261106210000($connection, new NullLogger());
    }
}
