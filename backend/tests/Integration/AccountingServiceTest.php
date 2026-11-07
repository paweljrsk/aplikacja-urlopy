<?php

namespace App\Tests\Integration;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\EmploymentType;
use App\Entity\HourPool;
use App\Entity\LeaveAccount;
use App\Entity\LeaveRequest;
use App\Entity\LedgerEntry;
use App\Entity\OpeningBalance;
use App\Entity\OpeningCarry;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\LedgerEntryKind;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Enum\TeamRole;
use App\Model\RequestedDay;
use App\Security\BalanceAccessPolicy;
use App\Security\CurrentAssignmentResolver;
use App\Security\RequestAccessPolicy;
use App\Service\Accounting\AccountingService;
use App\Service\CredentialsException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** Real, migrated MySQL; isolated synthetic employees and a controlled Warsaw clock. */
final class AccountingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AccountingService $accounting;
    private MockClock $clock;
    private string $prefix;
    private int $sequence = 0;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->prefix = 'acc_'.bin2hex(random_bytes(6));
        $this->clock = new MockClock('2026-11-07 12:00:00 Europe/Warsaw');
        $this->bootAccounting();
    }

    public function testMissingLimitIsDifferentFromExplicitZero(): void
    {
        $this->seed();
        self::assertFalse($this->balance()['configured']);
        $this->opening(0);
        self::assertTrue($this->balance()['configured']);
        self::assertSame(0, $this->balance()['annualHours']);
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertFalse($this->balance(2027)['configured']);
        $this->annual(2027, 0);
        self::assertTrue($this->balance(2027)['configured']);
    }

    public function testOpeningUsesOnlyRemainingOldHoursAndChargesCurrentUsageOnce(): void
    {
        $this->seed();
        $this->opening(208, 40, [['originYear' => 2024, 'remainingHours' => 5], ['originYear' => 2025, 'remainingHours' => 11]]);
        self::assertSame(208, $this->balance()['annualHours']);
        self::assertSame(16, $this->balance()['carriedHours']);
        self::assertSame(40, $this->balance()['openingUsedHours']);
        self::assertSame(184, $this->balance()['availableHours']);

        $revision = $this->request(['2026-11-09' => 8, '2026-11-10' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $revision, $this->key('reserve'));
        self::assertSame([2024 => 5, 2025 => 11], $this->reservedByOrigin($revision));
        self::assertSame(168, $this->balance()['availableHours']);
        $this->accounting->approveRevision($this->user('admin'), $revision, $this->key('approve'));
        self::assertSame(168, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame(16, $this->balance()['approvedFutureHours']);
        $this->clock->modify('2026-11-20 12:00:00');
        self::assertSame(168, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['approvedFutureHours']);
    }

    public function testOldPendingReservationIsExcludedFromCarryAndReleasedAfterRollover(): void
    {
        $this->seed();
        $this->opening(24);
        $old = $this->request(['2026-12-31' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $old, $this->key('reserve'));
        $this->annual(2027, 80);
        self::assertSame(80, $this->balance(2027)['availableHours'], 'No forecast carry in November.');
        $this->clock->modify('2027-01-02 12:00:00');
        $this->settle(2027);
        self::assertSame(96, $this->balance(2027)['availableHours']);
        $version = $this->balance(2027)['version'];
        $this->accounting->releaseRevision($this->user('admin'), $old, $this->key('release'));
        self::assertSame(104, $this->balance(2027)['availableHours']);
        self::assertNotSame($version, $this->balance(2027)['version'], 'A different leave year changed the shared pool.');
        self::assertSame(24, $this->balance(2027)['carriedHours']);
    }

    public function testB2BReleaseOfOldReservationDoesNotCreateNewYearCarry(): void
    {
        $this->seed(false);
        $this->opening(24);
        $old = $this->request(['2026-12-31' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $old, $this->key('reserve'));
        $this->annual(2027, 80);
        $this->clock->modify('2027-01-02 12:00:00');
        $this->settle(2027);
        self::assertSame(80, $this->balance(2027)['availableHours']);
        $this->accounting->releaseRevision($this->user('admin'), $old, $this->key('release'));
        self::assertSame(80, $this->balance(2027)['availableHours']);
        self::assertSame(0, $this->balance(2027)['carriedHours']);
    }

    public function testOldB2BPoolCannotReappearAfterLaterChangeToUop(): void
    {
        $this->seed(false);
        $this->opening(80);
        $this->entity(StaffAssignment::class, $this->ids['assignment'])->closeAt(self::date('2028-01-01'));
        $uop = new EmploymentType($this->prefix.'_new_uop', 'Synthetic later UoP', true);
        $assignment = new StaffAssignment($this->user(), $this->entity(Team::class, $this->ids['team']), $uop, TeamRole::EMPLOYEE, self::date('2028-01-01'));
        $type = new AbsenceType($this->prefix.'_later_paid');
        $policy = $type->createVersion('Synthetic later paid leave', true, [$uop]);
        $this->persist($uop, $assignment, $type, $policy);
        $this->em->flush();
        self::assertNotNull($assignment->getId());
        self::assertNotNull($policy->getId());
        $this->ids['assignment'] = $assignment->getId();
        $this->ids['policy'] = $policy->getId();
        $this->clock->modify('2028-01-02 12:00:00');
        $this->annual(2028, 8);
        $this->settle(2028);
        self::assertSame(8, $this->balance(2028)['availableHours']);
        self::assertSame(0, $this->balance(2028)['carriedHours']);

        $request = $this->request(['2028-01-03' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $request, $this->key('new-uop-reserve'));
        self::assertSame([2028 => 8], $this->reservedByOrigin($request));
        $extra = $this->request(['2028-01-04' => 1]);
        $this->fails(fn () => $this->accounting->reserveRevision($this->user('admin'), $extra, $this->key('extra-uop-reserve')), 422);
        self::assertSame(0, $this->balance(2028)['availableHours']);
        self::assertSame(80, (int) $this->em->getConnection()->fetchOne('SELECT SUM(e.allocated_delta) FROM ledger_entry e JOIN hour_pool p ON p.id=e.pool_id WHERE p.user_id=? AND p.origin_year=2026', [$this->ids['employee']]), 'Expired B2B history remains recorded without becoming spendable again.');
    }

    public function testImportedCarryWithoutEarlierEmploymentHistoryRemainsEligibleAfterCorrection(): void
    {
        $this->seed(); // The first known assignment starts in 2025; the imported origin is 2024.
        $this->opening(0, 0, [['originYear' => 2024, 'remainingHours' => 8]]);
        self::assertSame(8, $this->balance()['availableHours']);
        $request = $this->request(['2026-11-09' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $request, $this->key('imported-reserve'));
        self::assertSame([2024 => 8], $this->reservedByOrigin($request));
        $this->accounting->correctOpening($this->user('admin'), $this->user(), [
            'operationKey' => $this->key('remove-imported'), 'expectedVersion' => $this->balance()['version'],
            'currentYearUsedHours' => 0, 'carries' => [], 'reason' => 'Synthetic correction of an overstated imported pool',
        ]);
        self::assertSame(-8, $this->balance()['availableHours']);
        self::assertSame(8, $this->balance()['deficitHours']);
        self::assertSame([2024 => 8], $this->reservedByOrigin($request), 'The existing commitment survives the explicit correction.');
    }

    public function testUopOriginPoolRemainsAvailableWhenNextUopYearStarts(): void
    {
        $this->seed();
        $this->opening(16);
        $this->annual(2027, 80);
        self::assertSame(80, $this->balance(2027)['availableHours']);
        $this->clock->modify('2027-01-02 12:00:00');
        $this->settle(2027);
        self::assertSame(96, $this->balance(2027)['availableHours']);
        $request = $this->request(['2027-01-04' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $request, $this->key('carried-reserve'));
        self::assertSame([2026 => 8], $this->reservedByOrigin($request));
        self::assertSame(88, $this->balance(2027)['availableHours']);
    }

    public function testDecemberJanuaryRequestReservesEachAnnualLimitAndApprovesWithoutSecondCharge(): void
    {
        $this->seed();
        $this->opening(16);
        $this->annual(2027, 24);
        $revision = $this->request(['2026-12-31' => 8, '2027-01-04' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $revision, $this->key('reserve'));
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(16, $this->balance(2027)['availableHours']);
        self::assertSame([2026 => 8, 2027 => 8], $this->reservedByOrigin($revision));
        $this->accounting->approveRevision($this->user('admin'), $revision, $this->key('approve'));
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(16, $this->balance(2027)['availableHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame(0, $this->balance(2027)['reservedHours']);
    }

    public function testMissingJanuaryLimitRollsBackWholeCrossYearReservation(): void
    {
        $this->seed();
        $this->opening(80);
        $revision = $this->request(['2026-12-31' => 8, '2027-01-04' => 8]);
        $revisionId = $revision->getId();
        $operation = $this->key('reserve');
        $before = $this->ledgerCount();
        $this->fails(fn () => $this->accounting->reserveRevision($this->user('admin'), $revision, $operation), 422);
        self::assertSame($before, $this->ledgerCount());
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_event WHERE operation_key = ?', [$operation]));
        self::assertSame([], $this->reservedByOrigin($this->entity(RequestRevision::class, $revisionId)));
    }

    public function testSharedOldPoolCannotBeSpentTwiceAcrossLeaveYears(): void
    {
        $this->seed();
        $this->opening(8);
        $this->annual(2027, 0);
        $this->clock->modify('2027-01-02 12:00:00');
        $this->settle(2027);
        $new = $this->request(['2027-01-04' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $new, $this->key('reserve-new'));
        self::assertSame([2026 => 8], $this->reservedByOrigin($new));
        self::assertSame(0, $this->balance(2027)['availableHours']);
        $old = $this->request(['2026-12-31' => 8]);
        $this->fails(fn () => $this->accounting->reserveRevision($this->user('admin'), $old, $this->key('reserve-old')), 422);
        self::assertSame(8, (int) $this->em->getConnection()->fetchOne('SELECT SUM(e.reserved_delta) FROM ledger_entry e JOIN hour_pool p ON p.id = e.pool_id WHERE p.user_id = ? AND p.origin_year = 2026', [$this->ids['employee']]));
    }

    public function testCorrectionMayCreateDeficitAndExistingReservationCanStillBeApproved(): void
    {
        $this->seed();
        $this->opening(16);
        $revision = $this->request(['2026-11-09' => 8, '2026-11-10' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $revision, $this->key('reserve'));
        $this->annual(2026, 8);
        self::assertSame(-8, $this->balance()['availableHours']);
        self::assertSame(8, $this->balance()['deficitHours']);
        $this->accounting->approveRevision($this->user('admin'), $revision, $this->key('approve'));
        self::assertSame(-8, $this->balance()['availableHours']);
        self::assertSame(16, $this->balance()['approvedFutureHours']);
        $extra = $this->request(['2026-11-12' => 1]);
        $this->fails(fn () => $this->accounting->reserveRevision($this->user('admin'), $extra, $this->key('extra')), 422);
        self::assertSame(-8, $this->balance()['availableHours']);
    }

    public function testNegativeOlderPoolCannotBeIgnoredWhileSpendingPositiveNewPool(): void
    {
        $this->seed();
        $this->opening(16, 16);
        $this->annual(2026, 8);
        $this->annual(2027, 8);
        $this->clock->modify('2027-01-02 12:00:00');
        $this->settle(2027);
        self::assertSame(0, $this->balance(2027)['availableHours']);
        $request = $this->request(['2027-01-04' => 1]);
        $this->fails(fn () => $this->accounting->reserveRevision($this->user('admin'), $request, $this->key('reserve')), 422);
    }

    public function testOpeningCorrectionIsAuditedAndDoesNotRewriteOriginalSnapshot(): void
    {
        $this->seed();
        $this->opening(80, 40, [['originYear' => 2025, 'remainingHours' => 16]]);
        $before = $this->balance();
        $this->accounting->correctOpening($this->user('admin'), $this->user(), [
            'operationKey' => $this->key('correct'), 'expectedVersion' => $before['version'],
            'currentYearUsedHours' => 48, 'carries' => [['originYear' => 2025, 'remainingHours' => 8]],
            'reason' => 'Synthetic reconciliation of initial records',
        ]);
        self::assertSame(40, $this->balance()['availableHours']);
        self::assertSame(48, $this->balance()['openingUsedHours']);
        $opening = $this->em->getRepository(OpeningBalance::class)->findOneBy(['user' => $this->user()]);
        self::assertInstanceOf(OpeningBalance::class, $opening);
        self::assertSame(40, $opening->getCurrentYearUsedHours());
        self::assertSame(16, $opening->getCarries()[0]->getRemainingHours());
        self::assertGreaterThan(0, $this->em->getRepository(AuditEvent::class)->count(['subject' => $this->user()]));
    }

    public function testOpeningBoundaryCannotMoveAfterRequestWasAccounted(): void
    {
        $this->seed();
        $this->opening(80, 0, [], '2026-11-01');
        $request = $this->request(['2026-11-09' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $request, $this->key('reserve'));
        $this->fails(fn () => $this->accounting->correctOpening($this->user('admin'), $this->user(), [
            'operationKey' => $this->key('move-boundary'), 'expectedVersion' => $this->balance()['version'],
            'effectiveDate' => '2026-11-02', 'currentYearUsedHours' => 0, 'carries' => [],
            'reason' => 'Synthetic invalid boundary move',
        ]), 409);
        self::assertSame(72, $this->balance()['availableHours']);
        $opening = $this->em->getRepository(OpeningBalance::class)->findOneBy(['user' => $this->user()]);
        self::assertInstanceOf(OpeningBalance::class, $opening);
        self::assertSame('2026-11-01', $opening->getEffectiveDate()->format('Y-m-d'));
    }

    public function testApprovalRetainsCapturedEmploymentAfterFutureContractChange(): void
    {
        $this->seed();
        $this->opening(8);
        $this->annual(2027, 0);
        $this->clock->modify('2027-01-02 12:00:00');
        $this->settle(2027);
        $request = $this->request(['2027-01-20' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $request, $this->key('reserve'));
        self::assertSame([2026 => 8], $this->reservedByOrigin($request));

        $assignment = $this->entity(StaffAssignment::class, $this->ids['assignment']);
        $assignment->closeAt(self::date('2027-01-10'));
        $b2b = new EmploymentType($this->prefix.'_b2b', 'Synthetic new B2B', false);
        $replacement = new StaffAssignment($this->user(), $this->entity(Team::class, $this->ids['team']), $b2b, TeamRole::EMPLOYEE, self::date('2027-01-10'));
        $this->persist($b2b, $replacement);
        $this->em->flush();
        $this->clock->modify('2027-01-11 12:00:00');
        $this->accounting->approveRevision($this->user('admin'), $request, $this->key('approve'));
        self::assertSame($this->ids['assignment'], $request->getDays()[0]->getAssignment()->getId());
        self::assertTrue($request->getDays()[0]->getAssignment()->getEmploymentType()->carriesUnusedHours());
        self::assertSame(8, (int) $this->em->getConnection()->fetchOne('SELECT SUM(e.charged_delta) FROM ledger_entry e JOIN hour_pool p ON p.id=e.pool_id JOIN request_day d ON d.id=e.day_id WHERE d.revision_id=? AND p.origin_year=2026', [$request->getId()]));
    }

    public function testPreOpeningRequestCannotDoubleChargeAggregateUsage(): void
    {
        $this->seed();
        $this->opening(80, 40, [], '2026-11-01');
        $request = $this->request(['2026-10-30' => 8]);
        $this->fails(fn () => $this->accounting->reserveRevision($this->user('admin'), $request, $this->key('reserve')), 422);
        self::assertSame(40, $this->balance()['availableHours']);
    }

    public function testRepeatedOpeningAndAnnualOperationsDoNotDuplicateHours(): void
    {
        $this->seed();
        $data = $this->openingData(80);
        $this->accounting->initializeOpening($this->user('admin'), $this->user(), $data);
        $before = $this->ledgerCount();
        $this->accounting->initializeOpening($this->user('admin'), $this->user(), $data);
        self::assertSame($before, $this->ledgerCount());
        self::assertSame(80, $this->balance()['availableHours']);
        $annual = ['operationKey' => $this->key('annual'), 'expectedVersion' => $this->balance(2027)['version'],
            'annualHours' => 88, 'reason' => 'Synthetic future allocation'];
        $this->accounting->setAnnualLimit($this->user('admin'), $this->user(), 2027, $annual);
        $before = $this->ledgerCount();
        $this->accounting->setAnnualLimit($this->user('admin'), $this->user(), 2027, $annual);
        self::assertSame($before, $this->ledgerCount());
        self::assertSame(88, $this->balance(2027)['availableHours']);
        $data['annualHours'] = 88;
        $this->fails(fn () => $this->accounting->initializeOpening($this->user('admin'), $this->user(), $data), 409);
        self::assertSame(80, $this->balance()['availableHours']);
    }

    public function testRepeatedReservationAndApprovalDoNotDuplicateChargeOrOccupancy(): void
    {
        $this->seed();
        $this->opening(80);
        $request = $this->request(['2026-11-09' => 8]);
        $reserve = $this->key('reserve');
        $approve = $this->key('approve');
        $this->accounting->reserveRevision($this->user('admin'), $request, $reserve);
        $before = $this->ledgerCount();
        $this->accounting->reserveRevision($this->user('admin'), $request, $reserve);
        self::assertSame($before, $this->ledgerCount());
        $this->accounting->approveRevision($this->user('admin'), $request, $approve);
        $before = $this->ledgerCount();
        $this->accounting->approveRevision($this->user('admin'), $request, $approve);
        self::assertSame($before, $this->ledgerCount());
        self::assertSame(72, $this->balance()['availableHours']);
        self::assertSame(8, (int) $this->em->getConnection()->fetchOne('SELECT hours FROM daily_occupancy WHERE user_id=? AND date=?', [$this->ids['employee'], '2026-11-09']));
    }

    public function testStaleBalanceCannotOverwriteConcurrentAnnualChange(): void
    {
        $this->seed();
        $this->opening(80);
        $version = $this->balance()['version'];
        $this->annual(2026, 88);
        $this->fails(fn () => $this->accounting->setAnnualLimit($this->user('admin'), $this->user(), 2026, [
            'operationKey' => $this->key('stale'), 'expectedVersion' => $version, 'annualHours' => 96,
            'reason' => 'Synthetic stale form',
        ]), 409);
        self::assertSame(88, $this->balance()['annualHours']);
    }

    public function testCompetingDatabaseMutexPreventsAccountingWriteAndLeavesNoPartialHistory(): void
    {
        $this->seed();
        $this->opening(80);
        $operation = $this->key('locked');
        $data = ['operationKey' => $operation, 'expectedVersion' => $this->balance()['version'],
            'annualHours' => 88, 'reason' => 'Synthetic competing transaction'];
        $connection = $this->em->getConnection();
        $timeout = (int) $connection->fetchOne('SELECT @@SESSION.innodb_lock_wait_timeout');
        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $blocker = DriverManager::getConnection($connection->getParams());
        $blocker->beginTransaction();
        try {
            self::assertSame(1, (int) $blocker->fetchOne('SELECT id FROM administration_lock WHERE id = 1 FOR UPDATE'));
            try {
                $this->accounting->setAnnualLimit($this->user('admin'), $this->user(), 2026, $data);
                self::fail('A competing write bypassed the shared database mutex.');
            } catch (RetryableException) {
                self::assertFalse($this->em->isOpen(), 'Doctrine discards the rolled-back unit of work.');
            }
        } finally {
            if ($blocker->isTransactionActive()) {
                $blocker->rollBack();
            }
            $blocker->close();
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
        }
        self::ensureKernelShutdown();
        $this->bootAccounting();
        self::assertSame(80, $this->balance()['annualHours']);
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_event WHERE operation_key=?', [$operation]));
    }

    public function testStalePendingSnapshotCannotUndoAConcurrentApproval(): void
    {
        $this->seed();
        $this->opening(80);
        $request = $this->request(['2026-11-09' => 8]);
        $this->accounting->reserveRevision($this->user('admin'), $request, $this->key('reserve'));
        $otherConnection = DriverManager::getConnection($this->em->getConnection()->getParams());
        $staleManager = new EntityManager($otherConnection, $this->em->getConfiguration());
        try {
            $staleRequest = $staleManager->find(LeaveRequest::class, $request->getRequest()->getId());
            $staleAdmin = $staleManager->find(User::class, $this->ids['admin']);
            self::assertInstanceOf(LeaveRequest::class, $staleRequest);
            self::assertInstanceOf(User::class, $staleAdmin);
            $staleRevision = $staleRequest->getPendingRevision();
            self::assertInstanceOf(RequestRevision::class, $staleRevision);
            self::assertNull($staleRevision->getDecision());
            self::assertNull($staleRequest->getEffectiveRevision());
            $assignments = new CurrentAssignmentResolver();
            $staleService = new AccountingService($staleManager, $this->clock, $assignments, new BalanceAccessPolicy($assignments), new RequestAccessPolicy($assignments));

            // A separate transaction commits after the first worker hydrated the old pending graph.
            $this->accounting->approveRevision($this->user('admin'), $request, $this->key('approve'));
            $before = $this->ledgerCount();
            try {
                $staleService->reserveRevision($staleAdmin, $staleRevision, $this->key('stale-reserve'));
                self::fail('The stale pending snapshot rewrote the already approved accounting.');
            } catch (CredentialsException $exception) {
                self::assertSame(409, $exception->httpStatus);
            }
            self::assertSame($before, $this->ledgerCount(), 'The rejected stale operation leaves no ledger rows.');
            self::assertSame(0, $this->balance()['reservedHours']);
            self::assertSame(8, $this->balance()['approvedFutureHours']);
            self::assertSame(72, $this->balance()['availableHours']);
        } finally {
            while ($otherConnection->isTransactionActive()) {
                $otherConnection->rollBack();
            }
            $staleManager->close();
            $otherConnection->close();
        }
    }

    public function testReplayingPriorOperationDoesNotBypassAccessAfterTeamTransfer(): void
    {
        $this->seed();
        $this->opening(80);
        $manager = new User('Synthetic', 'Manager', $this->prefix.'_manager@example.test');
        new StaffAssignment($manager, $this->entity(Team::class, $this->ids['team']), $this->entity(EmploymentType::class, $this->ids['employment']), TeamRole::MANAGER, self::date('2025-01-01'));
        $this->persist($manager);
        $this->em->flush();
        self::assertNotNull($manager->getId());
        $this->ids['manager'] = $manager->getId();
        $request = $this->request(['2026-11-20' => 8]);
        $key = $this->key('manager-reserve');
        $receipt = $this->accounting->reserveRevision($manager, $request, $key);
        self::assertArrayNotHasKey('availableHours', $receipt, 'Workflow authorization must not grant access to a current balance.');
        self::assertArrayNotHasKey('annualHours', $receipt);

        $this->entity(StaffAssignment::class, $this->ids['assignment'])->closeAt(self::date('2026-11-10'));
        $otherTeam = new Team($this->prefix.'_other', 'Synthetic transferred team');
        $assignment = new StaffAssignment($this->user(), $otherTeam, $this->entity(EmploymentType::class, $this->ids['employment']), TeamRole::EMPLOYEE, self::date('2026-11-10'));
        $this->persist($otherTeam, $assignment);
        $this->em->flush();
        $this->clock->modify('2026-11-11 12:00:00');
        $before = $this->ledgerCount();
        $this->fails(fn () => $this->accounting->reserveRevision($this->user('manager'), $request, $key), 403);
        self::assertSame($before, $this->ledgerCount());
        self::assertSame(72, $this->balance()['availableHours']);
    }

    public function testRepeatedSettlementDoesNotCreateAnotherCopyOfCarry(): void
    {
        $this->seed();
        $this->opening(16);
        $this->annual(2027, 80);
        $this->clock->modify('2027-01-02 12:00:00');
        $this->settle(2027);
        $before = $this->ledgerCount();
        $this->settle(2027);
        self::assertSame($before, $this->ledgerCount());
        self::assertSame(96, $this->balance(2027)['availableHours']);
        self::assertSame(96, (int) $this->em->getConnection()->fetchOne('SELECT SUM(e.allocated_delta) FROM ledger_entry e JOIN hour_pool p ON p.id = e.pool_id WHERE p.user_id = ?', [$this->ids['employee']]));
    }

    public function testSettlementCannotExposeForecastCarryBeforeNewYear(): void
    {
        $this->seed();
        $this->opening(16);
        $this->annual(2027, 80);
        $this->fails(fn () => $this->settle(2027), 422);
        self::assertSame(80, $this->balance(2027)['availableHours']);
        self::assertSame(0, $this->balance(2027)['carriedHours']);
    }

    #[DataProvider('invalidWholeHours')]
    public function testAnnualLimitRejectsCoercibleAndFractionalInputs(mixed $hours): void
    {
        $this->seed();
        $this->opening(80);
        $this->fails(fn () => $this->accounting->setAnnualLimit($this->user('admin'), $this->user(), 2026, [
            'operationKey' => $this->key('invalid'), 'expectedVersion' => $this->balance()['version'],
            'annualHours' => $hours, 'reason' => 'Synthetic invalid value',
        ]), 422);
        self::assertSame(80, $this->balance()['annualHours']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidWholeHours(): iterable
    {
        yield 'string number' => ['208'];
        yield 'fraction' => [2.5];
        yield 'integral float' => [208.0];
        yield 'boolean' => [true];
        yield 'negative' => [-1];
        yield 'overflow' => [2147483648];
    }

    public function testLegacyOpeningAndLedgerRemainUsableWithoutReimport(): void
    {
        $this->seed();
        $user = $this->user();
        $event = new AuditEvent($this->key('legacy'), 'test.legacy', $this->user('admin'), $user, $this->clock->now());
        $account = new LeaveAccount($user, 2026);
        // The stage 5 migration backfills this projection for historical opening/ledger rows.
        $account->setAnnualLimitHours(208);
        $current = new HourPool($user, 2026);
        $old = new HourPool($user, 2025);
        $opening = new OpeningBalance($user, self::date('2026-11-01'), 208, 40, $event);
        new OpeningCarry($opening, 2025, 16);
        $this->persist($event, $account, $current, $old, $opening,
            new LedgerEntry($account, $current, $event, 1, LedgerEntryKind::GRANT, 208, 0, 0),
            new LedgerEntry($account, $current, $event, 2, LedgerEntryKind::OPENING_USED, 0, 0, 40),
            new LedgerEntry($account, $old, $event, 3, LedgerEntryKind::GRANT, 16, 0, 0));
        $this->em->flush();
        self::assertTrue($this->balance()['configured']);
        self::assertSame(184, $this->balance()['availableHours']);
        $this->annual(2026, 216);
        self::assertSame(192, $this->balance()['availableHours']);
        self::assertSame(1, $this->em->getRepository(OpeningBalance::class)->count(['user' => $user]));
    }

    private function bootAccounting(): void
    {
        self::bootKernel();
        self::assertSame('test', self::$kernel?->getEnvironment());
        self::getContainer()->set(ClockInterface::class, $this->clock);
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->em = $manager;
        $database = $manager->getConnection()->getDatabase();
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', $database);
        self::assertStringContainsString('mysql', $manager->getConnection()->getParams()['driver'] ?? '');
        $service = self::getContainer()->get(AccountingService::class);
        self::assertInstanceOf(AccountingService::class, $service);
        $this->accounting = $service;
    }

    private function seed(bool $carries = true): void
    {
        $team = new Team($this->prefix.'_team', 'Synthetic accounting team');
        $employment = new EmploymentType($this->prefix.'_employment', 'Synthetic employment', $carries);
        $admin = new User('Synthetic', 'Administrator', $this->prefix.'_admin@example.test', true);
        $user = new User('Synthetic', 'Employee', $this->prefix.'_employee@example.test');
        $assignment = new StaffAssignment($user, $team, $employment, TeamRole::EMPLOYEE, self::date('2025-01-01'));
        $type = new AbsenceType($this->prefix.'_paid');
        $policy = $type->createVersion('Synthetic paid leave', true, [$employment]);
        $this->persist($team, $employment, $admin, $user, $assignment, $type, $policy);
        $this->em->flush();
        foreach (['employee' => $user, 'admin' => $admin, 'team' => $team, 'employment' => $employment, 'assignment' => $assignment, 'policy' => $policy] as $name => $entity) {
            self::assertNotNull($entity->getId());
            $this->ids[$name] = $entity->getId();
        }
    }

    /** @param list<array{originYear:int,remainingHours:int}> $carries */
    private function opening(int $annual, int $used = 0, array $carries = [], string $date = '2026-01-01'): void
    {
        $this->accounting->initializeOpening($this->user('admin'), $this->user(), $this->openingData($annual, $used, $carries, $date));
    }

    /** @param list<array{originYear:int,remainingHours:int}> $carries
     * @return array<string,mixed>
     */
    private function openingData(int $annual, int $used = 0, array $carries = [], string $date = '2026-01-01'): array
    {
        return ['operationKey' => $this->key('opening'), 'expectedVersion' => $this->balance()['version'], 'effectiveDate' => $date,
            'annualHours' => $annual, 'currentYearUsedHours' => $used, 'carries' => $carries];
    }

    private function annual(int $year, int $hours): void
    {
        $this->accounting->setAnnualLimit($this->user('admin'), $this->user(), $year, [
            'operationKey' => $this->key('annual'), 'expectedVersion' => $this->balance($year)['version'],
            'annualHours' => $hours, 'reason' => 'Synthetic manual annual allocation',
        ]);
    }

    private function settle(int $year): void
    {
        $this->accounting->settleYear($this->user('admin'), $this->user(), $year, [
            'operationKey' => $this->key('settle'), 'expectedVersion' => $this->balance($year)['version'],
        ]);
    }

    /** @return array<string,mixed>
     * @phpstan-impure
     */
    private function balance(int $year = 2026): array
    {
        return $this->accounting->getBalance($this->user(), $year);
    }

    /** @param array<string,int> $days */
    private function request(array $days): RequestRevision
    {
        $dates = array_keys($days);
        sort($dates);
        $request = new LeaveRequest($this->user(), $this->clock->now());
        $event = new AuditEvent($this->key('request'), 'test.request', $this->user('admin'), $this->user(), $this->clock->now());
        $requested = [];
        foreach ($days as $day => $hours) {
            $requested[] = new RequestedDay($this->entity(StaffAssignment::class, $this->ids['assignment']), self::date($day), $hours);
        }
        $mode = 1 === count($days) && reset($days) < 8 ? RequestMode::HOURLY : RequestMode::FULL_DAYS;
        $revision = new RequestRevision($request, RequestChangeKind::INITIAL, $this->entity(AbsenceTypeVersion::class, $this->ids['policy']),
            $mode, self::date($dates[0]), self::date($dates[count($dates) - 1]), $requested, $event);
        $this->persist($event, $request);
        $this->em->flush();
        return $revision;
    }

    /** @return array<int,int> */
    private function reservedByOrigin(RequestRevision $revision): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT p.origin_year, SUM(e.reserved_delta) AS hours FROM ledger_entry e JOIN hour_pool p ON p.id=e.pool_id JOIN request_day d ON d.id=e.day_id WHERE d.revision_id=? GROUP BY p.origin_year HAVING SUM(e.reserved_delta) <> 0 ORDER BY p.origin_year', [$revision->getId()]);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['origin_year']] = (int) $row['hours'];
        }
        return $result;
    }

    private function ledgerCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ledger_entry e JOIN hour_pool p ON p.id=e.pool_id WHERE p.user_id=?', [$this->ids['employee']]);
    }

    private function user(string $name = 'employee'): User
    {
        return $this->entity(User::class, $this->ids[$name]);
    }

    /** @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function entity(string $class, ?int $id): object
    {
        self::assertNotNull($id);
        $entity = $this->em->find($class, $id);
        self::assertInstanceOf($class, $entity);
        return $entity;
    }

    /** @param callable():mixed $operation */
    private function fails(callable $operation, int $status): void
    {
        try {
            $operation();
            self::fail('The invalid accounting operation was accepted.');
        } catch (CredentialsException $exception) {
            self::assertSame($status, $exception->httpStatus, $exception->getMessage());
        }
        // A rolled-back Doctrine wrapInTransaction intentionally closes its manager.
        self::ensureKernelShutdown();
        $this->bootAccounting();
    }

    private function key(string $operation): string
    {
        return $this->prefix.':'.$operation.':'.++$this->sequence;
    }

    private static function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Warsaw'));
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
    }
}
