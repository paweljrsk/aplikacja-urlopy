<?php

namespace App\Tests\Unit;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
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
use App\Security\CurrentAssignmentResolver;
use App\Security\RequestAccessPolicy;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/** @phpstan-type Seed array{employee: User, assignment: StaffAssignment, old: Team, new: Team, employment: EmploymentType, rules: AbsenceTypeVersion, oldManager: User, newManager: User, admin: User} */
final class RequestAccessPolicyTest extends TestCase
{
    private RequestAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RequestAccessPolicy(new CurrentAssignmentResolver());
    }

    public function testCalendarFiltersEachSideOfATransferAndDoesNotAuthorizePrivateDetails(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-11-30', '2026-12-01']);
        $this->transfer($seed, '2026-12-01');
        $at = $this->date('2026-12-02');

        self::assertSame(['2026-11-30'], $this->visibleDates($seed['oldManager'], $revision, $at));
        self::assertSame(['2026-12-01'], $this->visibleDates($seed['newManager'], $revision, $at));
        self::assertFalse($this->policy->canViewRevisionDetails($seed['oldManager'], $revision, $at));
        self::assertFalse($this->policy->canViewRevisionDetails($seed['newManager'], $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($seed['oldManager'], $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($seed['newManager'], $revision, $at));
        self::assertTrue($this->policy->canDecideRevision($seed['admin'], $revision, $at));
        foreach ([$seed['employee'], $seed['admin']] as $actor) {
            self::assertSame(['2026-11-30', '2026-12-01'], $this->visibleDates($actor, $revision, $at));
            self::assertTrue($this->policy->canViewRevisionDetails($actor, $revision, $at));
        }
    }

    public function testScheduledTransferMovesFutureAccessExactlyAtWarsawMidnight(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-12-07']);
        $this->transfer($seed, '2026-12-01');
        $before = new \DateTimeImmutable('2026-11-30T22:59:59Z');
        $after = new \DateTimeImmutable('2026-11-30T23:00:00Z');

        self::assertTrue($this->policy->canDecideRevision($seed['oldManager'], $revision, $before));
        self::assertTrue($this->policy->canViewRevisionDetails($seed['oldManager'], $revision, $before));
        self::assertSame([], $this->visibleDates($seed['newManager'], $revision, $before));
        self::assertFalse($this->policy->canDecideRevision($seed['newManager'], $revision, $before));
        self::assertFalse($this->policy->canDecideRevision($seed['oldManager'], $revision, $after));
        self::assertSame([], $this->visibleDates($seed['oldManager'], $revision, $after));
        self::assertTrue($this->policy->canDecideRevision($seed['newManager'], $revision, $after));
        self::assertTrue($this->policy->canViewRevisionDetails($seed['newManager'], $revision, $after));
        self::assertSame($seed['assignment'], $revision->getDays()[0]->getAssignment());
    }

    public function testFutureTransferSpanningRequestAlreadyRequiresAdministratorBeforeTransfer(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-11-30', '2026-12-01']);
        $this->transfer($seed, '2026-12-01');
        $before = $this->date('2026-11-20');

        // The scheduled receiving team still has no access, but the old team may not
        // approve an operation spanning two teams even before the transfer takes effect.
        self::assertSame([], $this->visibleDates($seed['newManager'], $revision, $before));
        self::assertFalse($this->policy->canDecideRevision($seed['oldManager'], $revision, $before));
        self::assertFalse($this->policy->canDecideRevision($seed['newManager'], $revision, $before));
        self::assertTrue($this->policy->canDecideRevision($seed['admin'], $revision, $before));
    }

    public function testOldPendingHistoricalRequestRemainsWithOldTeamAfterTransfer(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-11-20']);
        $this->transfer($seed, '2026-12-01');
        $at = $this->date('2026-12-02');

        self::assertTrue($this->policy->canViewRevisionDetails($seed['oldManager'], $revision, $at));
        self::assertTrue($this->policy->canDecideRevision($seed['oldManager'], $revision, $at));
        self::assertFalse($this->policy->canViewRevisionDetails($seed['newManager'], $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($seed['newManager'], $revision, $at));
    }

    public function testExistingApprovalAndEmploymentCalculationSnapshotStayUnchanged(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-12-07']);
        $decision = new RequestDecision($revision, DecisionOutcome::APPROVED, $this->event($seed['employee'], 'approve'));
        $b2b = new EmploymentType('b2b', 'B2B', false);
        $seed['assignment']->closeAt($this->date('2026-12-01'));
        new StaffAssignment($seed['employee'], $seed['new'], $b2b, TeamRole::EMPLOYEE, $this->date('2026-12-01'));
        $at = $this->date('2026-12-02');

        self::assertTrue($this->policy->canViewRevisionDetails($seed['newManager'], $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($seed['newManager'], $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($seed['admin'], $revision, $at));
        self::assertSame($decision, $revision->getDecision());
        self::assertSame($revision, $revision->getRequest()->getEffectiveRevision());
        self::assertSame($seed['employment'], $revision->getDays()[0]->getAssignment()->getEmploymentType());
        self::assertTrue($revision->getDays()[0]->getAssignment()->getEmploymentType()->carriesUnusedHours());
        self::assertSame($seed['rules'], $revision->getPolicy());
        self::assertTrue($revision->getPolicy()->deductsBalance());
        self::assertSame(8, $revision->getTotalHours());
    }

    public function testEmploymentAndRoleChangeWithinSameTeamDoesNotRequireAdministrator(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-11-30', '2026-12-01']);
        $seed['assignment']->closeAt($this->date('2026-12-01'));
        new StaffAssignment($seed['employee'], $seed['old'], new EmploymentType('b2b', 'B2B', false), TeamRole::DEPUTY, $this->date('2026-12-01'));
        $at = $this->date('2026-12-02');

        self::assertTrue($this->policy->canDecideRevision($seed['oldManager'], $revision, $at));
        self::assertTrue($this->policy->canViewRevisionDetails($seed['oldManager'], $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($seed['employee'], $revision, $at));
    }

    public function testPeerSeesCalendarButNeverPrivateDetailsOrDecision(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-11-30']);
        $peer = $this->staff('peer', TeamRole::EMPLOYEE, $seed['old'], $seed['employment']);
        $at = $this->date('2026-11-20');

        self::assertSame(['2026-11-30'], $this->visibleDates($peer, $revision, $at));
        self::assertFalse($this->policy->canViewRevisionDetails($peer, $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($peer, $revision, $at));
        self::assertTrue($this->policy->canViewRevisionDetails($seed['employee'], $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($seed['employee'], $revision, $at));
    }

    public function testManagerCanDecideOwnRequestButDeputyCannot(): void
    {
        $seed = $this->seed();
        $deputy = $this->staff('deputy', TeamRole::DEPUTY, $seed['old'], $seed['employment']);
        $managerRevision = $this->request($seed['oldManager']->getAssignments()[0], $seed['rules'], ['2026-11-30']);
        $deputyRevision = $this->request($deputy->getAssignments()[0], $seed['rules'], ['2026-11-30']);
        $at = $this->date('2026-11-20');

        self::assertTrue($this->policy->canDecideRevision($seed['oldManager'], $managerRevision, $at));
        self::assertFalse($this->policy->canDecideRevision($deputy, $deputyRevision, $at));
        self::assertTrue($this->policy->canDecideRevision($deputy, $managerRevision, $at));
        self::assertTrue($this->policy->canDecideRevision($seed['oldManager'], $deputyRevision, $at));
    }

    public function testFormerSupervisorCannotKeepHistoricalAuthorityAfterChangingTeam(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-11-20']);
        $seed['oldManager']->getAssignments()[0]->closeAt($this->date('2026-12-01'));
        new StaffAssignment($seed['oldManager'], $seed['new'], $seed['employment'], TeamRole::MANAGER, $this->date('2026-12-01'));
        $replacement = $this->staff('replacement', TeamRole::MANAGER, $seed['old'], $seed['employment']);
        $at = $this->date('2026-12-02');

        self::assertFalse($this->policy->canViewRevisionDetails($seed['oldManager'], $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($seed['oldManager'], $revision, $at));
        self::assertTrue($this->policy->canViewRevisionDetails($replacement, $revision, $at));
        self::assertTrue($this->policy->canDecideRevision($replacement, $revision, $at));
    }

    public function testHistoryIsResolvedForEachPeriodAcrossMultipleTransfers(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-11-30', '2026-12-01', '2027-01-04']);
        $middle = $this->transfer($seed, '2026-12-01');
        $thirdTeam = new Team('third', 'Third team');
        $middle->closeAt($this->date('2027-01-01'));
        new StaffAssignment($seed['employee'], $thirdTeam, $seed['employment'], TeamRole::EMPLOYEE, $this->date('2027-01-01'));
        $thirdManager = $this->staff('third_manager', TeamRole::MANAGER, $thirdTeam, $seed['employment']);
        $at = $this->date('2027-01-10');

        self::assertSame(['2026-11-30'], $this->visibleDates($seed['oldManager'], $revision, $at));
        self::assertSame(['2026-12-01'], $this->visibleDates($seed['newManager'], $revision, $at));
        self::assertSame(['2027-01-04'], $this->visibleDates($thirdManager, $revision, $at));
        foreach ([$seed['oldManager'], $seed['newManager'], $thirdManager] as $actor) {
            self::assertFalse($this->policy->canDecideRevision($actor, $revision, $at));
        }
    }

    public function testMissingEffectiveAssignmentFailsClosedInsteadOfUsingCalculationSnapshot(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-12-07']);
        $seed['assignment']->closeAt($this->date('2026-12-01'));
        new StaffAssignment($seed['employee'], $seed['new'], $seed['employment'], TeamRole::EMPLOYEE, $this->date('2026-12-10'));
        $at = $this->date('2026-12-07');

        foreach ([$seed['oldManager'], $seed['newManager']] as $actor) {
            self::assertSame([], $this->visibleDates($actor, $revision, $at));
            self::assertFalse($this->policy->canViewRevisionDetails($actor, $revision, $at));
            self::assertFalse($this->policy->canDecideRevision($actor, $revision, $at));
        }
        self::assertTrue($this->policy->canViewRevisionDetails($seed['employee'], $revision, $at));
        self::assertTrue($this->policy->canDecideRevision($seed['admin'], $revision, $at));
    }

    public function testInactiveActorsCannotUseOwnOrAdministratorExceptions(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-11-30']);
        $at = $this->date('2026-11-20');
        foreach ([$seed['employee'], $seed['admin'], $seed['oldManager']] as $actor) {
            $actor->deactivate();
            self::assertSame([], $this->visibleDates($actor, $revision, $at));
            self::assertFalse($this->policy->canViewRevisionDetails($actor, $revision, $at));
            self::assertFalse($this->policy->canDecideRevision($actor, $revision, $at));
        }
    }

    public function testChangeCannotUseFutureProposalToGainAccessToOldTeamBaseRevision(): void
    {
        $seed = $this->seed();
        $original = $this->request($seed['assignment'], $seed['rules'], ['2026-11-30']);
        new RequestDecision($original, DecisionOutcome::APPROVED, $this->event($seed['employee'], 'approve'));
        $newAssignment = $this->transfer($seed, '2026-12-01');
        $change = new RequestRevision(
            $original->getRequest(), RequestChangeKind::CHANGE, $seed['rules'], RequestMode::FULL_DAYS,
            $this->date('2026-12-07'), $this->date('2026-12-07'),
            [new RequestedDay($newAssignment, $this->date('2026-12-07'), 8)],
            $this->event($seed['employee'], 'change'), $original,
        );
        $at = $this->date('2026-12-02');

        self::assertSame(['2026-12-07'], $this->visibleDates($seed['newManager'], $change, $at));
        foreach ([$seed['oldManager'], $seed['newManager']] as $actor) {
            self::assertFalse($this->policy->canViewRevisionDetails($actor, $change, $at));
            self::assertFalse($this->policy->canDecideRevision($actor, $change, $at));
        }
        self::assertTrue($this->policy->canDecideRevision($seed['admin'], $change, $at));
        self::assertTrue($this->policy->canViewRevisionDetails($seed['employee'], $change, $at));
        self::assertSame($original, $original->getRequest()->getEffectiveRevision());
    }

    public function testEmptyHydratedRevisionFailsClosedEvenForAnAdministrator(): void
    {
        $seed = $this->seed();
        $revision = $this->request($seed['assignment'], $seed['rules'], ['2026-11-30']);
        (new \ReflectionProperty(RequestRevision::class, 'days'))->setValue($revision, new ArrayCollection());
        $at = $this->date('2026-11-20');

        self::assertSame([], $this->visibleDates($seed['admin'], $revision, $at));
        self::assertFalse($this->policy->canViewRevisionDetails($seed['admin'], $revision, $at));
        self::assertFalse($this->policy->canDecideRevision($seed['admin'], $revision, $at));
    }

    /** @return Seed */
    private function seed(): array
    {
        $old = new Team('old', 'Old team');
        $new = new Team('new', 'New team');
        $employment = new EmploymentType('uop', 'UoP', true);
        $employee = $this->staff('employee', TeamRole::EMPLOYEE, $old, $employment);
        $assignment = $employee->getAssignments()[0];
        $rules = (new AbsenceType('paid'))->createVersion('Paid leave', true, [$employment]);
        $oldManager = $this->staff('old_manager', TeamRole::MANAGER, $old, $employment);
        $newManager = $this->staff('new_manager', TeamRole::MANAGER, $new, $employment);
        $admin = new User('Test', 'Administrator', 'admin@example.test', true);

        return compact('employee', 'assignment', 'old', 'new', 'employment', 'rules', 'oldManager', 'newManager', 'admin');
    }

    /** @param Seed $seed */
    private function transfer(array $seed, string $date): StaffAssignment
    {
        $seed['assignment']->closeAt($this->date($date));

        return new StaffAssignment($seed['employee'], $seed['new'], $seed['employment'], TeamRole::EMPLOYEE, $this->date($date));
    }

    private function staff(string $name, TeamRole $role, Team $team, EmploymentType $employment): User
    {
        $user = new User('Test', $name, $name.'@example.test');
        new StaffAssignment($user, $team, $employment, $role, $this->date('2026-01-01'));

        return $user;
    }

    /** @param non-empty-list<string> $dates */
    private function request(StaffAssignment $assignment, AbsenceTypeVersion $rules, array $dates): RequestRevision
    {
        $request = new LeaveRequest($assignment->getUser(), $this->date('2026-11-01'));

        return new RequestRevision(
            $request, RequestChangeKind::INITIAL, $rules, RequestMode::FULL_DAYS,
            $this->date(min($dates)), $this->date(max($dates)),
            array_map(fn (string $date): RequestedDay => new RequestedDay($assignment, $this->date($date), 8), $dates),
            $this->event($assignment->getUser(), 'create'),
        );
    }

    /** @return list<string> */
    private function visibleDates(User $actor, RequestRevision $revision, \DateTimeImmutable $at): array
    {
        return array_map(static fn (RequestDay $day): string => $day->getDate()->format('Y-m-d'), $this->policy->visibleDays($actor, $revision, $at));
    }

    private function event(User $subject, string $key): AuditEvent
    {
        return new AuditEvent('access_test:'.$key, 'test.'.$key, $subject, $subject, $this->date('2026-11-01'));
    }

    private function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'));
    }
}
