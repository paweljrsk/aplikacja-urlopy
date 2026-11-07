<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\EmploymentType;
use App\Entity\LeaveRequest;
use App\Entity\RequestDecision;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DecisionOutcome;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Enum\TeamRole;
use App\Model\RequestedDay;
use App\Service\Accounting\AccountingService;
use App\Service\CredentialsException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** Exact pool provenance and competing alternative obligations on a real MySQL ledger. */
final class RequestChangeAccountingTest extends KernelTestCase
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
        $this->prefix = 'change_acc_'.bin2hex(random_bytes(5));
        $this->clock = new MockClock('2026-11-07 12:00:00 Europe/Warsaw');
        $this->bootAccounting();
        $team = new Team($this->prefix, 'Synthetic change accounting team');
        $uop = new EmploymentType($this->prefix.'_uop', 'Synthetic UoP', true);
        $b2b = new EmploymentType($this->prefix.'_b2b', 'Synthetic B2B', false);
        $employee = new User('Synthetic', 'Employee', $this->prefix.'@example.test');
        $admin = new User('Synthetic', 'Administrator', $this->prefix.'_admin@example.test', true);
        $assignment = new StaffAssignment($employee, $team, $uop, TeamRole::EMPLOYEE, self::date('2025-01-01'));
        $type = new AbsenceType($this->prefix.'_paid');
        $paid = $type->createVersion('Synthetic paid', true, [$uop, $b2b]);
        $other = new AbsenceType($this->prefix.'_unpaid');
        $unpaid = $other->createVersion('Synthetic unpaid', false, [$uop, $b2b]);
        foreach ([$team, $uop, $b2b, $employee, $admin, $assignment, $type, $paid, $other, $unpaid] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        foreach (compact('team', 'uop', 'b2b', 'employee', 'admin', 'assignment', 'paid', 'unpaid') as $name => $entity) {
            self::assertNotNull($entity->getId());
            $this->ids[$name] = $entity->getId();
        }
    }

    public function testShiftedGrowthReservesOnlyEightAndRejectionRetainsExactApprovedOrigins(): void
    {
        $this->opening(64, [['originYear' => 2025, 'remainingHours' => 16]]);
        $base = $this->initial(['2026-11-09' => 8, '2026-11-10' => 8]);
        $this->decide($base, DecisionOutcome::APPROVED);
        self::assertSame([2025 => ['reserved' => 0, 'charged' => 16]], $this->funding($base));
        $change = $this->change($base, ['2026-11-16' => 8, '2026-11-17' => 8, '2026-11-18' => 8]);
        self::assertSame(56, $this->balance()['availableHours']);
        self::assertSame(8, $this->balance()['changeReservedHours']);
        self::assertSame(16, $this->balance()['approvedFutureHours']);
        self::assertSame([2025 => ['reserved' => 0, 'charged' => 16]], $this->funding($base));
        self::assertSame([2026 => ['reserved' => 8, 'charged' => 0]], $this->funding($change));
        self::assertSame(40, $this->occupancy());
        $this->decide($change, DecisionOutcome::REJECTED);
        self::assertSame(64, $this->balance()['availableHours']);
        self::assertSame(16, $this->occupancy());
        self::assertSame([], $this->funding($change));
        self::assertSame([2025 => ['reserved' => 0, 'charged' => 16]], $this->funding($base));
    }

    public function testShrinkingRefundsOriginalPoolsOnlyAfterApprovalAndCancellationRefundsOnce(): void
    {
        $this->opening(8, [['originYear' => 2025, 'remainingHours' => 8]]);
        $base = $this->initial(['2026-11-09' => 8, '2026-11-10' => 8]);
        $this->decide($base, DecisionOutcome::APPROVED);
        $change = $this->change($base, ['2026-11-16' => 8]);
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        $this->decide($change, DecisionOutcome::APPROVED);
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame([2025 => ['reserved' => 0, 'charged' => 8]], $this->funding($change));
        $cancel = $this->change($change, ['2026-11-16' => 8], kind: RequestChangeKind::CANCELLATION);
        self::assertSame(8, $this->balance()['availableHours']);
        $key = $this->key();
        $this->accounting->approveRevision($this->user('admin'), $cancel, $key);
        self::assertSame(16, $this->balance()['availableHours']);
        $this->accounting->approveRevision($this->user('admin'), $cancel, $key);
        self::assertSame(16, $this->balance()['availableHours']);
        self::assertSame(0, $this->occupancy());
        self::assertSame([], $this->funding($base));
        self::assertSame([], $this->funding($change));
    }

    public function testSameYearMoveHoldsBothDaysWithoutSecondBudgetAndSameDayUsesMaximum(): void
    {
        $this->opening(8);
        $base = $this->initial(['2026-11-09' => 3]);
        $this->decide($base, DecisionOutcome::APPROVED);
        $change = $this->change($base, ['2026-11-09' => 5]);
        self::assertSame(5, $this->occupancy());
        self::assertSame(2, $this->balance()['changeReservedHours']);
        $this->decide($change, DecisionOutcome::APPROVED);
        $move = $this->change($change, ['2026-11-10' => 5]);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        self::assertSame(3, $this->balance()['availableHours']);
        self::assertSame(10, $this->occupancy());
        $this->decide($move, DecisionOutcome::APPROVED);
        self::assertSame(5, $this->occupancy());
        self::assertSame(3, $this->balance()['availableHours']);
    }

    public function testCrossYearMoveRequiresAdditionalNewYearFundingEvenWithEqualHours(): void
    {
        $this->opening(8);
        $this->annual(2027, 8);
        $base = $this->initial(['2026-12-31' => 8]);
        $this->decide($base, DecisionOutcome::APPROVED);
        $change = $this->change($base, ['2027-01-04' => 8]);
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance(2027)['availableHours']);
        self::assertSame([2027 => ['reserved' => 8, 'charged' => 0]], $this->funding($change));
        $this->decide($change, DecisionOutcome::APPROVED);
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance(2027)['availableHours']);
    }

    public function testPendingProposalCannotRefinanceStillEffectiveCurrentFundingIntoNewlyImportedCarry(): void
    {
        $this->opening(8);
        $base = $this->initial(['2026-11-09' => 8]);
        $this->decide($base, DecisionOutcome::APPROVED);
        $this->accounting->correctOpening($this->user('admin'), $this->user(), [
            'operationKey' => $this->key(), 'expectedVersion' => $this->balance()['version'], 'currentYearUsedHours' => 0,
            'carries' => [['originYear' => 2025, 'remainingHours' => 8]], 'reason' => 'Synthetic recovered opening record',
        ]);
        $change = $this->change($base, ['2026-11-10' => 8]);
        self::assertSame([2026 => ['reserved' => 0, 'charged' => 8]], $this->funding($base));
        self::assertSame([], $this->funding($change));
        $this->decide($change, DecisionOutcome::APPROVED);
        self::assertSame([2026 => ['reserved' => 0, 'charged' => 8]], $this->funding($change));
        self::assertSame(8, $this->balance()['carriedHours']);
    }

    public function testChangingToUnpaidReturnsOnlyAfterApprovalAndOppositeChangeReservesFullAmount(): void
    {
        $this->opening(8);
        $base = $this->initial(['2026-11-09' => 8]);
        $this->decide($base, DecisionOutcome::APPROVED);
        $unpaid = $this->change($base, ['2026-11-10' => 8], 'unpaid');
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(16, $this->occupancy());
        $this->decide($unpaid, DecisionOutcome::APPROVED);
        self::assertSame(8, $this->balance()['availableHours']);
        $paid = $this->change($unpaid, ['2026-11-12' => 8]);
        self::assertSame(8, $this->balance()['changeReservedHours']);
        self::assertSame(0, $this->balance()['availableHours']);
        $this->decide($paid, DecisionOutcome::REJECTED);
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(8, $this->occupancy());
    }

    public function testProposalNeedsRestrictedCurrentFundingWhenApprovedLeaveUsedCarry(): void
    {
        $this->transferToB2b('2026-12-01');
        $this->opening(8, [['originYear' => 2025, 'remainingHours' => 8]]);
        $base = $this->initial(['2026-11-09' => 8]);
        $this->decide($base, DecisionOutcome::APPROVED);
        $change = $this->change($base, ['2026-12-01' => 8]);
        self::assertSame(8, $this->balance()['changeReservedHours']);
        self::assertSame([2025 => ['reserved' => 0, 'charged' => 8]], $this->funding($base));
        self::assertSame([2026 => ['reserved' => 8, 'charged' => 0]], $this->funding($change));
        $this->decide($change, DecisionOutcome::APPROVED);
        self::assertSame([2026 => ['reserved' => 0, 'charged' => 8]], $this->funding($change));
        self::assertSame(8, $this->balance()['availableHours']);
    }

    public function testRestrictedProposalWithoutCurrentFundingRollsBackEvenIfTotalDoesNotGrow(): void
    {
        $this->transferToB2b('2026-12-01');
        $this->opening(0, [['originYear' => 2025, 'remainingHours' => 8]]);
        $base = $this->initial(['2026-11-09' => 8]);
        $this->decide($base, DecisionOutcome::APPROVED);
        $id = $base->getRequest()->getId();
        $this->fails(fn () => $this->change($base, ['2026-12-01' => 8]));
        $request = $this->em->find(LeaveRequest::class, $id);
        self::assertInstanceOf(LeaveRequest::class, $request);
        self::assertNull($request->getPendingRevision());
        self::assertCount(1, $request->getRevisions());
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(8, $this->occupancy());
    }

    public function testApprovalPreservesOriginalDeficitPoolInsteadOfMovingDebtToCurrentYear(): void
    {
        $this->opening(0, [['originYear' => 2025, 'remainingHours' => 8]]);
        $base = $this->initial(['2026-11-09' => 8]);
        $this->accounting->correctOpening($this->user('admin'), $this->user(), [
            'operationKey' => $this->key(), 'expectedVersion' => $this->balance()['version'], 'currentYearUsedHours' => 0,
            'carries' => [], 'reason' => 'Synthetic overstated carry',
        ]);
        $this->decide($base, DecisionOutcome::APPROVED);
        self::assertSame([2025 => ['reserved' => 0, 'charged' => 8]], $this->funding($base));
        self::assertSame(-8, $this->balance()['availableHours']);
        $change = $this->change($base, ['2026-11-10' => 8]);
        $this->decide($change, DecisionOutcome::APPROVED);
        self::assertSame([2025 => ['reserved' => 0, 'charged' => 8]], $this->funding($change));
        self::assertSame(-8, $this->balance()['availableHours']);
    }

    public function testHistoricalDebtExceptionCannotFundNewFutureHours(): void
    {
        $this->opening(3);
        $base = $this->initial(['2026-11-06' => 3]);
        $this->decide($base, DecisionOutcome::APPROVED);
        $this->fails(fn () => $this->directCorrection($base, ['2026-11-09' => 7], true));
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(3, $this->occupancy());
    }

    public function testHistoricalDebtExceptionAndCalendarExceptionHaveDifferentDateBoundaries(): void
    {
        $this->opening(3);
        $base = $this->initial(['2026-11-05' => 3]);
        $this->decide($base, DecisionOutcome::APPROVED);
        $past = $this->directCorrection($base, ['2026-11-06' => 7], true);
        self::assertSame(-4, $this->balance()['availableHours']);
        self::assertSame([2026 => ['reserved' => 0, 'charged' => 7]], $this->funding($past));
        $future = $this->directCorrection($past, ['2026-11-09' => 8], false);
        self::assertSame(-5, $this->balance()['availableHours'], 'B29 calendar corrections retain their explicit future deficit authority.');
        self::assertSame([2026 => ['reserved' => 0, 'charged' => 8]], $this->funding($future));
    }

    private function bootAccounting(): void
    {
        self::bootKernel();
        self::getContainer()->set(ClockInterface::class, $this->clock);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        self::assertStringEndsWith('_test', $this->em->getConnection()->getDatabase() ?? '');
        $this->accounting = self::getContainer()->get(AccountingService::class);
    }

    /** @param list<array{originYear: int, remainingHours: int}> $carries */
    private function opening(int $hours, array $carries = []): void
    {
        $this->accounting->initializeOpening($this->user('admin'), $this->user(), ['operationKey' => $this->key(),
            'expectedVersion' => $this->balance()['version'], 'effectiveDate' => '2026-01-01',
            'annualHours' => $hours, 'currentYearUsedHours' => 0, 'carries' => $carries]);
    }

    private function annual(int $year, int $hours): void
    {
        $this->accounting->setAnnualLimit($this->user('admin'), $this->user(), $year, ['operationKey' => $this->key(),
            'expectedVersion' => $this->balance($year)['version'], 'annualHours' => $hours, 'reason' => 'Synthetic limit']);
    }

    private function transferToB2b(string $date): void
    {
        $this->em->find(StaffAssignment::class, $this->ids['assignment'])->closeAt(self::date($date));
        $assignment = new StaffAssignment($this->user(), $this->em->find(Team::class, $this->ids['team']), $this->em->find(EmploymentType::class, $this->ids['b2b']), TeamRole::EMPLOYEE, self::date($date));
        $this->em->persist($assignment);
        $this->em->flush();
        self::assertNotNull($assignment->getId());
        $this->ids['b2b_assignment'] = $assignment->getId();
    }

    /** @param array<string, int> $days */
    private function initial(array $days): RequestRevision
    {
        return $this->em->wrapInTransaction(function () use ($days): RequestRevision {
            $request = new LeaveRequest($this->user(), $this->clock->now());
            $event = $this->event();
            $revision = $this->revision($request, RequestChangeKind::INITIAL, $days, 'paid', $event);
            $this->em->persist($request);
            $this->accounting->reconcileRequest($request, $event);
            return $revision;
        });
    }

    /** @param array<string, int> $days */
    private function change(RequestRevision $base, array $days, string $policy = 'paid', RequestChangeKind $kind = RequestChangeKind::CHANGE): RequestRevision
    {
        return $this->em->wrapInTransaction(function () use ($base, $days, $policy, $kind): RequestRevision {
            $event = $this->event();
            $revision = $this->revision($base->getRequest(), $kind, $days, $policy, $event, $base);
            $this->accounting->reconcileRequest($base->getRequest(), $event);
            return $revision;
        });
    }

    /** @param array<string, int> $days */
    private function directCorrection(RequestRevision $base, array $days, bool $historicalOnly): RequestRevision
    {
        return $this->em->wrapInTransaction(function () use ($base, $days, $historicalOnly): RequestRevision {
            $event = $this->event();
            $revision = $this->revision($base->getRequest(), RequestChangeKind::CHANGE, $days, 'paid', $event, $base);
            $this->em->persist(new RequestDecision($revision, DecisionOutcome::APPROVED, $event));
            $this->accounting->reconcileRequest($base->getRequest(), $event, true, $historicalOnly);
            return $revision;
        });
    }

    /** @param array<string, int> $days */
    private function revision(LeaveRequest $request, RequestChangeKind $kind, array $days, string $policy, AuditEvent $event, ?RequestRevision $base = null): RequestRevision
    {
        ksort($days);
        $requested = [];
        foreach ($days as $date => $hours) {
            $assignment = null;
            foreach ($this->user()->getAssignments() as $candidate) {
                if ($candidate->getValidFrom()->format('Y-m-d') <= $date && (null === $candidate->getValidUntil() || $candidate->getValidUntil()->format('Y-m-d') > $date)) { $assignment = $candidate; }
            }
            self::assertInstanceOf(StaffAssignment::class, $assignment);
            $requested[] = new RequestedDay($assignment, self::date($date), $hours);
        }
        $revision = new RequestRevision($request, $kind, $this->em->find(AbsenceTypeVersion::class, $this->ids[$policy]),
            1 === count($days) && reset($days) < 8 ? RequestMode::HOURLY : RequestMode::FULL_DAYS,
            self::date((string) array_key_first($days)), self::date((string) array_key_last($days)), $requested, $event, $base);
        $this->em->persist($revision);
        return $revision;
    }

    private function decide(RequestRevision $revision, DecisionOutcome $outcome): void
    {
        $this->em->wrapInTransaction(function () use ($revision, $outcome): void {
            $event = $this->event();
            $this->em->persist(new RequestDecision($revision, $outcome, $event));
            $this->accounting->reconcileRequest($revision->getRequest(), $event);
        });
    }

    /** @return array<int, array{reserved: int, charged: int}> */
    private function funding(RequestRevision $revision): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT p.origin_year, SUM(e.reserved_delta) AS reserved, SUM(e.charged_delta) AS charged FROM ledger_entry e JOIN hour_pool p ON p.id=e.pool_id JOIN request_day d ON d.id=e.day_id WHERE d.revision_id=? GROUP BY p.origin_year HAVING SUM(e.reserved_delta) <> 0 OR SUM(e.charged_delta) <> 0 ORDER BY p.origin_year', [$revision->getId()]);
        $result = [];
        foreach ($rows as $row) { $result[(int) $row['origin_year']] = ['reserved' => (int) $row['reserved'], 'charged' => (int) $row['charged']]; }
        return $result;
    }

    private function occupancy(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(SUM(hours),0) FROM daily_occupancy WHERE user_id = ?', [$this->ids['employee']]);
    }

    /** @return array<string, mixed>
     * @phpstan-impure
     */
    private function balance(int $year = 2026): array { return $this->accounting->getBalance($this->user(), $year); }
    private function user(string $name = 'employee'): User { return $this->em->find(User::class, $this->ids[$name]); }
    private function key(): string { return $this->prefix.':'.++$this->sequence; }
    private static function date(string $date): \DateTimeImmutable { return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw')); }
    private function event(): AuditEvent
    {
        $event = new AuditEvent($this->key(), 'test.request_change', $this->user('admin'), $this->user(), $this->clock->now());
        $this->em->persist($event);
        return $event;
    }
    private function fails(callable $operation): void
    {
        try { $operation(); self::fail('An unfunded change was accepted.'); }
        catch (CredentialsException $exception) { self::assertSame('INSUFFICIENT_BALANCE', $exception->errorCode); }
        self::ensureKernelShutdown();
        $this->bootAccounting();
    }
}
