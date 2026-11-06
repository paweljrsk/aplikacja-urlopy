<?php

namespace App\Tests\Unit;

use App\Entity\AbsenceType;
use App\Entity\EmploymentType;
use App\Entity\Holiday;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use PHPUnit\Framework\TestCase;

final class IdentityModelTest extends TestCase
{
    public function testEmailIsNormalizedBeforePersistence(): void
    {
        $user = new User(' Ada ', ' Testowa ', ' ADA.TESTOWA@EXAMPLE.TEST ');

        self::assertSame('ada.testowa@example.test', $user->getEmail());
        self::assertSame('Ada', $user->getFirstName());
        self::assertSame('Testowa', $user->getLastName());
        self::assertNull($user->getPasswordHash());
        self::assertTrue($user->mustChangePassword());
        self::assertFalse($user->isAdministrator());
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new User('Ada', 'Testowa', 'not-an-email');
    }

    public function testAdjacentAssignmentsPreserveEarlierTeamAndEmployment(): void
    {
        $user = $this->employee();
        $oldTeam = new Team('team_old', 'Zespół testowy A');
        $newTeam = new Team('team_new', 'Zespół testowy B');
        $employment = new EmploymentType('uop', 'Umowa o pracę', true);
        $contract = new EmploymentType('b2b', 'B2B', false);
        $first = new StaffAssignment($user, $oldTeam, $employment, TeamRole::EMPLOYEE, $this->date('2026-01-01'));
        $first->closeAt($this->date('2026-07-01'));
        $second = new StaffAssignment($user, $newTeam, $contract, TeamRole::MANAGER, $this->date('2026-07-01'));

        self::assertSame([$first, $second], $user->getAssignments());
        self::assertSame($oldTeam, $first->getTeam());
        self::assertSame($employment, $first->getEmploymentType());
        self::assertSame($newTeam, $second->getTeam());
        self::assertSame($contract, $second->getEmploymentType());
        self::assertSame(TeamRole::MANAGER, $second->getRole());
        self::assertSame('2026-07-01', $first->getValidUntil()?->format('Y-m-d'));
        self::assertSame(3, $user->getAssignmentRevision());
    }

    public function testOverlappingAssignmentDoesNotRegisterAnInvalidChild(): void
    {
        $user = $this->employee();
        $team = new Team('team', 'Zespół testowy');
        $employment = new EmploymentType('uop', 'Umowa o pracę', true);
        $first = new StaffAssignment($user, $team, $employment, TeamRole::EMPLOYEE, $this->date('2026-01-01'), $this->date('2026-07-01'));

        try {
            new StaffAssignment($user, $team, $employment, TeamRole::DEPUTY, $this->date('2026-06-30'));
            self::fail('Overlapping assignments must be rejected.');
        } catch (\DomainException) {
            self::assertSame([$first], $user->getAssignments());
            self::assertSame(1, $user->getAssignmentRevision());
        }
    }

    public function testAssignmentCloseIsIdempotentAndKeepsHistoricalBoundary(): void
    {
        $user = $this->employee();
        $assignment = new StaffAssignment($user, new Team('team', 'Zespół testowy'), new EmploymentType('uop', 'UoP', true), TeamRole::EMPLOYEE, $this->date('2026-01-01'));
        $assignment->closeAt($this->date('2026-07-01'));
        $assignment->closeAt($this->date('2026-07-01'));
        self::assertSame(2, $user->getAssignmentRevision());

        $this->expectException(\DomainException::class);
        $assignment->closeAt($this->date('2026-08-01'));
    }

    public function testAssignmentRejectsAnEmptyPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StaffAssignment($this->employee(), new Team('team', 'Zespół testowy'), new EmploymentType('uop', 'UoP', true), TeamRole::EMPLOYEE, $this->date('2026-07-01'), $this->date('2026-07-01'));
    }

    public function testDateInputCannotSilentlyDiscardHours(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Holiday(new \DateTimeImmutable('2026-11-11 12:00:00'), 'Testowy dzień wolny');
    }

    public function testAChangedPolicyDoesNotRewriteAnExistingVersion(): void
    {
        $employment = new EmploymentType('uop', 'Umowa o pracę', true);
        $contract = new EmploymentType('b2b', 'B2B', false);
        $type = new AbsenceType('paid');
        $original = $type->createVersion('Płatne dni wolne', true, [$employment, $contract, $employment]);
        $changed = $type->createVersion('Nowe zasady', false, [$employment]);
        $returnedTypes = $original->getEmploymentTypes();
        array_pop($returnedTypes);

        self::assertSame(1, $original->getRevision());
        self::assertSame(2, $changed->getRevision());
        self::assertSame(2, $type->getLatestRevision());
        self::assertTrue($original->deductsBalance());
        self::assertFalse($changed->deductsBalance());
        self::assertSame('Płatne dni wolne', $original->getName());
        self::assertTrue($original->allowsEmploymentType($contract));
        self::assertFalse($changed->allowsEmploymentType($contract));
        self::assertCount(2, $original->getEmploymentTypes());
    }

    public function testInvalidPolicyDoesNotAdvanceRevision(): void
    {
        $type = new AbsenceType('paid');

        try {
            $type->createVersion('Płatne dni wolne', true, []);
            self::fail('A version must apply to at least one employment type.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $type->getLatestRevision());
        }
    }

    public function testDeactivationDoesNotRemoveHistory(): void
    {
        $user = $this->employee();
        $team = new Team('team', 'Zespół testowy');
        $employment = new EmploymentType('uop', 'Umowa o pracę', true);
        $assignment = new StaffAssignment($user, $team, $employment, TeamRole::EMPLOYEE, $this->date('2026-01-01'));
        $type = new AbsenceType('paid');
        $version = $type->createVersion('Płatne dni wolne', true, [$employment]);

        $user->deactivate();
        $team->deactivate();
        $employment->deactivate();
        $type->deactivate();

        self::assertFalse($user->isActive());
        self::assertFalse($team->isActive());
        self::assertFalse($employment->isActive());
        self::assertFalse($type->isActive());
        self::assertSame([$assignment], $user->getAssignments());
        self::assertTrue($version->allowsEmploymentType($employment));
        self::assertTrue($version->deductsBalance());
    }

    private function employee(): User
    {
        return new User('Ada', 'Testowa', 'ada.testowa@example.test');
    }

    private function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'));
    }
}
