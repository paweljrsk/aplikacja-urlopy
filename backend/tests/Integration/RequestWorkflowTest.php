<?php

namespace App\Tests\Integration;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\EmploymentType;
use App\Entity\LeaveRequest;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DecisionOutcome;
use App\Enum\TeamRole;
use App\Service\Accounting\AccountingService;
use App\Service\CredentialsException;
use App\Service\Requests\RequestWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** Public workflow against migrated MySQL, synthetic employees and a controlled clock. */
final class RequestWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AccountingService $accounting;
    private RequestWorkflowService $workflow;
    private MockClock $clock;
    private string $prefix;
    private int $sequence = 0;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->prefix = 'wf_'.bin2hex(random_bytes(6));
        $this->clock = new MockClock('2026-11-09 12:00:00 Europe/Warsaw');
        $this->bootServices();
        $this->seed();
    }

    public function testSubmissionApprovalAndPassageOfTimeChargeEightyMinusSixteenOnlyOnce(): void
    {
        $this->opening(80);
        $created = $this->create('2026-11-09', '2026-11-10');
        self::assertSame('pending', $created['status']);
        self::assertSame(64, $this->balance()['availableHours']);
        self::assertSame(16, $this->balance()['reservedHours']);
        $originalDays = $this->daysSnapshot((int) $created['id']);
        $payload = $this->transition($created, 'approve');
        $approved = $this->workflow->approve($this->user('admin'), (int) $created['id'], $payload);
        self::assertSame('approved', $approved['status']);
        self::assertSame(64, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame(8, $this->balance()['usedHours']);
        self::assertSame(8, $this->balance()['approvedFutureHours']);
        $counts = $this->counts();
        $this->assertReceiptSame($approved, $this->workflow->approve($this->user('admin'), (int) $created['id'], $payload));
        self::assertSame($counts, $this->counts(), 'An identical retry creates no new audit or financial movements.');
        $this->clock->modify('2026-11-20 12:00:00');
        self::assertSame(16, $this->balance()['usedHours']);
        self::assertSame(0, $this->balance()['approvedFutureHours']);
        self::assertSame(64, $this->balance()['availableHours']);
        self::assertSame($originalDays, $this->daysSnapshot((int) $created['id']));
    }

    public function testEditingPendingReplacesItsReservationAndRetainsImmutablePriorVersion(): void
    {
        $this->opening(80);
        $created = $this->create('2026-11-09', '2026-11-10');
        $original = $this->daysSnapshot((int) $created['id']);
        $edited = $this->edit($created, $this->payload('2026-11-09', hours: 3));
        self::assertSame(2, $edited['revision']);
        self::assertSame(77, $this->balance()['availableHours']);
        self::assertSame(3, $this->occupancy('2026-11-09'));
        self::assertSame(0, $this->occupancy('2026-11-10'));
        $revisions = $this->request((int) $created['id'])->getRevisions();
        self::assertCount(2, $revisions);
        self::assertSame(DecisionOutcome::SUPERSEDED, $revisions[0]->getDecision()?->getOutcome());
        self::assertNull($revisions[1]->getDecision());
        self::assertSame(16, $revisions[0]->getTotalHours());
        self::assertSame(3, $revisions[1]->getTotalHours());
        self::assertSame($original, array_slice($this->daysSnapshot((int) $created['id']), 0, count($original)));
    }

    public function testFailedEditRollsBackSupersessionAuditLedgerAndDayOccupancyTogether(): void
    {
        $this->opening(8);
        $created = $this->create('2026-11-09');
        $counts = $this->counts();
        $payload = $this->payload('2026-11-09', '2026-11-10');
        $payload['expectedVersion'] = $created['version'];
        $this->fails(fn () => $this->workflow->edit($this->user(), (int) $created['id'], $payload), 422, 'INSUFFICIENT_BALANCE');
        self::assertSame($counts, $this->counts());
        self::assertSame(1, $this->pending((int) $created['id'])->getRevision());
        self::assertSame(8, $this->occupancy('2026-11-09'));
        self::assertSame(0, $this->occupancy('2026-11-10'));
        self::assertSame(0, $this->balance()['availableHours']);
        // A rolled-back attempt does not consume its operation key.
        $payload['mode'] = 'hourly';
        $payload['hours'] = 6;
        $payload['endDate'] = '2026-11-09';
        $edited = $this->workflow->edit($this->user(), (int) $created['id'], $payload);
        self::assertSame(2, $edited['revision']);
        self::assertSame(2, $this->balance()['availableHours']);
    }

    public function testRejectionReleasesReservationAndStoresTrimmedReasonWithDecision(): void
    {
        $this->opening(80);
        $created = $this->create('2026-11-10');
        $payload = $this->transition($created, 'reject') + ['reason' => '  Synthetic staffing conflict  '];
        $rejected = $this->workflow->reject($this->user('admin'), (int) $created['id'], $payload);
        self::assertSame('rejected', $rejected['status']);
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(0, $this->occupancy('2026-11-10'));
        $decision = $this->request((int) $created['id'])->getRevisions()[0]->getDecision();
        self::assertSame(DecisionOutcome::REJECTED, $decision?->getOutcome());
        self::assertSame('Synthetic staffing conflict', $decision->getEvent()->getAfterData()['reason']);
        $this->clock->modify('2026-11-20 12:00:00');
        $counts = $this->counts();
        $this->assertReceiptSame($rejected, $this->workflow->reject($this->user('admin'), (int) $created['id'], $payload));
        self::assertSame($counts, $this->counts());
        $this->fails(fn () => $this->workflow->approve($this->user('admin'), (int) $created['id'], $this->transition($rejected)), 409);
    }

    public function testWithdrawalReleasesHoursOnceAndClosedRequestCannotBeEdited(): void
    {
        $this->opening(80);
        $created = $this->create('2026-11-10');
        $payload = $this->transition($created, 'withdraw');
        $withdrawn = $this->workflow->withdraw($this->user(), (int) $created['id'], $payload);
        self::assertSame('withdrawn', $withdrawn['status']);
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(0, $this->occupancy('2026-11-10'));
        $counts = $this->counts();
        $this->assertReceiptSame($withdrawn, $this->workflow->withdraw($this->user(), (int) $created['id'], $payload));
        self::assertSame($counts, $this->counts());
        $this->fails(fn () => $this->edit($withdrawn, $this->payload('2026-11-12')), 409);
    }

    public function testNonDeductingLeaveWorksAtZeroLimitButConsumesDailyCapacity(): void
    {
        $this->opening(0);
        $unpaid = $this->create('2026-11-10', type: 'unpaid');
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame(8, $this->occupancy('2026-11-10'));
        $this->fails(fn () => $this->create('2026-11-10', hours: 1, type: 'unpaid'), 409, 'DAILY_CAPACITY_EXCEEDED');
        $approved = $this->workflow->approve($this->user('admin'), (int) $unpaid['id'], $this->transition($unpaid));
        self::assertSame('approved', $approved['status']);
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(8, $this->occupancy('2026-11-10'));
    }

    public function testThreeAndFiveHoursAreAllowedWithoutInventingTimeOfDayCollisions(): void
    {
        $this->opening(80);
        $this->create('2026-11-10', hours: 3);
        $this->create('2026-11-10', hours: 5, type: 'unpaid');
        self::assertSame(77, $this->balance()['availableHours']);
        self::assertSame(8, $this->occupancy('2026-11-10'));
        $counts = $this->counts();
        $this->fails(fn () => $this->create('2026-11-10', hours: 1), 409, 'DAILY_CAPACITY_EXCEEDED');
        self::assertSame($counts, $this->counts(), 'Daily overflow rolls back the tentative paid reservation too.');
        self::assertSame(77, $this->balance()['availableHours']);
    }

    public function testZeroAndMissingAnnualLimitsHaveDistinctFailuresWhileUnpaidNeedsNoGrant(): void
    {
        $this->opening(0);
        $this->fails(fn () => $this->create('2026-11-10'), 422, 'INSUFFICIENT_BALANCE');
        $this->fails(fn () => $this->create('2027-01-04'), 422, 'LIMIT_NOT_CONFIGURED');
        $unpaid = $this->create('2027-01-04', type: 'unpaid');
        self::assertSame('pending', $unpaid['status']);
        self::assertFalse($this->balance(2027)['configured']);
    }

    public function testCrossYearSubmissionRollsBackBothYearsWhenJanuaryHasNoLimit(): void
    {
        $this->opening(80);
        $counts = $this->counts();
        $this->fails(fn () => $this->create('2026-12-31', '2027-01-04'), 422, 'LIMIT_NOT_CONFIGURED');
        self::assertSame($counts, $this->counts());
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(0, $this->occupancy('2026-12-31'));
        self::assertSame(0, $this->occupancy('2027-01-04'));
    }

    public function testCrossYearSubmissionAndApprovalAllocateByAbsenceYear(): void
    {
        $this->opening(16);
        $this->annual(2027, 24);
        $created = $this->create('2026-12-31', '2027-01-04');
        self::assertSame(['2026-12-31' => 8, '2027-01-04' => 8], $this->dayHours($this->pending((int) $created['id'])));
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(16, $this->balance(2027)['availableHours']);
        $this->workflow->approve($this->user('admin'), (int) $created['id'], $this->transition($created));
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(16, $this->balance(2027)['availableHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame(0, $this->balance(2027)['reservedHours']);
    }

    public function testTodayCanBeSubmittedEditedAndWithdrawnUntilWarsawDayEnds(): void
    {
        $this->opening(80);
        $this->clock->modify('2026-11-09 23:59:59 Europe/Warsaw');
        $created = $this->create('2026-11-09', hours: 3);
        $edited = $this->edit($created, $this->payload('2026-11-09', hours: 5));
        self::assertSame(75, $this->balance()['availableHours']);
        $withdrawn = $this->workflow->withdraw($this->user(), (int) $edited['id'], $this->transition($edited));
        self::assertSame('withdrawn', $withdrawn['status']);
        self::assertSame(80, $this->balance()['availableHours']);
    }

    public function testAfterDayBoundaryOwnerCannotMoveOldPendingToFutureButSupervisorCanDecide(): void
    {
        $this->opening(80);
        $created = $this->create('2026-11-09', '2026-11-10');
        $this->clock->modify('2026-11-10 00:00:00 Europe/Warsaw');
        $this->fails(fn () => $this->edit($created, $this->payload('2026-11-12')), 422);
        $this->fails(fn () => $this->workflow->withdraw($this->user(), (int) $created['id'], $this->transition($created)), 422);
        self::assertSame(64, $this->balance()['availableHours']);
        $approved = $this->workflow->approve($this->user('admin'), (int) $created['id'], $this->transition($created));
        self::assertSame('approved', $approved['status']);
        self::assertSame(64, $this->balance()['availableHours']);
    }

    public function testMidnightUsesWarsawEvenWhenClockAndPhpDefaultAreUtc(): void
    {
        $previousZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->opening(80);
            $this->clock = new MockClock('2026-11-08T23:30:00+00:00');
            self::ensureKernelShutdown();
            $this->bootServices();
            $created = $this->create('2026-11-09');
            self::assertSame('pending', $created['status']);
            $this->fails(fn () => $this->create('2026-11-08', '2026-11-10'), 422);
            self::assertSame(72, $this->balance()['availableHours']);
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    public function testPastDatesCannotBeIntroducedByCreatingOrEditingFutureRequest(): void
    {
        $this->opening(80);
        $this->fails(fn () => $this->create('2026-11-06', '2026-11-10'), 422);
        $created = $this->create('2026-11-10');
        $this->fails(fn () => $this->edit($created, $this->payload('2026-11-06', '2026-11-12')), 422);
        self::assertSame(['2026-11-10' => 8], $this->dayHours($this->pending((int) $created['id'])));
    }

    #[DataProvider('invalidHours')]
    public function testHourlyInputRejectsAnythingOtherThanIntegerOneToSeven(mixed $hours): void
    {
        $this->opening(80);
        $data = $this->payload('2026-11-10', hours: 3);
        $data['hours'] = $hours;
        $this->fails(fn () => $this->workflow->create($this->user(), $data), 422);
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(0, $this->counts()['requests']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidHours(): iterable
    {
        yield 'null' => [null];
        yield 'zero' => [0];
        yield 'eight belongs to full-day mode' => [8];
        yield 'negative' => [-1];
        yield 'fraction' => [3.5];
        yield 'integral float' => [3.0];
        yield 'numeric string' => ['3'];
        yield 'boolean' => [true];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidRanges')]
    public function testInvalidDateAndModeCombinationsDoNotCreateAnything(array $changes): void
    {
        $this->opening(80);
        $data = array_replace($this->payload('2026-11-10'), $changes);
        $this->fails(fn () => $this->workflow->create($this->user(), $data), 422);
        self::assertSame(0, $this->counts()['requests']);
        self::assertSame(80, $this->balance()['availableHours']);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function invalidRanges(): iterable
    {
        yield 'reversed range' => [['startDate' => '2026-11-12', 'endDate' => '2026-11-10']];
        yield 'invalid calendar date' => [['startDate' => '2027-02-29', 'endDate' => '2027-02-29']];
        yield 'unsupported year' => [['startDate' => '2101-01-03', 'endDate' => '2101-01-03']];
        yield 'hourly multiple days' => [['mode' => 'hourly', 'hours' => 3, 'endDate' => '2026-11-12']];
        yield 'hours in full days' => [['hours' => 8]];
        yield 'unknown mode' => [['mode' => 'half_day']];
        yield 'statutory holiday only' => [['startDate' => '2026-11-11', 'endDate' => '2026-11-11']];
        yield 'weekend only' => [['startDate' => '2026-11-14', 'endDate' => '2026-11-15']];
    }

    public function testFullDaysSkipWeekendAndStatutoryHoliday(): void
    {
        $this->opening(80);
        $created = $this->create('2026-11-09', '2026-11-16');
        self::assertSame(['2026-11-09' => 8, '2026-11-10' => 8, '2026-11-12' => 8, '2026-11-13' => 8, '2026-11-16' => 8], $this->dayHours($this->pending((int) $created['id'])));
        self::assertSame(40, $this->balance()['availableHours']);
    }

    public function testLeapDayIsCountedAsAnOrdinaryWorkingDate(): void
    {
        $this->clock->modify('2028-02-28 12:00:00 Europe/Warsaw');
        $this->opening(80, '2028-01-01');
        $created = $this->create('2028-02-28', '2028-02-29');
        self::assertSame(['2028-02-28' => 8, '2028-02-29' => 8], $this->dayHours($this->pending((int) $created['id'])));
        self::assertSame(64, $this->balance(2028)['availableHours']);
    }

    public function testEditingKeepsCapturedTypeRulesAndChangingTypeReallocatesWithoutDoubleCapacity(): void
    {
        $this->opening(80);
        $created = $this->create('2026-11-10');
        $capturedPolicyId = $this->pending((int) $created['id'])->getPolicy()->getId();
        $paidType = $this->entity(AbsenceType::class, $this->ids['paid']);
        $newPolicy = $paidType->createVersion('Synthetic now non-deducting rule', false, [$this->entity(EmploymentType::class, $this->ids['employment']), $this->entity(EmploymentType::class, $this->ids['b2b'])]);
        $this->em->persist($newPolicy);
        $this->em->flush();
        $edited = $this->edit($created, $this->payload('2026-11-12'));
        self::assertSame($capturedPolicyId, $this->pending((int) $created['id'])->getPolicy()->getId());
        self::assertSame(72, $this->balance()['availableHours']);
        $new = $this->create('2026-11-13');
        self::assertFalse($this->pending((int) $new['id'])->getPolicy()->deductsBalance());
        self::assertSame(72, $this->balance()['availableHours']);
        $unpaid = $this->edit($edited, $this->payload('2026-11-12', type: 'unpaid'));
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(8, $this->occupancy('2026-11-12'));
        $restored = $this->edit($unpaid, $this->payload('2026-11-12'));
        self::assertSame($capturedPolicyId, $this->pending((int) $restored['id'])->getPolicy()->getId());
        self::assertSame(72, $this->balance()['availableHours']);
        self::assertSame(8, $this->occupancy('2026-11-12'));
    }

    public function testEditingAfterEmploymentTransferPreservesOldRangeButUsesNewAssignmentForAddedDates(): void
    {
        $this->opening(80);
        $created = $this->create('2026-11-10', '2026-11-12');
        $original = $this->daysSnapshot((int) $created['id']);
        $oldAssignmentId = $this->ids['assignment'];
        $old = $this->entity(StaffAssignment::class, $oldAssignmentId);
        $old->closeAt(self::date('2026-11-10'));
        $newAssignment = new StaffAssignment($this->user(), $this->entity(Team::class, $this->ids['team']), $this->entity(EmploymentType::class, $this->ids['b2b']), TeamRole::EMPLOYEE, self::date('2026-11-10'));
        $this->em->persist($newAssignment);
        $this->em->flush();
        $newId = $newAssignment->getId();
        self::assertNotNull($newId);
        $edited = $this->edit($created, $this->payload('2026-11-10', '2026-11-13'));
        $pending = $this->pending((int) $edited['id']);
        $assignments = [];
        foreach ($pending->getDays() as $day) {
            $assignments[$day->getDate()->format('Y-m-d')] = $day->getAssignment()->getId();
        }
        self::assertSame(['2026-11-10' => $oldAssignmentId, '2026-11-12' => $oldAssignmentId, '2026-11-13' => $newId], $assignments);
        self::assertTrue($pending->preservesAssignment($this->entity(StaffAssignment::class, $oldAssignmentId), self::date('2026-11-11')), 'The originally excluded statutory holiday keeps its captured employment context.');
        self::assertFalse($pending->preservesAssignment($this->entity(StaffAssignment::class, $oldAssignmentId), self::date('2026-11-13')), 'An originally unbounded assignment must not leak outside the original request range.');
        self::assertSame($original, array_slice($this->daysSnapshot((int) $created['id']), 0, count($original)));
        self::assertSame(56, $this->balance()['availableHours']);
    }

    public function testOpeningBoundaryAppliesToNonDeductingRequestsAsWell(): void
    {
        $this->fails(fn () => $this->create('2026-11-10', type: 'unpaid'), 422);
        $this->opening(80, '2026-11-10');
        $this->fails(fn () => $this->create('2026-11-09', type: 'unpaid'), 422, 'BEFORE_OPENING');
        self::assertSame(0, $this->counts()['requests']);
        self::assertSame('pending', $this->create('2026-11-10', type: 'unpaid')['status']);
    }

    public function testDeficitDoesNotPreventApprovalOfExistingCommitmentButBlocksAnIncrease(): void
    {
        $this->opening(16);
        $created = $this->create('2026-11-10');
        $this->annual(2026, 0);
        self::assertSame(-8, $this->balance()['availableHours']);
        $this->fails(fn () => $this->edit($created, $this->payload('2026-11-10', '2026-11-12')), 422, 'INSUFFICIENT_BALANCE');
        $approved = $this->workflow->approve($this->user('admin'), (int) $created['id'], $this->transition($created));
        self::assertSame('approved', $approved['status']);
        self::assertSame(-8, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
    }

    public function testMovingCarriedUopReservationToNewB2bDateCannotCreateUnfundedCurrentPoolDeficit(): void
    {
        $this->opening(0, carries: [['originYear' => 2025, 'remainingHours' => 8]]);
        $created = $this->create('2026-11-10', hours: 6);
        self::assertSame(2, $this->balance()['availableHours']);
        $original = $this->daysSnapshot((int) $created['id']);
        $old = $this->entity(StaffAssignment::class, $this->ids['assignment']);
        $old->closeAt(self::date('2026-11-12'));
        $new = new StaffAssignment($this->user(), $this->entity(Team::class, $this->ids['team']), $this->entity(EmploymentType::class, $this->ids['b2b']), TeamRole::EMPLOYEE, self::date('2026-11-12'));
        $this->em->persist($new);
        $this->em->flush();
        $counts = $this->counts();

        $this->fails(fn () => $this->edit($created, $this->payload('2026-11-13', hours: 6)), 422, 'INSUFFICIENT_BALANCE');

        self::assertSame($counts, $this->counts());
        self::assertSame($original, $this->daysSnapshot((int) $created['id']));
        self::assertSame(1, $this->pending((int) $created['id'])->getRevision());
        self::assertSame(6, $this->occupancy('2026-11-10'));
        self::assertSame(0, $this->occupancy('2026-11-13'));
        self::assertSame(2, $this->balance()['availableHours']);
    }

    public function testAnnualFundedUopReservationCanMoveToB2bAndStillApproveAfterExplicitDeficitCorrection(): void
    {
        $this->opening(6);
        $created = $this->create('2026-11-10', hours: 6);
        $old = $this->entity(StaffAssignment::class, $this->ids['assignment']);
        $old->closeAt(self::date('2026-11-12'));
        $new = new StaffAssignment($this->user(), $this->entity(Team::class, $this->ids['team']), $this->entity(EmploymentType::class, $this->ids['b2b']), TeamRole::EMPLOYEE, self::date('2026-11-12'));
        $this->em->persist($new);
        $this->em->flush();

        $edited = $this->edit($created, $this->payload('2026-11-13', hours: 6));
        self::assertSame(2, $edited['revision']);
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(0, $this->occupancy('2026-11-10'));
        self::assertSame(6, $this->occupancy('2026-11-13'));
        self::assertFalse($this->pending((int) $edited['id'])->getDays()[0]->getAssignment()->getEmploymentType()->carriesUnusedHours());

        $this->annual(2026, 0);
        self::assertSame(-6, $this->balance()['availableHours']);
        self::assertSame(6, $this->balance()['reservedHours']);
        $approved = $this->workflow->approve($this->user('admin'), (int) $edited['id'], $this->transition($edited));
        self::assertSame('approved', $approved['status']);
        self::assertSame(-6, $this->balance()['availableHours']);
        self::assertSame(6, $this->balance()['deficitHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame(6, $this->balance()['approvedFutureHours']);
    }

    private function bootServices(): void
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
        $accounting = self::getContainer()->get(AccountingService::class);
        self::assertInstanceOf(AccountingService::class, $accounting);
        $this->accounting = $accounting;
        $workflow = self::getContainer()->get(RequestWorkflowService::class);
        self::assertInstanceOf(RequestWorkflowService::class, $workflow);
        $this->workflow = $workflow;
    }

    private function seed(): void
    {
        $team = new Team($this->prefix.'_team', 'Synthetic workflow team');
        $employment = new EmploymentType($this->prefix.'_uop', 'Synthetic UoP', true);
        $b2b = new EmploymentType($this->prefix.'_b2b', 'Synthetic B2B', false);
        $admin = new User('Synthetic', 'Administrator', $this->prefix.'_admin@example.test', true);
        $user = new User('Synthetic', 'Employee', $this->prefix.'_employee@example.test');
        $assignment = new StaffAssignment($user, $team, $employment, TeamRole::EMPLOYEE, self::date('2025-01-01'));
        $paid = new AbsenceType($this->prefix.'_paid');
        $paidPolicy = $paid->createVersion('Synthetic paid leave', true, [$employment, $b2b]);
        $unpaid = new AbsenceType($this->prefix.'_unpaid');
        $unpaidPolicy = $unpaid->createVersion('Synthetic non-deducting absence', false, [$employment, $b2b]);
        $entities = compact('team', 'employment', 'b2b', 'admin', 'assignment', 'paid', 'unpaid', 'paidPolicy', 'unpaidPolicy') + ['employee' => $user];
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        foreach ($entities as $name => $entity) {
            self::assertNotNull($entity->getId());
            $this->ids[$name] = $entity->getId();
        }
    }

    /** @param list<array{originYear:int,remainingHours:int}> $carries */
    private function opening(int $hours, string $date = '2026-01-01', array $carries = []): void
    {
        $year = (int) substr($date, 0, 4);
        $this->accounting->initializeOpening($this->user('admin'), $this->user(), [
            'operationKey' => $this->key('opening'), 'expectedVersion' => $this->balance($year)['version'],
            'effectiveDate' => $date, 'annualHours' => $hours, 'currentYearUsedHours' => 0, 'carries' => $carries,
        ]);
    }

    private function annual(int $year, int $hours): void
    {
        $this->accounting->setAnnualLimit($this->user('admin'), $this->user(), $year, [
            'operationKey' => $this->key('annual'), 'expectedVersion' => $this->balance($year)['version'],
            'annualHours' => $hours, 'reason' => 'Synthetic annual limit correction',
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(string $start, ?string $end = null, ?int $hours = null, string $type = 'paid'): array
    {
        $data = ['operationKey' => $this->key('request'), 'absenceTypeId' => $this->ids[$type], 'startDate' => $start, 'endDate' => $end ?? $start];
        if (null !== $hours) {
            $data += ['mode' => 'hourly', 'hours' => $hours];
        }
        return $data;
    }

    /** @return array<string,mixed> */
    private function create(string $start, ?string $end = null, ?int $hours = null, string $type = 'paid'): array
    {
        return $this->workflow->create($this->user(), $this->payload($start, $end, $hours, $type));
    }

    /** @param array<string,mixed> $request
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function edit(array $request, array $payload): array
    {
        return $this->workflow->edit($this->user(), (int) $request['id'], $payload + ['expectedVersion' => $request['version']]);
    }

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function transition(array $request, string $action = 'decision'): array
    {
        return ['operationKey' => $this->key($action), 'expectedVersion' => $request['version']];
    }

    /** @return array<string,mixed>
     * @phpstan-impure
     */
    private function balance(int $year = 2026): array
    {
        return $this->accounting->getBalance($this->user(), $year);
    }

    private function request(int $id): LeaveRequest
    {
        return $this->entity(LeaveRequest::class, $id);
    }

    private function pending(int $id): RequestRevision
    {
        $revision = $this->request($id)->getPendingRevision();
        self::assertInstanceOf(RequestRevision::class, $revision);
        return $revision;
    }

    /** @return array<string,int> */
    private function dayHours(RequestRevision $revision): array
    {
        $days = [];
        foreach ($revision->getDays() as $day) {
            $days[$day->getDate()->format('Y-m-d')] = $day->getHours();
        }
        return $days;
    }

    /** @return list<array<string,mixed>> */
    private function daysSnapshot(int $requestId): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT d.id,d.revision_id,d.assignment_id,d.date,d.hours FROM request_day d JOIN request_revision r ON r.id=d.revision_id WHERE r.request_id=? ORDER BY r.revision,d.date', [$requestId]);
    }

    private function occupancy(string $date): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT hours FROM daily_occupancy WHERE user_id=? AND date=?', [$this->ids['employee'], $date]);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $connection = $this->em->getConnection();
        return [
            'requests' => (int) $connection->fetchOne('SELECT COUNT(*) FROM leave_request WHERE user_id=?', [$this->ids['employee']]),
            'revisions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM request_revision r JOIN leave_request a ON a.id=r.request_id WHERE a.user_id=?', [$this->ids['employee']]),
            'decisions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM request_decision d JOIN request_revision r ON r.id=d.revision_id JOIN leave_request a ON a.id=r.request_id WHERE a.user_id=?', [$this->ids['employee']]),
            'events' => (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_event WHERE subject_id=?', [$this->ids['employee']]),
            'ledger' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry e JOIN hour_pool p ON p.id=e.pool_id WHERE p.user_id=?', [$this->ids['employee']]),
        ];
    }

    private function user(string $name = 'employee'): User
    {
        return $this->entity(User::class, $this->ids[$name]);
    }

    /** @param array<string,mixed> $expected
     * @param array<string,mixed> $actual
     */
    private function assertReceiptSame(array $expected, array $actual): void
    {
        // JSON objects have no key order; values and scalar types must still match exactly.
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    /** @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function entity(string $class, int $id): object
    {
        $entity = $this->em->find($class, $id);
        self::assertInstanceOf($class, $entity);
        return $entity;
    }

    /** @param callable():mixed $operation */
    private function fails(callable $operation, int $status, ?string $code = null): void
    {
        try {
            $operation();
            self::fail('The invalid workflow operation was accepted.');
        } catch (CredentialsException $exception) {
            self::assertSame($status, $exception->httpStatus, $exception->getMessage());
            if (null !== $code) {
                self::assertSame($code, $exception->errorCode);
            }
        }
        self::ensureKernelShutdown();
        $this->bootServices();
    }

    private function key(string $action): string
    {
        return $this->prefix.':'.$action.':'.++$this->sequence;
    }

    private static function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Warsaw'));
    }
}
