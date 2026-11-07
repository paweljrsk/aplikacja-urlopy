<?php

namespace App\Tests\Integration;

use App\Entity\AbsenceType;
use App\Entity\AuditEvent;
use App\Entity\AbsenceTypeVersion;
use App\Entity\EmploymentType;
use App\Entity\LeaveRequest;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DecisionOutcome;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Model\RequestedDay;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Enum\TeamRole;
use App\Service\Accounting\AccountingService;
use App\Service\CredentialsException;
use App\Service\Requests\RequestWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** Public workflow against migrated MySQL, synthetic employees and a controlled clock. */
final class RequestChangesWorkflowTest extends KernelTestCase
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
        $this->prefix = 'changes_'.bin2hex(random_bytes(6));
        $this->clock = new MockClock('2026-11-09 12:00:00 Europe/Warsaw');
        $this->bootServices();
        $this->seed();
    }

    public function testShiftedLargerProposalReservesOnlyEightAndRejectionPreservesOriginalSixteen(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-09', '2026-11-10'));
        $original = $this->daysSnapshot((int) $approved['id']);
        $proposal = $this->change($approved, $this->payload('2026-11-16', '2026-11-18'));
        self::assertSame('change_pending', $proposal['status']);
        self::assertSame(56, $this->balance()['availableHours']);
        self::assertSame(8, $this->balance()['changeReservedHours']);
        self::assertSame(16, $this->effective((int) $approved['id'])->getTotalHours());
        self::assertSame(24, $this->pending((int) $approved['id'])->getTotalHours());
        foreach (['2026-11-09', '2026-11-10', '2026-11-16', '2026-11-17', '2026-11-18'] as $day) {
            self::assertSame(8, $this->occupancy($day));
        }
        $rejected = $this->workflow->reject($this->user('manager'), (int) $proposal['id'], $this->transition($proposal) + ['reason' => 'Synthetic denied change']);
        self::assertSame('approved', $rejected['status']);
        self::assertSame(64, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        self::assertSame(8, $this->occupancy('2026-11-09'));
        self::assertSame(0, $this->occupancy('2026-11-16'));
        self::assertSame($original, array_slice($this->daysSnapshot((int) $approved['id']), 0, count($original)));
    }

    public function testSmallerProposalRefundsOnlyAfterApproval(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-09', '2026-11-10'));
        $proposal = $this->change($approved, $this->payload('2026-11-09'));
        self::assertSame(64, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        self::assertSame(8, $this->occupancy('2026-11-10'));
        $changed = $this->approve($proposal);
        self::assertSame('approved', $changed['status']);
        self::assertSame(72, $this->balance()['availableHours']);
        self::assertSame(8, $this->effective((int) $approved['id'])->getTotalHours());
        self::assertSame(0, $this->occupancy('2026-11-10'));
    }

    public function testCancellationProposalProtectsHoursUntilApprovalAndRefundsOnlyOnce(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-16', '2026-11-17'));
        $proposal = $this->workflow->proposeCancellation($this->user(), (int) $approved['id'], $this->transition($approved));
        self::assertSame('cancellation_pending', $proposal['status']);
        self::assertSame(64, $this->balance()['availableHours']);
        self::assertSame(8, $this->occupancy('2026-11-16'));
        $payload = $this->transition($proposal);
        $cancelled = $this->workflow->approve($this->user('manager'), (int) $proposal['id'], $payload);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(0, $this->occupancy('2026-11-16'));
        self::assertNull($this->request((int) $proposal['id'])->getEffectiveRevision());
        $counts = $this->counts();
        $this->assertReceiptSame($cancelled, $this->workflow->approve($this->user('manager'), (int) $proposal['id'], $payload));
        self::assertSame($counts, $this->counts());
        $this->fails(fn () => $this->workflow->approve($this->user('manager'), (int) $proposal['id'], $this->transition($cancelled)), 409);
        self::assertSame(80, $this->balance()['availableHours']);
    }

    public function testRejectingCancellationRetainsApprovedLeave(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-16'));
        $proposal = $this->workflow->proposeCancellation($this->user(), (int) $approved['id'], $this->transition($approved));
        $result = $this->workflow->reject($this->user('deputy'), (int) $proposal['id'], $this->transition($proposal) + ['reason' => 'Synthetic reason']);
        self::assertSame('approved', $result['status']);
        self::assertSame(72, $this->balance()['availableHours']);
        self::assertSame(8, $this->occupancy('2026-11-16'));
        self::assertSame(1, $this->effective((int) $proposal['id'])->getRevision());
    }

    public function testWithdrawalOfChangeReleasesOnlyAdditionalReservation(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-16'));
        $proposal = $this->change($approved, $this->payload('2026-11-16', '2026-11-18'));
        self::assertSame(56, $this->balance()['availableHours']);
        $withdrawn = $this->workflow->withdraw($this->user(), (int) $proposal['id'], $this->transition($proposal));
        self::assertSame('approved', $withdrawn['status']);
        self::assertSame(72, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        self::assertSame(8, $this->occupancy('2026-11-16'));
        self::assertSame(0, $this->occupancy('2026-11-17'));
    }

    public function testSameDayThreeToFiveUsesFiveHoursAndAllowsAnotherThree(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-16', hours: 3));
        $proposal = $this->change($approved, $this->payload('2026-11-16', hours: 5));
        self::assertSame(5, $this->occupancy('2026-11-16'));
        self::assertSame(2, $this->balance()['changeReservedHours']);
        $this->create('2026-11-16', hours: 3, type: 'unpaid');
        self::assertSame(8, $this->occupancy('2026-11-16'));
        $counts = $this->counts();
        $this->fails(fn () => $this->create('2026-11-16', hours: 1, type: 'unpaid'), 409, 'DAILY_CAPACITY_EXCEEDED');
        self::assertSame($counts, $this->counts());
        $this->approve($proposal);
        self::assertSame(8, $this->occupancy('2026-11-16'));
        self::assertSame(75, $this->balance()['availableHours']);
    }

    public function testMovingFullDayProtectsBothDatesButDoesNotReserveSameYearTwice(): void
    {
        $this->opening(8);
        $approved = $this->approve($this->create('2026-11-16'));
        $proposal = $this->change($approved, $this->payload('2026-11-17'));
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        foreach (['2026-11-16', '2026-11-17'] as $day) {
            self::assertSame(8, $this->occupancy($day));
            $this->fails(fn () => $this->create($day, hours: 1, type: 'unpaid'), 409, 'DAILY_CAPACITY_EXCEEDED');
        }
        $this->approve($proposal);
        self::assertSame(0, $this->occupancy('2026-11-16'));
        self::assertSame(8, $this->occupancy('2026-11-17'));
        self::assertSame(0, $this->balance()['availableHours']);
    }

    public function testChangingYearRequiresNewYearsPoolAndKeepsDecemberUntilDecision(): void
    {
        $this->opening(8);
        $approved = $this->approve($this->create('2026-12-31'));
        $counts = $this->counts();
        $this->fails(fn () => $this->change($approved, $this->payload('2027-01-04')), 422, 'LIMIT_NOT_CONFIGURED');
        self::assertSame($counts, $this->counts());
        self::assertSame(8, $this->occupancy('2026-12-31'));
        $this->annual(2027, 8);
        $proposal = $this->change($approved, $this->payload('2027-01-04'));
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance(2027)['availableHours']);
        self::assertSame(8, $this->balance(2027)['changeReservedHours']);
        $this->approve($proposal);
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance(2027)['availableHours']);
        self::assertSame(0, $this->occupancy('2026-12-31'));
        self::assertSame(8, $this->occupancy('2027-01-04'));
    }

    public function testPaidToUnpaidRefundsAfterApprovalAndReverseReservesBeforeDecision(): void
    {
        $this->opening(8);
        $approved = $this->approve($this->create('2026-11-16'));
        $unpaidProposal = $this->change($approved, $this->payload('2026-11-16', type: 'unpaid'));
        self::assertSame(0, $this->balance()['availableHours']);
        $unpaid = $this->approve($unpaidProposal);
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(8, $this->occupancy('2026-11-16'));
        $paidProposal = $this->change($unpaid, $this->payload('2026-11-16'));
        self::assertSame(0, $this->balance()['availableHours']);
        self::assertSame(8, $this->balance()['changeReservedHours']);
        $this->workflow->reject($this->user('manager'), (int) $paidProposal['id'], $this->transition($paidProposal) + ['reason' => 'Synthetic unpaid retained']);
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertFalse($this->effective((int) $approved['id'])->getPolicy()->deductsBalance());
    }

    public function testConcurrentVersionsAndRepeatedProposalsCannotOverwriteAnOpenChange(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-16'));
        $payload = $this->payload('2026-11-16', '2026-11-17') + ['expectedVersion' => $approved['version']];
        $proposal = $this->workflow->proposeChange($this->user(), (int) $approved['id'], $payload);
        $counts = $this->counts();
        $this->assertReceiptSame($proposal, $this->workflow->proposeChange($this->user(), (int) $approved['id'], $payload));
        self::assertSame($counts, $this->counts());
        $this->fails(fn () => $this->change($approved, $this->payload('2026-11-18')), 409);
        $this->fails(fn () => $this->change($proposal, $this->payload('2026-11-18')), 409);
        self::assertSame($counts, $this->counts());
        $accepted = $this->approve($proposal);
        $this->fails(fn () => $this->workflow->reject($this->user('manager'), (int) $proposal['id'], $this->transition($proposal) + ['reason' => 'Stale opposing decision']), 409);
        self::assertSame(16, $this->effective((int) $accepted['id'])->getTotalHours());
    }

    public function testApprovalUsesReservedHoursAfterExplicitLimitReductionWithoutChargingAgain(): void
    {
        $this->opening(24);
        $approved = $this->approve($this->create('2026-11-16', '2026-11-17'));
        $proposal = $this->change($approved, $this->payload('2026-11-16', '2026-11-18'));
        $this->annual(2026, 0);
        self::assertSame(-24, $this->balance()['availableHours']);
        $this->approve($proposal);
        self::assertSame(-24, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        self::assertSame(24, $this->balance()['approvedFutureHours']);
    }

    public function testOrdinaryChangeCannotBypassHistoryBoundaryByMovingOldLeaveForward(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-09'));
        $this->clock->modify('2026-11-10 00:00:00 Europe/Warsaw');
        $counts = $this->counts();
        $this->fails(fn () => $this->change($approved, $this->payload('2026-11-16')), 422);
        $this->fails(fn () => $this->workflow->proposeCancellation($this->user(), (int) $approved['id'], $this->transition($approved)), 422);
        self::assertSame($counts, $this->counts());
        self::assertSame(72, $this->balance()['availableHours']);
    }

    public function testManagerMayApproveOwnProposalButDeputyNeedsAnotherDecisionMaker(): void
    {
        foreach (['manager', 'deputy'] as $subject) {
            $this->opening(80, subject: $subject);
            $created = $this->workflow->create($this->user($subject), $this->payload('2026-11-16', hours: 3));
            $approved = $this->approve($created);
            $proposal = $this->change($approved, $this->payload('2026-11-16', hours: 5), $subject);
            if ('deputy' === $subject) {
                $this->fails(fn () => $this->approve($proposal, $subject), 403);
                self::assertSame(75, $this->balance(2026, $subject)['availableHours']);
                $this->approve($proposal, 'manager');
            } else {
                $this->approve($proposal, $subject);
            }
            self::assertSame(75, $this->balance(2026, $subject)['availableHours']);
            self::assertSame(0, $this->balance(2026, $subject)['changeReservedHours']);
        }
    }

    public function testCapturedPolicyAndOverlappingEmploymentSurviveAnApprovedLeaveChange(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-10', '2026-11-12'));
        $original = $this->daysSnapshot((int) $approved['id']);
        $policyId = $this->effective((int) $approved['id'])->getPolicy()->getId();
        $oldAssignment = $this->entity(StaffAssignment::class, $this->ids['assignment']);
        $oldAssignment->closeAt(self::date('2026-11-10'));
        $newAssignment = new StaffAssignment($this->user(), $this->entity(Team::class, $this->ids['team']), $this->entity(EmploymentType::class, $this->ids['b2b']), TeamRole::EMPLOYEE, self::date('2026-11-10'));
        $this->em->persist($newAssignment);
        $newPolicy = $this->entity(AbsenceType::class, $this->ids['paid'])->createVersion('Changed synthetic dictionary', false, [$this->entity(EmploymentType::class, $this->ids['b2b'])]);
        $this->em->persist($newPolicy);
        $this->em->flush();
        $newAssignmentId = $newAssignment->getId();
        $proposal = $this->change($approved, $this->payload('2026-11-10', '2026-11-13'));
        $pending = $this->pending((int) $approved['id']);
        self::assertSame($policyId, $pending->getPolicy()->getId());
        self::assertSame([$this->ids['assignment'], $this->ids['assignment'], $newAssignmentId], array_map(static fn ($day) => $day->getAssignment()->getId(), $pending->getDays()));
        self::assertTrue($pending->preservesAssignment($this->entity(StaffAssignment::class, $this->ids['assignment']), self::date('2026-11-11')));
        self::assertSame(56, $this->balance()['availableHours']);
        $this->approve($proposal);
        self::assertSame($original, array_slice($this->daysSnapshot((int) $approved['id']), 0, count($original)));
    }

    public function testProposedB2bDateCannotReuseOldCarryFundingWithoutAnnualCoverage(): void
    {
        $this->opening(0, carries: [['originYear' => 2025, 'remainingHours' => 8]]);
        $approved = $this->approve($this->create('2026-11-10', hours: 6));
        $old = $this->entity(StaffAssignment::class, $this->ids['assignment']);
        $old->closeAt(self::date('2026-11-12'));
        $new = new StaffAssignment($this->user(), $this->entity(Team::class, $this->ids['team']), $this->entity(EmploymentType::class, $this->ids['b2b']), TeamRole::EMPLOYEE, self::date('2026-11-12'));
        $this->em->persist($new);
        $this->em->flush();
        $counts = $this->counts();
        $this->fails(fn () => $this->change($approved, $this->payload('2026-11-13', hours: 6)), 422, 'INSUFFICIENT_BALANCE');
        self::assertSame($counts, $this->counts());
        self::assertSame(6, $this->occupancy('2026-11-10'));
        self::assertSame(0, $this->occupancy('2026-11-13'));
    }

    public function testHistoricalPreviewDoesNotWriteAndConfirmationAppliesOneAuditedRevision(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-09', '2026-11-10'));
        $original = $this->daysSnapshot((int) $approved['id']);
        $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
        $payload = $this->correction($approved, '2026-11-09', hours: 3);
        $counts = $this->counts();
        $before = $this->balance();
        $preview = $this->workflow->previewCorrection($this->user('manager'), (int) $approved['id'], $payload);
        self::assertIsString($preview['previewToken']);
        self::assertFalse($preview['requiresDeficitConfirmation']);
        self::assertSame($counts, $this->counts(), 'Preview must leave no audit, revision, decision or ledger writes.');
        self::assertSame($before['version'], $this->balance()['version']);
        self::assertSame(64, $this->balance()['availableHours']);
        self::assertSame($approved['version'], $this->request((int) $approved['id'])->getVersion());
        $changed = $this->confirmCorrection($approved, $payload, $preview, 'manager');
        self::assertSame('approved', $changed['status']);
        self::assertSame(77, $this->balance()['availableHours']);
        self::assertSame(3, $this->balance()['usedHours']);
        self::assertSame(3, $this->occupancy('2026-11-09'));
        self::assertSame(0, $this->occupancy('2026-11-10'));
        $revisions = $this->request((int) $approved['id'])->getRevisions();
        self::assertCount(2, $revisions);
        self::assertSame(DecisionOutcome::APPROVED, $revisions[0]->getDecision()?->getOutcome());
        self::assertSame(DecisionOutcome::APPROVED, $revisions[1]->getDecision()?->getOutcome());
        self::assertSame('Synthetic historical correction', $revisions[1]->getDecision()->getEvent()->getAfterData()['reason']);
        self::assertSame($original, array_slice($this->daysSnapshot((int) $approved['id']), 0, count($original)));
    }

    public function testHistoricalCancellationReplaysWithoutAnotherRefund(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-09'));
        $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
        $data = $this->transition($approved);
        unset($data['operationKey']);
        $data += ['action' => 'cancel', 'reason' => 'Synthetic cancellation of incorrect record'];
        $preview = $this->workflow->previewCorrection($this->user('deputy'), (int) $approved['id'], $data);
        $confirmed = $data + ['operationKey' => $this->key('correct'), 'previewToken' => $preview['previewToken'], 'confirm' => true];
        $cancelled = $this->workflow->correct($this->user('deputy'), (int) $approved['id'], $confirmed);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(0, $this->occupancy('2026-11-09'));
        $counts = $this->counts();
        $this->assertReceiptSame($cancelled, $this->workflow->correct($this->user('deputy'), (int) $approved['id'], $confirmed));
        self::assertSame($counts, $this->counts());
    }

    public function testHistoricalCorrectionRequiresExplicitConfirmationReasonAndFreshPreview(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-09', hours: 3));
        $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
        $data = $this->correction($approved, '2026-11-09', hours: 5);
        $this->fails(fn () => $this->workflow->previewCorrection($this->user('manager'), (int) $approved['id'], array_replace($data, ['reason' => '  '])), 422);
        $preview = $this->workflow->previewCorrection($this->user('manager'), (int) $approved['id'], $data);
        $counts = $this->counts();
        $missingConfirmation = $data + ['operationKey' => $this->key('correct'), 'previewToken' => $preview['previewToken']];
        $this->fails(fn () => $this->workflow->correct($this->user('manager'), (int) $approved['id'], $missingConfirmation), 422);
        self::assertSame($counts, $this->counts());
        $this->annual(2026, 72);
        $counts = $this->counts();
        $this->fails(fn () => $this->confirmCorrection($approved, $data, $preview, 'manager'), 409);
        self::assertSame($counts, $this->counts());
        self::assertSame(3, $this->effective((int) $approved['id'])->getTotalHours());
        $fresh = $this->workflow->previewCorrection($this->user('manager'), (int) $approved['id'], $data);
        $this->confirmCorrection($approved, $data, $fresh, 'manager');
        self::assertSame(67, $this->balance()['availableHours']);
    }

    public function testHistoricalPreviewCannotBeReusedWithChangedTargetOrDifferentActor(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-09', hours: 3));
        $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
        $data = $this->correction($approved, '2026-11-09', hours: 5);
        $preview = $this->workflow->previewCorrection($this->user('manager'), (int) $approved['id'], $data);
        $this->fails(fn () => $this->confirmCorrection($approved, array_replace($data, ['hours' => 6]), $preview, 'manager'), 409);
        $this->fails(fn () => $this->confirmCorrection($approved, $data, $preview, 'deputy'), 409);
        self::assertSame(3, $this->effective((int) $approved['id'])->getTotalHours());
        self::assertSame(77, $this->balance()['availableHours']);
    }

    public function testHistoricalSelfServiceAndDeputyOwnCorrectionAreForbiddenWhileManagerOwnWorks(): void
    {
        foreach (['employee', 'manager', 'deputy'] as $subject) {
            $this->opening(80, subject: $subject);
            $created = $this->workflow->create($this->user($subject), $this->payload('2026-11-09', hours: 3));
            $approved = $this->approve($created);
            $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
            $data = $this->correction($approved, '2026-11-09', hours: 5);
            if ('manager' !== $subject) {
                $this->fails(fn () => $this->workflow->previewCorrection($this->user($subject), (int) $approved['id'], $data), 403);
                self::assertSame(77, $this->balance(2026, $subject)['availableHours']);
                $actor = 'manager';
            } else {
                $actor = 'manager';
            }
            $preview = $this->workflow->previewCorrection($this->user($actor), (int) $approved['id'], $data);
            $this->confirmCorrection($approved, $data, $preview, $actor);
            self::assertSame(75, $this->balance(2026, $subject)['availableHours']);
            $this->clock->modify('2026-11-09 12:00:00 Europe/Warsaw');
        }
    }

    public function testHistoricalCorrectionCannotSilentlyReplaceAnUndecidedChange(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-09', hours: 3));
        $proposal = $this->change($approved, $this->payload('2026-11-09', hours: 5));
        $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
        $counts = $this->counts();
        $data = $this->correction($proposal, '2026-11-09', hours: 2);
        $this->fails(fn () => $this->workflow->previewCorrection($this->user('admin'), (int) $proposal['id'], $data), 409);
        self::assertSame($counts, $this->counts());
        self::assertSame(3, $this->effective((int) $proposal['id'])->getTotalHours());
        self::assertSame(5, $this->pending((int) $proposal['id'])->getTotalHours());
    }

    public function testHistoricalPendingCorrectionStaysPendingAndRetainsOriginalHistory(): void
    {
        $this->opening(80);
        $pending = $this->create('2026-11-09', hours: 3);
        $original = $this->daysSnapshot((int) $pending['id']);
        $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
        $data = $this->correction($pending, '2026-11-09', hours: 5);
        $preview = $this->workflow->previewCorrection($this->user('manager'), (int) $pending['id'], $data);
        $result = $this->confirmCorrection($pending, $data, $preview, 'manager');
        self::assertSame('pending', $result['status']);
        self::assertNull($this->request((int) $pending['id'])->getEffectiveRevision());
        self::assertSame(5, $this->balance()['reservedHours']);
        self::assertSame(0, $this->balance()['usedHours']);
        self::assertSame(DecisionOutcome::SUPERSEDED, $this->request((int) $pending['id'])->getRevisions()[0]->getDecision()?->getOutcome());
        self::assertSame($original, array_slice($this->daysSnapshot((int) $pending['id']), 0, count($original)));
    }

    public function testHistoricalCorrectionBeforeOpeningIsBlockedEvenForAdministratorAndUnpaidLeave(): void
    {
        $this->opening(80, '2026-11-10');
        $approved = $this->approve($this->create('2026-11-12', type: 'unpaid'));
        $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
        $counts = $this->counts();
        $data = $this->correction($approved, '2026-11-09', type: 'unpaid');
        $this->fails(fn () => $this->workflow->previewCorrection($this->user('admin'), (int) $approved['id'], $data), 422, 'BEFORE_OPENING');
        self::assertSame($counts, $this->counts());
        self::assertSame(8, $this->occupancy('2026-11-12'));
        self::assertSame(0, $this->occupancy('2026-11-09'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function legacyCancellationCases(): iterable
    {
        yield 'paid before corrected opening date' => ['paid', true];
        yield 'unpaid before corrected opening date' => ['unpaid', true];
        yield 'paid without opening' => ['paid', false];
        yield 'unpaid without opening' => ['unpaid', false];
    }

    #[DataProvider('legacyCancellationCases')]
    public function testHistoricalLegacyCancellationCannotBypassOriginalRecordingBoundary(string $type, bool $hasOpening): void
    {
        if ($hasOpening) {
            $this->opening(80);
            // No application has been accounted yet, so correcting the initial date is valid.
            $this->accounting->correctOpening($this->user('admin'), $this->user(), [
                'operationKey' => $this->key('correct_opening'), 'expectedVersion' => $this->balance()['version'],
                'effectiveDate' => '2026-11-10', 'currentYearUsedHours' => 0, 'carries' => [],
                'reason' => 'Synthetic later recording start',
            ]);
        }
        // Model-stage/imported applications can exist without a ledger or occupancy projection.
        // They must not be "fixed" by a public cancellation outside the recorded period.
        $source = new AuditEvent($this->key('legacy'), 'fixture.legacy_request', $this->user('admin'), $this->user(), $this->clock->now());
        $request = new LeaveRequest($this->user(), $this->clock->now());
        $revision = new RequestRevision($request, RequestChangeKind::INITIAL, $this->entity(AbsenceTypeVersion::class, $this->ids[$type.'Policy']), RequestMode::FULL_DAYS,
            self::date('2026-11-09'), self::date('2026-11-09'),
            [new RequestedDay($this->entity(StaffAssignment::class, $this->ids['assignment']), self::date('2026-11-09'), 8)], $source);
        foreach ([$source, $request, $revision] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        $id = $request->getId();
        self::assertNotNull($id);
        $version = $request->getVersion();
        $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
        $counts = $this->counts();
        $data = ['expectedVersion' => $version, 'action' => 'cancel', 'reason' => 'Synthetic legacy cancellation'];
        $this->fails(fn () => $this->workflow->previewCorrection($this->user('admin'), $id, $data), 422, 'BEFORE_OPENING');
        self::assertSame($counts, $this->counts());
        self::assertNull($this->pending($id)->getDecision());
        self::assertSame($version, $this->request($id)->getVersion());
    }

    public function testCorrectionAcrossTeamTransferNeedsAdministratorAndDoesNotRewriteOriginalAssignment(): void
    {
        $this->opening(80);
        $approved = $this->approve($this->create('2026-11-10', hours: 3));
        $original = $this->daysSnapshot((int) $approved['id']);
        $old = $this->entity(StaffAssignment::class, $this->ids['assignment']);
        $old->closeAt(self::date('2026-11-12'));
        $new = new StaffAssignment($this->user(), $this->entity(Team::class, $this->ids['otherTeam']), $this->entity(EmploymentType::class, $this->ids['employment']), TeamRole::EMPLOYEE, self::date('2026-11-12'));
        $this->em->persist($new);
        $this->em->flush();
        $this->clock->modify('2026-11-20 12:00:00 Europe/Warsaw');
        $data = $this->correction($approved, '2026-11-13', hours: 3);
        foreach (['manager', 'otherManager'] as $actor) {
            $this->fails(fn () => $this->workflow->previewCorrection($this->user($actor), (int) $approved['id'], $data), 403);
        }
        $preview = $this->workflow->previewCorrection($this->user('admin'), (int) $approved['id'], $data);
        $this->confirmCorrection($approved, $data, $preview, 'admin');
        self::assertSame(0, $this->occupancy('2026-11-10'));
        self::assertSame(3, $this->occupancy('2026-11-13'));
        self::assertSame($original, array_slice($this->daysSnapshot((int) $approved['id']), 0, count($original)));
    }

    public function testPastYearDeficitRequiresExplicitAdministratorConfirmationAndPreservesLaterLeave(): void
    {
        $this->clock->modify('2025-12-29 12:00:00 Europe/Warsaw');
        $this->opening(8, '2025-01-01');
        $past = $this->approve($this->create('2025-12-29', hours: 3));
        $this->clock->modify('2026-11-09 12:00:00 Europe/Warsaw');
        $this->annual(2026, 0);
        $later = $this->approve($this->create('2026-11-10', hours: 5));
        $laterSnapshot = $this->daysSnapshot((int) $later['id']);
        self::assertSame(0, $this->balance()['availableHours']);
        $data = $this->correction($past, '2025-12-29', hours: 6);
        $this->fails(fn () => $this->workflow->previewCorrection($this->user('manager'), (int) $past['id'], $data), 422);
        $this->fails(fn () => $this->workflow->previewCorrection($this->user('deputy'), (int) $past['id'], $data), 422);
        $preview = $this->workflow->previewCorrection($this->user('admin'), (int) $past['id'], $data);
        self::assertTrue($preview['requiresDeficitConfirmation']);
        self::assertSame(0, $this->balance()['availableHours']);
        $this->fails(fn () => $this->confirmCorrection($past, $data, $preview, 'admin'), 422);
        $updated = $this->confirmCorrection($past, $data, $preview, 'admin', true);
        self::assertSame('approved', $updated['status']);
        self::assertSame(-3, $this->balance()['availableHours']);
        self::assertSame(3, $this->balance()['deficitHours']);
        self::assertSame(5, $this->effective((int) $later['id'])->getTotalHours());
        self::assertSame($laterSnapshot, $this->daysSnapshot((int) $later['id']));
        self::assertSame(5, $this->occupancy('2026-11-10'));
        $this->fails(fn () => $this->create('2026-11-12', hours: 1), 422, 'INSUFFICIENT_BALANCE');
        self::assertSame(-3, $this->balance()['availableHours']);
    }

    /** @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function correction(array $receipt, string $start, ?string $end = null, ?int $hours = null, string $type = 'paid'): array
    {
        $data = $this->payload($start, $end, $hours, $type);
        unset($data['operationKey']);
        return $data + ['action' => 'change', 'expectedVersion' => $receipt['version'], 'reason' => 'Synthetic historical correction'];
    }

    /** @param array<string,mixed> $receipt
     * @param array<string,mixed> $data
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function confirmCorrection(array $receipt, array $data, array $preview, string $actor, bool $allowDeficit = false): array
    {
        return $this->workflow->correct($this->user($actor), (int) $receipt['id'], $data + [
            'operationKey' => $this->key('correct'), 'previewToken' => $preview['previewToken'], 'confirm' => true, 'allowDeficit' => $allowDeficit,
        ]);
    }

    /** @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function approve(array $receipt, string $actor = 'admin'): array
    {
        return $this->workflow->approve($this->user($actor), (int) $receipt['id'], $this->transition($receipt));
    }

    private function effective(int $id): RequestRevision
    {
        $revision = $this->request($id)->getEffectiveRevision();
        self::assertInstanceOf(RequestRevision::class, $revision);
        return $revision;
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
        $manager = new User('Synthetic', 'Manager', $this->prefix.'_manager@example.test');
        $deputy = new User('Synthetic', 'Deputy', $this->prefix.'_deputy@example.test');
        $otherTeam = new Team($this->prefix.'_other', 'Other synthetic team');
        $otherManager = new User('Synthetic', 'Other manager', $this->prefix.'_other_manager@example.test');
        $managerAssignment = new StaffAssignment($manager, $team, $employment, TeamRole::MANAGER, self::date('2025-01-01'));
        $deputyAssignment = new StaffAssignment($deputy, $team, $employment, TeamRole::DEPUTY, self::date('2025-01-01'));
        $otherManagerAssignment = new StaffAssignment($otherManager, $otherTeam, $employment, TeamRole::MANAGER, self::date('2025-01-01'));
        $assignment = new StaffAssignment($user, $team, $employment, TeamRole::EMPLOYEE, self::date('2025-01-01'));
        $paid = new AbsenceType($this->prefix.'_paid');
        $paidPolicy = $paid->createVersion('Synthetic paid leave', true, [$employment, $b2b]);
        $unpaid = new AbsenceType($this->prefix.'_unpaid');
        $unpaidPolicy = $unpaid->createVersion('Synthetic non-deducting absence', false, [$employment, $b2b]);
        $entities = compact('team', 'employment', 'b2b', 'admin', 'assignment', 'paid', 'unpaid', 'paidPolicy', 'unpaidPolicy', 'manager', 'deputy', 'otherTeam', 'otherManager', 'managerAssignment', 'deputyAssignment', 'otherManagerAssignment') + ['employee' => $user];
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
    private function opening(int $hours, string $date = '2026-01-01', array $carries = [], string $subject = 'employee'): void
    {
        $year = (int) substr($date, 0, 4);
        $this->accounting->initializeOpening($this->user('admin'), $this->user($subject), [
            'operationKey' => $this->key('opening'), 'expectedVersion' => $this->balance($year, $subject)['version'],
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
    private function change(array $request, array $payload, string $actor = 'employee'): array
    {
        return $this->workflow->proposeChange($this->user($actor), (int) $request['id'], $payload + ['expectedVersion' => $request['version']]);
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
    private function balance(int $year = 2026, string $subject = 'employee'): array
    {
        return $this->accounting->getBalance($this->user($subject), $year);
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
