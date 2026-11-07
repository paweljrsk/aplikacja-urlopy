<?php

namespace App\Tests\Unit;

use App\Entity\AbsenceType;
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
use PHPUnit\Framework\TestCase;

final class CalendarAssignmentContextTest extends TestCase
{
    public function testCalendarCorrectionPreservesRulesForAnOriginallyExcludedDateAfterTransfer(): void
    {
        $user = new User('Synthetic', 'Employee', 'calendar-context@example.test');
        $admin = new User('Synthetic', 'Admin', 'calendar-admin@example.test', true);
        $team = new Team('context', 'Context');
        $uop = new EmploymentType('context_uop', 'UoP', true);
        $b2b = new EmploymentType('context_b2b', 'B2B', false);
        $assignment = new StaffAssignment($user, $team, $uop, TeamRole::EMPLOYEE, $this->date('2026-01-01'));
        $policy = (new AbsenceType('context_paid'))->createVersion('Paid', true, [$uop, $b2b]);
        $time = new \DateTimeImmutable('2026-11-07T12:00:00Z');
        $request = new LeaveRequest($user, $time);
        $event = new AuditEvent('context:initial', 'test.initial', $admin, $user, $time);
        $original = new RequestRevision($request, RequestChangeKind::INITIAL, $policy, RequestMode::FULL_DAYS, $this->date('2026-12-14'), $this->date('2026-12-16'), [new RequestedDay($assignment, $this->date('2026-12-14'), 8), new RequestedDay($assignment, $this->date('2026-12-16'), 8)], $event);
        new RequestDecision($original, DecisionOutcome::APPROVED, $event);
        self::assertCount(1, $original->getAssignmentContexts());
        self::assertTrue($original->getAssignmentContexts()[0]->covers($this->date('2026-12-15')));
        $assignment->closeAt($this->date('2026-12-01'));
        new StaffAssignment($user, $team, $b2b, TeamRole::EMPLOYEE, $this->date('2026-12-01'));
        $correction = new RequestRevision($request, RequestChangeKind::CHANGE, $policy, RequestMode::FULL_DAYS, $this->date('2026-12-14'), $this->date('2026-12-16'), [new RequestedDay($assignment, $this->date('2026-12-14'), 8), new RequestedDay($assignment, $this->date('2026-12-15'), 8), new RequestedDay($assignment, $this->date('2026-12-16'), 8)], new AuditEvent('context:correction', 'test.correction', $admin, $user, $time), $original, $original);
        self::assertSame(24, $correction->getTotalHours());
        self::assertSame($uop, $correction->getDays()[1]->getAssignment()->getEmploymentType());
        self::assertSame(16, $original->getTotalHours());
        self::assertNull($original->getAssignmentContexts()[0]->getValidUntil());
    }

    public function testNewRequestCannotUseOldContextFromAnotherRequest(): void
    {
        $user = new User('Synthetic', 'Employee', 'calendar-other@example.test');
        $employment = new EmploymentType('ctx', 'UoP', true);
        $assignment = new StaffAssignment($user, new Team('ctx', 'Context'), $employment, TeamRole::EMPLOYEE, $this->date('2026-01-01'));
        $policy = (new AbsenceType('ctx'))->createVersion('Paid', true, [$employment]);
        $time = new \DateTimeImmutable('2026-11-07T12:00:00Z');
        $event = new AuditEvent('context:foreign', 'test.foreign', $user, $user, $time);
        $days = [new RequestedDay($assignment, $this->date('2026-12-14'), 8)];
        $source = new RequestRevision(new LeaveRequest($user, $time), RequestChangeKind::INITIAL, $policy, RequestMode::FULL_DAYS, $this->date('2026-12-14'), $this->date('2026-12-14'), $days, $event);
        $this->expectException(\DomainException::class);
        new RequestRevision(new LeaveRequest($user, $time), RequestChangeKind::INITIAL, $policy, RequestMode::FULL_DAYS, $this->date('2026-12-14'), $this->date('2026-12-14'), $days, $event, null, $source);
    }

    private function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'));
    }
}
