<?php

namespace App\Tests\Unit;

use App\Entity\EmploymentType;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Security\BalanceAccessPolicy;
use App\Security\CurrentAssignmentResolver;
use PHPUnit\Framework\TestCase;

final class BalanceAccessPolicyTest extends TestCase
{
    public function testRolesSeparateBalanceReadingFromChangingOwnAllowance(): void
    {
        $team = new Team('team', 'Team');
        $otherTeam = new Team('other', 'Other team');
        $employment = new EmploymentType('uop', 'UoP', true);
        $employee = $this->staff('employee', TeamRole::EMPLOYEE, $team, $employment);
        $peer = $this->staff('peer', TeamRole::EMPLOYEE, $team, $employment);
        $manager = $this->staff('manager', TeamRole::MANAGER, $team, $employment);
        $deputy = $this->staff('deputy', TeamRole::DEPUTY, $team, $employment);
        $other = $this->staff('other', TeamRole::EMPLOYEE, $otherTeam, $employment);
        $admin = new User('Test', 'Administrator', 'admin@example.test', true);
        $policy = new BalanceAccessPolicy(new CurrentAssignmentResolver());
        $now = $this->date('2026-11-07');

        self::assertTrue($policy->canViewBalance($employee, $employee, $now));
        self::assertFalse($policy->canViewBalance($employee, $peer, $now));
        self::assertFalse($policy->canManageBalance($employee, $employee, $now));
        self::assertTrue($policy->canViewBalance($manager, $employee, $now));
        self::assertTrue($policy->canManageBalance($manager, $employee, $now));
        self::assertFalse($policy->canManageBalance($manager, $manager, $now));
        self::assertTrue($policy->canViewBalance($deputy, $employee, $now));
        self::assertFalse($policy->canManageBalance($deputy, $employee, $now));
        self::assertFalse($policy->canViewBalance($manager, $other, $now));
        self::assertFalse($policy->canManageBalance($manager, $other, $now));
        self::assertTrue($policy->canManageBalance($admin, $admin, $now));
        self::assertTrue($policy->canManageBalance($admin, $other, $now));
        $admin->deactivate();
        $manager->deactivate();
        self::assertFalse($policy->canViewBalance($admin, $admin, $now));
        self::assertFalse($policy->canManageBalance($admin, $other, $now));
        self::assertFalse($policy->canManageBalance($manager, $employee, $now));
    }

    public function testTransferChangesAuthorityAtWarsawMidnightWithoutEarlyAccess(): void
    {
        $old = new Team('old', 'Old team');
        $new = new Team('new', 'New team');
        $employment = new EmploymentType('uop', 'UoP', true);
        $oldManager = $this->staff('old_manager', TeamRole::MANAGER, $old, $employment);
        $newManager = $this->staff('new_manager', TeamRole::MANAGER, $new, $employment);
        $employee = new User('Test', 'Employee', 'employee@example.test');
        new StaffAssignment($employee, $old, $employment, TeamRole::EMPLOYEE, $this->date('2025-01-01'), $this->date('2027-01-01'));
        new StaffAssignment($employee, $new, $employment, TeamRole::EMPLOYEE, $this->date('2027-01-01'));
        $policy = new BalanceAccessPolicy(new CurrentAssignmentResolver());
        $before = new \DateTimeImmutable('2026-12-31T22:59:59Z');
        $after = new \DateTimeImmutable('2026-12-31T23:00:00Z');
        self::assertTrue($policy->canManageBalance($oldManager, $employee, $before));
        self::assertFalse($policy->canManageBalance($newManager, $employee, $before));
        self::assertFalse($policy->canManageBalance($oldManager, $employee, $after));
        self::assertTrue($policy->canManageBalance($newManager, $employee, $after));
    }

    private function staff(string $name, TeamRole $role, Team $team, EmploymentType $employment): User
    {
        $user = new User('Test', $name, $name.'@example.test');
        new StaffAssignment($user, $team, $employment, $role, $this->date('2025-01-01'));

        return $user;
    }

    private function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'));
    }
}
