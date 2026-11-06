<?php

namespace App\Tests\Unit;

use App\Entity\EmploymentType;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Security\AccessPolicy;
use App\Security\CurrentAssignmentResolver;
use App\Security\SecurityIdentity;
use App\Security\SecuritySession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SecurityCoreTest extends TestCase
{
    public function testSessionSerializationOmitsPasswordHashAndEntityGraph(): void
    {
        $identity = $this->identity();
        $serialized = serialize($identity);
        self::assertStringNotContainsString('secret-password-hash', $serialized);
        self::assertStringNotContainsString('passwordHash', $serialized);
        self::assertStringNotContainsString('App\\Entity', $serialized);
        $restored = unserialize($serialized, ['allowed_classes' => [SecurityIdentity::class]]);
        self::assertInstanceOf(SecurityIdentity::class, $restored);
        self::assertNull($restored->getPassword());
        self::assertTrue($restored->isEqualTo($identity));
        self::assertSame($identity->toArray(), $restored->toArray());
    }

    public function testSecurityChangesInvalidateTheSessionSnapshot(): void
    {
        $original = $this->identity();
        foreach ([['credentialVersion' => 2], ['assignmentRevision' => 2], ['assignmentFingerprint' => '2:2:deputy'], ['administrator' => true], ['active' => false], ['mustChangePassword' => true], ['temporaryPasswordExpiresAt' => 1800000000]] as $change) {
            self::assertFalse($original->isEqualTo($this->identity($change)));
        }
        self::assertTrue($original->isEqualTo($this->identity(['firstName' => 'Updated'])));
    }

    public function testIdleSessionExpiresAtSixtyMinutes(): void
    {
        $clock = new MockClock('2026-11-06T12:00:00+00:00');
        $security = new SecuritySession($clock);
        $session = new Session(new MockArraySessionStorage());
        self::assertTrue($security->hasExpired($session));
        $security->start($session);
        $clock->sleep(3599);
        self::assertFalse($security->hasExpired($session));
        $clock->sleep(1);
        self::assertTrue($security->hasExpired($session));
    }

    public function testActivityCannotExtendTheAbsoluteEightHourLimit(): void
    {
        $clock = new MockClock('2026-11-06T12:00:00+00:00');
        $security = new SecuritySession($clock);
        $session = new Session(new MockArraySessionStorage());
        $security->start($session);
        for ($index = 0; $index < 15; ++$index) {
            $clock->sleep(1800);
            self::assertFalse($security->hasExpired($session));
            $security->touch($session);
        }
        $clock->sleep(1800);
        self::assertTrue($security->hasExpired($session));
    }

    public function testMembershipChangesAtWarsawMidnightWithExclusiveEnd(): void
    {
        $user = new User('Test', 'Employee', 'employee@example.test');
        $team = new Team('team', 'Team');
        $employment = new EmploymentType('uop', 'UoP', true);
        $first = new StaffAssignment($user, $team, $employment, TeamRole::EMPLOYEE, $this->date('2026-01-01'), $this->date('2026-11-07'));
        $second = new StaffAssignment($user, $team, $employment, TeamRole::MANAGER, $this->date('2026-11-07'));
        $resolver = new CurrentAssignmentResolver();
        self::assertSame($first, $resolver->resolve($user, new \DateTimeImmutable('2026-11-06T22:59:59Z')));
        self::assertSame($second, $resolver->resolve($user, new \DateTimeImmutable('2026-11-06T23:00:00Z')));
    }

    public function testCurrentTeamPermissionsAndSelfDecisionRules(): void
    {
        $team = new Team('team', 'Team');
        $otherTeam = new Team('other', 'Other');
        $employment = new EmploymentType('uop', 'UoP', true);
        $employee = $this->staff('employee', TeamRole::EMPLOYEE, $team, $employment);
        $peer = $this->staff('peer', TeamRole::EMPLOYEE, $team, $employment);
        $manager = $this->staff('manager', TeamRole::MANAGER, $team, $employment);
        $deputy = $this->staff('deputy', TeamRole::DEPUTY, $team, $employment);
        $other = $this->staff('other', TeamRole::MANAGER, $otherTeam, $employment);
        $admin = new User('Test', 'Admin', 'admin@example.test', true);
        $policy = new AccessPolicy(new CurrentAssignmentResolver());
        $today = $this->date('2026-11-06');

        self::assertTrue($policy->canViewProfile($employee, $employee, $today));
        self::assertFalse($policy->canViewProfile($employee, $peer, $today));
        self::assertTrue($policy->canViewProfile($manager, $employee, $today));
        self::assertTrue($policy->canViewProfile($deputy, $employee, $today));
        self::assertFalse($policy->canViewProfile($other, $employee, $today));
        self::assertTrue($policy->canViewProfile($admin, $other, $today));
        self::assertFalse($policy->canDecideFor($employee, $employee, $today));
        self::assertTrue($policy->canDecideFor($manager, $manager, $today));
        self::assertTrue($policy->canDecideFor($manager, $employee, $today));
        self::assertFalse($policy->canDecideFor($deputy, $deputy, $today));
        self::assertTrue($policy->canDecideFor($deputy, $employee, $today));
        self::assertFalse($policy->canDecideFor($other, $employee, $today));
        self::assertTrue($policy->canDecideFor($admin, $admin, $today));
        self::assertTrue($policy->canDecideFor($admin, $other, $today));
        $manager->deactivate();
        self::assertFalse($policy->canViewProfile($manager, $employee, $today));
        self::assertFalse($policy->canDecideFor($manager, $employee, $today));
    }

    /** @param array<string, mixed> $overrides */
    private function identity(array $overrides = []): SecurityIdentity
    {
        return new SecurityIdentity(...array_replace(['id' => 1, 'email' => 'employee@example.test', 'firstName' => 'Test', 'lastName' => 'Employee', 'active' => true, 'administrator' => false, 'mustChangePassword' => false, 'credentialVersion' => 1, 'assignmentRevision' => 1, 'assignmentFingerprint' => '1:1:employee', 'team' => ['id' => 1, 'code' => 'team', 'name' => 'Team'], 'role' => 'employee', 'temporaryPasswordExpiresAt' => null, 'passwordHash' => 'secret-password-hash'], $overrides));
    }

    private function staff(string $name, TeamRole $role, Team $team, EmploymentType $employment): User
    {
        $user = new User('Test', $name, $name.'@example.test');
        new StaffAssignment($user, $team, $employment, $role, $this->date('2026-01-01'));

        return $user;
    }

    private function date(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Warsaw'));
    }
}
