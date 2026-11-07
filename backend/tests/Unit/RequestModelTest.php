<?php

namespace App\Tests\Unit;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\DailyOccupancy;
use App\Entity\EmploymentType;
use App\Entity\LeaveRequest;
use App\Entity\RequestDay;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type Seed array{user: User, actor: User, assignment: StaffAssignment, type: AbsenceType, policy: AbsenceTypeVersion, request: LeaveRequest}
 */
final class RequestModelTest extends TestCase
{
    public function testFullDayRequestPreservesDatesAcrossTheYearBoundary(): void
    {
        $seed = $this->seed();
        $revision = $this->revision($seed, '2026-12-31', '2027-01-04', [['2026-12-31', 8], ['2027-01-04', 8]]);

        self::assertSame(16, $revision->getTotalHours());
        self::assertSame(['2026-12-31', '2027-01-04'], array_map(static fn (RequestDay $day): string => $day->getDate()->format('Y-m-d'), $revision->getDays()));
        self::assertSame($seed['assignment'], $revision->getDays()[0]->getAssignment());
        self::assertSame($seed['policy'], $revision->getPolicy());
        self::assertSame($revision, $seed['request']->getPendingRevision());
        self::assertNull($seed['request']->getEffectiveRevision());
    }

    public function testHourlyRequestCapturesQuantityForOneDate(): void
    {
        $seed = $this->seed();
        $revision = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 3]], RequestMode::HOURLY);

        self::assertSame(3, $revision->getTotalHours());
        self::assertCount(1, $revision->getDays());
        self::assertSame(RequestMode::HOURLY, $revision->getMode());
        self::assertSame('2026-12-15', $revision->getStartDate()->format('Y-m-d'));
    }

    /** @return iterable<string, array{RequestMode, string, string, list<array{string, int}>}> */
    public static function invalidRequestShapes(): iterable
    {
        yield 'hourly range of two days' => [RequestMode::HOURLY, '2026-12-15', '2026-12-16', [['2026-12-15', 3], ['2026-12-16', 3]]];
        yield 'hourly range despite one supplied day' => [RequestMode::HOURLY, '2026-12-15', '2026-12-16', [['2026-12-15', 3]]];
        yield 'hourly cannot mean a full day' => [RequestMode::HOURLY, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]];
        yield 'full day cannot contain seven hours' => [RequestMode::FULL_DAYS, '2026-12-15', '2026-12-15', [['2026-12-15', 7]]];
        yield 'duplicate date' => [RequestMode::FULL_DAYS, '2026-12-15', '2026-12-15', [['2026-12-15', 8], ['2026-12-15', 8]]];
        yield 'day outside requested range' => [RequestMode::FULL_DAYS, '2026-12-15', '2026-12-15', [['2026-12-16', 8]]];
        yield 'reversed range' => [RequestMode::FULL_DAYS, '2026-12-16', '2026-12-15', [['2026-12-15', 8]]];
        yield 'no accounted working days' => [RequestMode::FULL_DAYS, '2026-12-15', '2026-12-15', []];
    }

    /** @param list<array{string, int}> $days */
    #[DataProvider('invalidRequestShapes')]
    public function testInvalidRequestShapeCannotRegisterARevision(RequestMode $mode, string $start, string $end, array $days): void
    {
        $seed = $this->seed();

        try {
            $this->revision($seed, $start, $end, $days, $mode);
            self::fail('Invalid request shape must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $seed['request']->getRevisions());
            self::assertNull($seed['request']->getPendingRevision());
        }
    }

    public function testForeignEmployeeAssignmentCannotBeUsedForARequest(): void
    {
        $seed = $this->seed();
        $foreign = new StaffAssignment(
            new User('Other', 'Employee', 'other@example.test'),
            $seed['assignment']->getTeam(), $seed['assignment']->getEmploymentType(),
            TeamRole::EMPLOYEE, $this->date('2026-01-01'),
        );

        $this->expectException(\InvalidArgumentException::class);
        new RequestRevision(
            $seed['request'], RequestChangeKind::INITIAL, $seed['policy'], RequestMode::FULL_DAYS,
            $this->date('2026-12-15'), $this->date('2026-12-15'),
            [new RequestedDay($foreign, $this->date('2026-12-15'), 8)], $this->event($seed, 'foreign'),
        );
    }

    public function testANewRequestCannotUseAnAssignmentOutsideItsPeriod(): void
    {
        $seed = $this->seed();
        $seed['assignment']->closeAt($this->date('2026-12-15'));

        $this->expectException(\InvalidArgumentException::class);
        $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);
    }

    public function testOnlyOneRevisionCanBePending(): void
    {
        $seed = $this->seed();
        $first = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);

        try {
            $this->revision($seed, '2026-12-16', '2026-12-16', [['2026-12-16', 8]]);
            self::fail('A second pending revision must be rejected.');
        } catch (\DomainException) {
            self::assertSame([$first], $seed['request']->getRevisions());
            self::assertSame($first, $seed['request']->getPendingRevision());
        }
    }

    public function testPendingAndRejectedChangeKeepTheOriginalApprovedAbsence(): void
    {
        $seed = $this->seed();
        $original = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);
        new RequestDecision($original, DecisionOutcome::APPROVED, $this->event($seed, 'approve'));
        $change = $this->revision($seed, '2026-12-16', '2026-12-16', [['2026-12-16', 8]], RequestMode::FULL_DAYS, RequestChangeKind::CHANGE, $original);

        self::assertSame($original, $seed['request']->getEffectiveRevision());
        self::assertSame($change, $seed['request']->getPendingRevision());
        new RequestDecision($change, DecisionOutcome::REJECTED, $this->event($seed, 'reject_change'));
        self::assertSame($original, $seed['request']->getEffectiveRevision());
        self::assertNull($seed['request']->getPendingRevision());
        self::assertSame(DecisionOutcome::APPROVED, $original->getDecision()?->getOutcome());
    }

    public function testApprovedCancellationPreservesHistoryAfterTheAssignmentWasClosed(): void
    {
        $seed = $this->seed();
        $original = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);
        new RequestDecision($original, DecisionOutcome::APPROVED, $this->event($seed, 'approve'));
        $seed['assignment']->closeAt($this->date('2026-12-01'));
        $cancellation = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]], RequestMode::FULL_DAYS, RequestChangeKind::CANCELLATION, $original);

        self::assertSame($original, $seed['request']->getEffectiveRevision());
        new RequestDecision($cancellation, DecisionOutcome::APPROVED, $this->event($seed, 'approve_cancel'));
        self::assertNull($seed['request']->getEffectiveRevision());
        self::assertNull($seed['request']->getPendingRevision());
        self::assertSame([$original, $cancellation], $seed['request']->getRevisions());
        self::assertSame($original, $cancellation->getBaseRevision());
        self::assertSame(8, $original->getTotalHours());
        self::assertSame(DecisionOutcome::APPROVED, $original->getDecision()?->getOutcome());
        self::assertSame($seed['assignment'], $cancellation->getDays()[0]->getAssignment());
    }

    public function testCancellationCannotAlterTheApprovedContent(): void
    {
        $seed = $this->seed();
        $original = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);
        new RequestDecision($original, DecisionOutcome::APPROVED, $this->event($seed, 'approve'));

        $this->expectException(\DomainException::class);
        $this->revision($seed, '2026-12-16', '2026-12-16', [['2026-12-16', 8]], RequestMode::FULL_DAYS, RequestChangeKind::CANCELLATION, $original);
    }

    public function testPendingEditKeepsTheCapturedPolicyVersion(): void
    {
        $seed = $this->seed();
        $original = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);
        $newPolicy = $seed['type']->createVersion('Changed settings', false, [$seed['assignment']->getEmploymentType()]);
        new RequestDecision($original, DecisionOutcome::SUPERSEDED, $this->event($seed, 'supersede'));

        try {
            new RequestRevision(
                $seed['request'], RequestChangeKind::INITIAL, $newPolicy, RequestMode::FULL_DAYS,
                $this->date('2026-12-16'), $this->date('2026-12-16'),
                [new RequestedDay($seed['assignment'], $this->date('2026-12-16'), 8)], $this->event($seed, 'bad_policy_edit'),
            );
            self::fail('A same-type edit cannot replace the captured policy.');
        } catch (\DomainException) {
            self::assertSame([$original], $seed['request']->getRevisions());
        }

        $replacement = $this->revision($seed, '2026-12-16', '2026-12-16', [['2026-12-16', 8]]);
        self::assertSame(2, $replacement->getRevision());
        self::assertSame($seed['policy'], $replacement->getPolicy());
        self::assertTrue($replacement->getPolicy()->deductsBalance());
        self::assertFalse($newPolicy->deductsBalance());
    }

    public function testRevisionDaysCannotBeMutatedThroughReturnedCollectionOrAppended(): void
    {
        $seed = $this->seed();
        $revision = $this->revision($seed, '2026-12-15', '2026-12-16', [['2026-12-15', 8]]);
        $days = $revision->getDays();
        array_pop($days);
        self::assertCount(1, $revision->getDays());

        $this->expectException(\LogicException::class);
        new RequestDay($revision, $seed['assignment'], $this->date('2026-12-16'), 8);
    }

    public function testASecondDecisionCannotReplaceTheFirst(): void
    {
        $seed = $this->seed();
        $revision = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);
        $decision = new RequestDecision($revision, DecisionOutcome::APPROVED, $this->event($seed, 'approve'));

        try {
            new RequestDecision($revision, DecisionOutcome::REJECTED, $this->event($seed, 'reject'));
            self::fail('The first decision must remain final for its revision.');
        } catch (\DomainException) {
            self::assertSame($decision, $revision->getDecision());
            self::assertSame($revision, $seed['request']->getEffectiveRevision());
        }
    }

    public function testAuditSnapshotsDetachNestedObjectsReferencesAndReturnedArrays(): void
    {
        $seed = $this->seed();
        $object = (object) ['hours' => 8, 'nested' => (object) ['year' => 2025]];
        $hours = 16;
        $before = ['object' => $object, 'referenced' => &$hours];
        $after = ['values' => ['hours' => &$hours]];
        $event = new AuditEvent('snapshot', 'test.snapshot', $seed['actor'], $seed['user'], $this->time(), $before, $after);

        $object->hours = 99;
        $object->nested->year = 2030;
        $hours = 42;
        $before['another'] = 'new value';
        self::assertSame(['object' => ['hours' => 8, 'nested' => ['year' => 2025]], 'referenced' => 16], $event->getBeforeData());
        $copy = $event->getAfterData();
        $copy['values'] = ['hours' => 100];
        self::assertSame(['values' => ['hours' => 16]], $event->getAfterData());
    }

    public function testGlobalAuditEventHasAnExplicitTargetAndNoArtificialEmployeeSubject(): void
    {
        $actor = new User('Test', 'Admin', 'admin@example.test', true);
        $event = new AuditEvent('catalog:paid:2', 'catalog.update', $actor, null, $this->time(), ['deducts' => true], ['deducts' => false], 'absence_type', 'paid');

        self::assertNull($event->getSubject());
        self::assertSame($actor, $event->getActor());
        self::assertSame('absence_type', $event->getTargetType());
        self::assertSame('paid', $event->getTargetReference());
    }

    public function testGlobalAuditEventWithoutATargetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuditEvent('catalog:missing', 'catalog.update', null, null, $this->time());
    }

    public function testDailyOccupancyRejectsOverflowWithoutChangingTheOriginalValue(): void
    {
        $seed = $this->seed();
        $occupancy = new DailyOccupancy($seed['user'], $this->date('2026-12-15'));
        $occupancy->changeBy(6);

        try {
            $occupancy->changeBy(3);
            self::fail('Occupancy cannot exceed eight hours.');
        } catch (\DomainException) {
            self::assertSame(6, $occupancy->getHours());
        }
        $occupancy->changeBy(2);
        self::assertSame(8, $occupancy->getHours());
    }

    public function testEditedRangeRetainsExcludedDaysButDoesNotExtendOldEmploymentOutsideItsRange(): void
    {
        $seed = $this->seed();
        $b2b = new EmploymentType('b2b', 'B2B', false);
        $seed['policy'] = $seed['type']->createVersion('Both employment forms', true, [$seed['assignment']->getEmploymentType(), $b2b]);
        $original = $this->revision($seed, '2026-12-15', '2026-12-17', [['2026-12-15', 8], ['2026-12-17', 8]]);
        $seed['assignment']->closeAt($this->date('2026-12-01'));
        $new = new StaffAssignment($seed['user'], $seed['assignment']->getTeam(), $b2b, TeamRole::EMPLOYEE, $this->date('2026-12-01'));
        $event = $this->event($seed, 'edit');
        new RequestDecision($original, DecisionOutcome::SUPERSEDED, $event);
        $edited = new RequestRevision($seed['request'], RequestChangeKind::INITIAL, $seed['policy'], RequestMode::FULL_DAYS,
            $this->date('2026-12-15'), $this->date('2026-12-18'), [
                new RequestedDay($seed['assignment'], $this->date('2026-12-15'), 8),
                new RequestedDay($seed['assignment'], $this->date('2026-12-17'), 8),
                new RequestedDay($new, $this->date('2026-12-18'), 8),
            ], $event, editSource: $original);

        self::assertSame($seed['assignment'], $edited->capturedAssignmentFor($this->date('2026-12-16')));
        self::assertSame($new, $edited->capturedAssignmentFor($this->date('2026-12-18')));
        self::assertFalse($edited->preservesAssignment($seed['assignment'], $this->date('2026-12-18')));
        self::assertNull($edited->capturedAssignmentFor($this->date('2026-12-19')));
        self::assertSame($original, $seed['request']->getRevisions()[0]);
        self::assertSame($edited, $seed['request']->getPendingRevision());
    }

    public function testEditCannotUseOldOpenEndedSnapshotOnANewDateAfterEmploymentTransfer(): void
    {
        $seed = $this->seed();
        $original = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);
        $seed['assignment']->closeAt($this->date('2026-12-01'));
        new StaffAssignment($seed['user'], $seed['assignment']->getTeam(), $seed['assignment']->getEmploymentType(), TeamRole::EMPLOYEE, $this->date('2026-12-01'));
        $event = $this->event($seed, 'forged_edit');
        new RequestDecision($original, DecisionOutcome::SUPERSEDED, $event);

        $this->expectException(\DomainException::class);
        new RequestRevision($seed['request'], RequestChangeKind::INITIAL, $seed['policy'], RequestMode::FULL_DAYS,
            $this->date('2026-12-16'), $this->date('2026-12-16'),
            [new RequestedDay($seed['assignment'], $this->date('2026-12-16'), 8)], $event, editSource: $original);
    }

    public function testEditCannotSwapCapturedAssignmentInsidePreviousRange(): void
    {
        $seed = $this->seed();
        $original = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);
        $seed['assignment']->closeAt($this->date('2026-12-01'));
        $new = new StaffAssignment($seed['user'], $seed['assignment']->getTeam(), $seed['assignment']->getEmploymentType(), TeamRole::EMPLOYEE, $this->date('2026-12-01'));
        $event = $this->event($seed, 'changed_context');
        new RequestDecision($original, DecisionOutcome::SUPERSEDED, $event);

        $this->expectException(\DomainException::class);
        new RequestRevision($seed['request'], RequestChangeKind::INITIAL, $seed['policy'], RequestMode::FULL_DAYS,
            $this->date('2026-12-15'), $this->date('2026-12-15'),
            [new RequestedDay($new, $this->date('2026-12-15'), 8)], $event, editSource: $original);
    }

    public function testEditSourceMustHaveBeenSupersededInTheSameAuditedOperation(): void
    {
        $seed = $this->seed();
        $original = $this->revision($seed, '2026-12-15', '2026-12-15', [['2026-12-15', 8]]);
        new RequestDecision($original, DecisionOutcome::REJECTED, $this->event($seed, 'reject'));

        $this->expectException(\DomainException::class);
        new RequestRevision($seed['request'], RequestChangeKind::INITIAL, $seed['policy'], RequestMode::FULL_DAYS,
            $this->date('2026-12-15'), $this->date('2026-12-15'),
            [new RequestedDay($seed['assignment'], $this->date('2026-12-15'), 8)], $this->event($seed, 'edit_rejected'), editSource: $original);
    }

    /** @return Seed */
    private function seed(): array
    {
        $user = new User('Test', 'Employee', 'employee@example.test');
        $actor = new User('Test', 'Admin', 'admin@example.test', true);
        $employment = new EmploymentType('uop', 'UoP', true);
        $assignment = new StaffAssignment($user, new Team('it', 'Test team'), $employment, TeamRole::EMPLOYEE, $this->date('2026-01-01'));
        $type = new AbsenceType('paid');
        $policy = $type->createVersion('Paid absence', true, [$employment]);
        $request = new LeaveRequest($user, $this->time());

        return compact('user', 'actor', 'assignment', 'type', 'policy', 'request');
    }

    /**
     * @param Seed                     $seed
     * @param list<array{string, int}> $days
     */
    private function revision(array $seed, string $start, string $end, array $days, RequestMode $mode = RequestMode::FULL_DAYS, RequestChangeKind $kind = RequestChangeKind::INITIAL, ?RequestRevision $base = null): RequestRevision
    {
        return new RequestRevision(
            $seed['request'], $kind, $seed['policy'], $mode, $this->date($start), $this->date($end),
            array_map(fn (array $day): RequestedDay => new RequestedDay($seed['assignment'], $this->date($day[0]), $day[1]), $days),
            $this->event($seed, 'revision_'.(\count($seed['request']->getRevisions()) + 1)), $base,
        );
    }

    /** @param Seed $seed */
    private function event(array $seed, string $key): AuditEvent
    {
        return new AuditEvent('test:'.$key, 'test.'.$key, $seed['actor'], $seed['user'], $this->time());
    }

    private function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Warsaw'));
    }

    private function time(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC'));
    }
}
