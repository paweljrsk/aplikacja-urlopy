<?php

namespace App\Tests\Integration;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\DailyOccupancy;
use App\Entity\EmploymentType;
use App\Entity\HourPool;
use App\Entity\LeaveAccount;
use App\Entity\LeaveRequest;
use App\Entity\LedgerEntry;
use App\Entity\OpeningBalance;
use App\Entity\OpeningCarry;
use App\Entity\RequestDecision;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DecisionOutcome;
use App\Enum\LedgerEntryKind;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Enum\TeamRole;
use App\Model\RequestedDay;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against a migrated, isolated MySQL database. It never creates or resets schema.
 * Setup rows are committed deliberately: concurrency tests use independent connections.
 */
final class ModelPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $prefix;

    /** @var list<EntityManagerInterface> */
    private array $extraManagers = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::assertSame('test', self::$kernel?->getEnvironment());
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->em = $manager;
        $database = $manager->getConnection()->getDatabase();
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', $database, 'Refusing to write outside the dedicated test database.');
        self::assertStringContainsString('mysql', $manager->getConnection()->getParams()['driver'] ?? '');
        $this->prefix = 'it_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->extraManagers as $manager) {
            $connection = $manager->getConnection();
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $manager->close();
            $connection->close();
        }
        $this->extraManagers = [];
        parent::tearDown();
    }

    public function testMigratedSchemaMatchesDoctrineMetadata(): void
    {
        $validator = new SchemaValidator($this->em);
        self::assertSame([], $validator->validateMapping());
        self::assertTrue($validator->schemaInSyncWithMetadata(), 'Migration schema must match ORM metadata.');
    }

    public function testUtcTimestampsRoundTripIndependentlyOfPhpDefaultTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $seed = $this->seedRequest();
            $requestId = $seed['request']->getId();
            $expected = $this->time();
            $this->em->clear();

            $restored = $this->find(LeaveRequest::class, $requestId);
            $occurredAt = $restored->getRevisions()[0]->getSource()->getOccurredAt();
            self::assertSame($expected->getTimestamp(), $restored->getCreatedAt()->getTimestamp());
            self::assertSame($expected->getTimestamp(), $occurredAt->getTimestamp());
            self::assertSame('UTC', $restored->getCreatedAt()->getTimezone()->getName());
            self::assertSame('UTC', $occurredAt->getTimezone()->getName());
            self::assertSame('2026-12-15', $restored->getRevisions()[0]->getDays()[0]->getDate()->format('Y-m-d'));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testHistoricalAssignmentsPoliciesAndDecisionsSurviveRoundTrip(): void
    {
        $seed = $this->seedRequest();
        $initial = $seed['revision'];
        $approval = $this->event($seed['user'], 'approve', $seed['actor']);
        $firstDecision = new RequestDecision($initial, DecisionOutcome::APPROVED, $approval);
        $this->persist($approval, $firstDecision);
        $this->em->flush();

        $newPolicy = $seed['type']->createVersion('Changed policy', false, [$seed['employment']]);
        $proposed = $this->revision($seed, RequestChangeKind::CHANGE, '2026-12-16', 'change', $initial);
        self::assertSame($initial, $seed['request']->getEffectiveRevision());
        $rejection = $this->event($seed['user'], 'reject', $seed['actor']);
        $rejected = new RequestDecision($proposed, DecisionOutcome::REJECTED, $rejection);
        $this->persist($newPolicy, $rejection, $rejected);
        $this->em->flush();

        $seed['assignment']->closeAt($this->date('2027-01-01'));
        $newTeam = new Team($this->prefix.'_next', 'Future team');
        $b2b = new EmploymentType($this->prefix.'_b2b', 'B2B', false);
        $nextAssignment = new StaffAssignment($seed['user'], $newTeam, $b2b, TeamRole::EMPLOYEE, $this->date('2027-01-01'));
        $seed['user']->deactivate();
        $seed['type']->deactivate();
        $this->persist($newTeam, $b2b, $nextAssignment);
        $this->em->flush();

        $requestId = $seed['request']->getId();
        $newPolicyId = $newPolicy->getId();
        $this->em->clear();
        $restored = $this->find(LeaveRequest::class, $requestId);
        self::assertFalse($restored->getUser()->isActive());
        self::assertCount(2, $restored->getUser()->getAssignments());
        self::assertSame('2027-01-01', $restored->getUser()->getAssignments()[0]->getValidUntil()?->format('Y-m-d'));
        self::assertSame('2027-01-01', $restored->getUser()->getAssignments()[1]->getValidFrom()->format('Y-m-d'));
        self::assertCount(2, $restored->getRevisions());
        $effective = $restored->getEffectiveRevision();
        self::assertNotNull($effective);
        self::assertSame(1, $effective->getRevision());
        self::assertSame(DecisionOutcome::REJECTED, $restored->getRevisions()[1]->getDecision()?->getOutcome());
        self::assertSame('2026-12-15', $effective->getDays()[0]->getDate()->format('Y-m-d'));
        self::assertSame(8, $effective->getTotalHours());
        self::assertTrue($effective->getPolicy()->deductsBalance());
        self::assertFalse($this->find(AbsenceTypeVersion::class, $newPolicyId)->deductsBalance());
        self::assertFalse($effective->getPolicy()->getType()->isActive());
        self::assertSame($this->prefix.'_team', $effective->getDays()[0]->getAssignment()->getTeam()->getCode());

        // Rebuild a cancellation from dates hydrated by DBAL in the process's default timezone.
        $cancelSource = $this->event($restored->getUser(), 'cancel', $this->find(User::class, $seed['actor']->getId()));
        $cancellation = new RequestRevision(
            $restored, RequestChangeKind::CANCELLATION, $effective->getPolicy(), $effective->getMode(),
            $effective->getStartDate(), $effective->getEndDate(),
            [new RequestedDay($effective->getDays()[0]->getAssignment(), $effective->getDays()[0]->getDate(), 8)],
            $cancelSource, $effective,
        );
        self::assertSame($effective, $restored->getEffectiveRevision());
        $cancelApproval = $this->event($restored->getUser(), 'cancel_approve', $cancelSource->getActor());
        $cancelDecision = new RequestDecision($cancellation, DecisionOutcome::APPROVED, $cancelApproval);
        $this->persist($cancelSource, $cancelApproval, $cancelDecision);
        $this->em->flush();
        $this->em->clear();
        $cancelled = $this->find(LeaveRequest::class, $requestId);
        self::assertNull($cancelled->getEffectiveRevision());
        self::assertCount(3, $cancelled->getRevisions());
        self::assertSame(DecisionOutcome::APPROVED, $cancelled->getRevisions()[0]->getDecision()?->getOutcome());
    }

    public function testOpeningAndLedgerPreserveOriginYearWithoutDuplicatingCarryUsage(): void
    {
        $seed = $this->seedRequest();
        $account = new LeaveAccount($seed['user'], 2026);
        $current = new HourPool($seed['user'], 2026);
        $old = new HourPool($seed['user'], 2025);
        $event = $this->event($seed['user'], 'opening', $seed['actor']);
        $opening = new OpeningBalance($seed['user'], $this->date('2026-11-01'), 208, 40, $event);
        new OpeningCarry($opening, 2025, 16);
        $entries = [
            new LedgerEntry($account, $current, $event, 1, LedgerEntryKind::GRANT, 208, 0, 0),
            new LedgerEntry($account, $current, $event, 2, LedgerEntryKind::OPENING_USED, 0, 0, 40),
            new LedgerEntry($account, $old, $event, 3, LedgerEntryKind::GRANT, 16, 0, 0),
        ];
        $this->persist($account, $current, $old, $event, $opening, ...$entries);
        $this->em->flush();
        $openingId = $opening->getId();
        $accountId = $account->getId();
        $this->em->clear();

        $restored = $this->find(OpeningBalance::class, $openingId);
        self::assertSame('2026-11-01', $restored->getEffectiveDate()->format('Y-m-d'));
        self::assertSame(40, $restored->getCurrentYearUsedHours());
        self::assertCount(1, $restored->getCarries());
        self::assertSame(2025, $restored->getCarries()[0]->getOriginYear());
        self::assertSame(16, $restored->getCarries()[0]->getRemainingHours());
        self::assertSame(184, (int) $this->em->getConnection()->fetchOne('SELECT SUM(allocated_delta - reserved_delta - charged_delta) FROM ledger_entry WHERE account_id = ?', [$accountId]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT SUM(charged_delta) FROM ledger_entry e JOIN hour_pool p ON e.pool_id = p.id WHERE e.account_id = ? AND p.origin_year = 2025', [$accountId]));
    }

    public function testDatabaseUniquenessForeignKeysAndChecksRejectInvalidWrites(): void
    {
        $seed = $this->seedRequest();
        $account = new LeaveAccount($seed['user'], 2026);
        $pool = new HourPool($seed['user'], 2026);
        $event = $this->event($seed['user'], 'grant', $seed['actor']);
        $entry = new LedgerEntry($account, $pool, $event, 1, LedgerEntryKind::GRANT, 208, 0, 0);
        $opening = new OpeningBalance($seed['user'], $this->date('2026-11-01'), 208, 40, $event);
        new OpeningCarry($opening, 2025, 16);
        $occupancy = new DailyOccupancy($seed['user'], $this->date('2026-12-15'));
        $occupancy->changeBy(8);
        $decision = new RequestDecision($seed['revision'], DecisionOutcome::APPROVED, $event);
        $this->persist($account, $pool, $event, $entry, $opening, $occupancy, $decision);
        $this->em->flush();

        self::assertSame(strtolower($seed['user']->getEmail()), $this->em->getConnection()->fetchOne('SELECT email FROM app_user WHERE id = ?', [$seed['user']->getId()]));
        $this->assertDuplicate('app_user', 'first_name,last_name,email,active,is_administrator,password_hash,must_change_password,version,assignment_revision', $seed['user']->getId());
        $this->assertDuplicate('leave_account', 'user_id,leave_year,version,movement_revision', $account->getId());
        $this->assertDuplicate('hour_pool', 'user_id,origin_year,version,movement_revision', $pool->getId());
        $this->assertDuplicate('audit_event', 'operation_key,action,actor_id,subject_id,target_type,target_reference,occurred_at,before_data,after_data', $event->getId());
        $this->assertDuplicate('ledger_entry', 'account_id,pool_id,event_id,line_number,kind,allocated_delta,reserved_delta,charged_delta,day_id', $entry->getId());
        $this->assertDuplicate('request_decision', 'revision_id,outcome,event_id', $decision->getId());
        $this->assertDuplicate('request_revision', 'request_id,revision,kind,policy_id,mode,start_date,end_date,source_id,base_revision_id', $seed['revision']->getId());
        $this->assertDuplicate('request_day', 'revision_id,assignment_id,date,hours', $seed['revision']->getDays()[0]->getId());
        $this->assertDuplicate('opening_balance', 'user_id,effective_date,annual_hours,current_year_used_hours,source_id', $opening->getId());
        $this->assertDuplicate('opening_carry', 'opening_balance_id,origin_year,remaining_hours', $opening->getCarries()[0]->getId());
        $this->assertDuplicate('absence_type_version', 'type_id,revision,name,deducts_balance', $seed['policy']->getId());

        $this->assertSqlFails('DELETE FROM app_user WHERE id = ?', [$seed['user']->getId()], 1451);
        $this->assertSqlFails('DELETE FROM absence_type_version WHERE id = ?', [$seed['policy']->getId()], 1451);
        $this->assertSqlFails('INSERT INTO leave_account (user_id,leave_year,version,movement_revision) VALUES (-1,2026,1,0)', [], 1452);
        foreach ([0, 9] as $hours) {
            $this->assertSqlFails('UPDATE request_day SET hours = ? WHERE id = ?', [$hours, $seed['revision']->getDays()[0]->getId()], 3819);
        }
        $this->assertSqlFails('UPDATE daily_occupancy SET hours = 9 WHERE id = ?', [$occupancy->getId()], 3819);
        $this->assertSqlFails('UPDATE staff_assignment SET valid_until = valid_from WHERE id = ?', [$seed['assignment']->getId()], 3819);
        $this->assertSqlFails('UPDATE opening_balance SET current_year_used_hours = -1 WHERE id = ?', [$opening->getId()], 3819);
        $this->assertSqlFails('UPDATE opening_carry SET remaining_hours = -1 WHERE id = ?', [$opening->getCarries()[0]->getId()], 3819);
        $this->assertSqlFails('UPDATE hour_pool SET origin_year = 999 WHERE id = ?', [$pool->getId()], 3819);
        $this->assertSqlFails('UPDATE leave_account SET leave_year = 10000 WHERE id = ?', [$account->getId()], 3819);
        $this->assertSqlFails("UPDATE request_decision SET outcome = 'APPROVED' WHERE id = ?", [$decision->getId()], 3819);
        $this->assertSqlFails("UPDATE staff_assignment SET role = 'EMPLOYEE' WHERE id = ?", [$seed['assignment']->getId()], 3819);
        self::assertSame(8, (int) $this->em->getConnection()->fetchOne('SELECT hours FROM request_day WHERE id = ?', [$seed['revision']->getDays()[0]->getId()]));
    }

    public function testConcurrentAssignmentInsertRollsBackLosingChild(): void
    {
        $seed = $this->seedRequest();
        $seed['assignment']->closeAt($this->date('2027-01-01'));
        $this->em->flush();
        $first = $this->newManager();
        $second = $this->newManager();
        $user1 = $this->findIn($first, User::class, $seed['user']->getId());
        $user2 = $this->findIn($second, User::class, $seed['user']->getId());
        $a = new StaffAssignment($user1, $this->findIn($first, Team::class, $seed['team']->getId()), $this->findIn($first, EmploymentType::class, $seed['employment']->getId()), TeamRole::EMPLOYEE, $this->date('2027-01-01'));
        $b = new StaffAssignment($user2, $this->findIn($second, Team::class, $seed['team']->getId()), $this->findIn($second, EmploymentType::class, $seed['employment']->getId()), TeamRole::EMPLOYEE, $this->date('2027-02-01'));
        $first->persist($a);
        $second->persist($b);
        $first->flush();
        $this->assertOptimisticFailure($second);

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM staff_assignment WHERE user_id = ?', [$seed['user']->getId()]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM staff_assignment WHERE user_id = ? AND valid_from = ?', [$seed['user']->getId(), '2027-02-01']));
    }

    /** @return iterable<string, array{bool}> */
    public static function accountConcurrencyCases(): iterable
    {
        yield 'same leave year' => [true];
        yield 'shared originating pool across leave years' => [false];
    }

    #[DataProvider('accountConcurrencyCases')]
    public function testConcurrentLedgerMovementRollsBackSourceAndEntry(bool $sameAccount): void
    {
        $seed = $this->seedRequest();
        $account1 = new LeaveAccount($seed['user'], 2026);
        $account2 = $sameAccount ? $account1 : new LeaveAccount($seed['user'], 2027);
        $pool = new HourPool($seed['user'], 2025);
        $grant = $this->event($seed['user'], 'carry_grant', $seed['actor']);
        $entry = new LedgerEntry($account1, $pool, $grant, 1, LedgerEntryKind::GRANT, 16, 0, 0);
        $this->persist($account1, $account2, $pool, $grant, $entry);
        $this->em->flush();

        $first = $this->newManager();
        $second = $this->newManager();
        $pool1 = $this->findIn($first, HourPool::class, $pool->getId());
        $pool2 = $this->findIn($second, HourPool::class, $pool->getId());
        $a1 = $this->findIn($first, LeaveAccount::class, $account1->getId());
        $a2 = $this->findIn($second, LeaveAccount::class, $account2->getId());
        $source1 = $this->event($pool1->getUser(), 'adjust_first', $pool1->getUser());
        $source2 = $this->event($pool2->getUser(), 'adjust_second', $pool2->getUser());
        $movement1 = new LedgerEntry($a1, $pool1, $source1, 1, LedgerEntryKind::ADJUSTMENT, -6, 0, 0);
        $movement2 = new LedgerEntry($a2, $pool2, $source2, 1, LedgerEntryKind::ADJUSTMENT, -6, 0, 0);
        $first->persist($source1);
        $first->persist($movement1);
        $second->persist($source2);
        $second->persist($movement2);
        $first->flush();
        $this->assertOptimisticFailure($second);

        self::assertSame(10, (int) $this->em->getConnection()->fetchOne('SELECT SUM(allocated_delta) FROM ledger_entry WHERE pool_id = ?', [$pool->getId()]));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ledger_entry WHERE pool_id = ?', [$pool->getId()]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_event WHERE operation_key = ?', [$source2->getOperationKey()]));
        if (!$sameAccount) {
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT movement_revision FROM leave_account WHERE id = ?', [$account2->getId()]));
        }
    }

    public function testCompetingDecisionsPersistOnlyOneOutcome(): void
    {
        $seed = $this->seedRequest();
        $first = $this->newManager();
        $second = $this->newManager();
        $r1 = $this->findIn($first, RequestRevision::class, $seed['revision']->getId());
        $r2 = $this->findIn($second, RequestRevision::class, $seed['revision']->getId());
        self::assertSame($r1->getRequest()->getVersion(), $r2->getRequest()->getVersion());
        $event1 = $this->event($r1->getRequest()->getUser(), 'decision_first', $this->findIn($first, User::class, $seed['actor']->getId()));
        $event2 = $this->event($r2->getRequest()->getUser(), 'decision_second', $this->findIn($second, User::class, $seed['actor']->getId()));
        $decision1 = new RequestDecision($r1, DecisionOutcome::APPROVED, $event1);
        $decision2 = new RequestDecision($r2, DecisionOutcome::REJECTED, $event2);
        $first->persist($event1);
        $first->persist($decision1);
        $second->persist($event2);
        $second->persist($decision2);
        $first->flush();
        try {
            $second->flush();
            self::fail('A competing terminal decision must fail.');
        } catch (UniqueConstraintViolationException|OptimisticLockException) {
            self::assertFalse($second->isOpen());
        }

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM request_decision WHERE revision_id = ?', [$seed['revision']->getId()]));
        self::assertSame('approved', $this->em->getConnection()->fetchOne('SELECT outcome FROM request_decision WHERE revision_id = ?', [$seed['revision']->getId()]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_event WHERE operation_key = ?', [$event2->getOperationKey()]));
    }

    public function testConcurrentDailyOccupancyUpdatesDoNotLoseTheFirstReservation(): void
    {
        $seed = $this->seedRequest();
        $occupancy = new DailyOccupancy($seed['user'], $this->date('2026-12-15'));
        $this->persist($occupancy);
        $this->em->flush();
        $first = $this->newManager();
        $second = $this->newManager();
        $a = $this->findIn($first, DailyOccupancy::class, $occupancy->getId());
        $b = $this->findIn($second, DailyOccupancy::class, $occupancy->getId());
        $a->changeBy(6);
        $b->changeBy(6);
        $first->flush();
        $this->assertOptimisticFailure($second);
        self::assertSame(6, (int) $this->em->getConnection()->fetchOne('SELECT hours FROM daily_occupancy WHERE id = ?', [$occupancy->getId()]));
    }

    /** @return array{user: User, actor: User, team: Team, employment: EmploymentType, assignment: StaffAssignment, type: AbsenceType, policy: AbsenceTypeVersion, request: LeaveRequest, revision: RequestRevision} */
    private function seedRequest(): array
    {
        $user = new User('Synthetic', 'Employee', strtoupper($this->prefix).'@EXAMPLE.TEST');
        $actor = new User('Synthetic', 'Administrator', $this->prefix.'_admin@example.test', true);
        $team = new Team($this->prefix.'_team', 'Synthetic team');
        $employment = new EmploymentType($this->prefix.'_uop', 'UoP', true);
        $assignment = new StaffAssignment($user, $team, $employment, TeamRole::EMPLOYEE, $this->date('2026-01-01'));
        $type = new AbsenceType($this->prefix.'_paid');
        $policy = $type->createVersion('Paid absence', true, [$employment]);
        $request = new LeaveRequest($user, $this->time());
        $source = $this->event($user, 'submit', $actor);
        $revision = new RequestRevision($request, RequestChangeKind::INITIAL, $policy, RequestMode::FULL_DAYS, $this->date('2026-12-15'), $this->date('2026-12-15'), [new RequestedDay($assignment, $this->date('2026-12-15'), 8)], $source);
        $this->persist($user, $actor, $team, $employment, $assignment, $type, $policy, $request, $source);
        $this->em->flush();

        return compact('user', 'actor', 'team', 'employment', 'assignment', 'type', 'policy', 'request', 'revision');
    }

    /** @param array{user: User, request: LeaveRequest, policy: AbsenceTypeVersion, assignment: StaffAssignment, actor: User} $seed */
    private function revision(array $seed, RequestChangeKind $kind, string $date, string $operation, RequestRevision $base): RequestRevision
    {
        $source = $this->event($seed['user'], $operation, $seed['actor']);
        $revision = new RequestRevision($seed['request'], $kind, $seed['policy'], RequestMode::FULL_DAYS, $this->date($date), $this->date($date), [new RequestedDay($seed['assignment'], $this->date($date), 8)], $source, $base);
        $this->em->persist($source);

        return $revision;
    }

    private function event(User $subject, string $suffix, ?User $actor): AuditEvent
    {
        return new AuditEvent($this->prefix.':'.$suffix, 'test.'.$suffix, $actor, $subject, $this->time());
    }

    private function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Warsaw'));
    }

    private function time(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC'));
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
    }

    private function newManager(): EntityManagerInterface
    {
        $connection = DriverManager::getConnection($this->em->getConnection()->getParams());
        $manager = new EntityManager($connection, $this->em->getConfiguration());
        $this->extraManagers[] = $manager;

        return $manager;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, ?int $id): object
    {
        return $this->findIn($this->em, $class, $id);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function findIn(EntityManagerInterface $manager, string $class, ?int $id): object
    {
        self::assertNotNull($id);
        $entity = $manager->find($class, $id);
        self::assertInstanceOf($class, $entity);

        return $entity;
    }

    private function assertOptimisticFailure(EntityManagerInterface $manager): void
    {
        try {
            $manager->flush();
            self::fail('A stale aggregate update must fail.');
        } catch (OptimisticLockException) {
            self::assertFalse($manager->isOpen());
        }
    }

    private function assertDuplicate(string $table, string $columns, ?int $id): void
    {
        self::assertNotNull($id);
        $this->assertSqlFails(sprintf('INSERT INTO %s (%s) SELECT %s FROM %s WHERE id = ?', $table, $columns, $columns, $table), [$id], 1062);
    }

    /** @param list<int|string|null> $parameters */
    private function assertSqlFails(string $sql, array $parameters, int $mysqlCode): void
    {
        try {
            $this->em->getConnection()->executeStatement($sql, $parameters);
            self::fail('The database accepted an invalid write: '.$sql);
        } catch (DriverException $exception) {
            self::assertSame($mysqlCode, $exception->getCode(), $exception->getMessage());
        }
    }
}
