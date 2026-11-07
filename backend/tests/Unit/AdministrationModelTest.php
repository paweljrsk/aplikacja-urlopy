<?php

namespace App\Tests\Unit;

use App\Entity\AbsenceType;
use App\Entity\EmploymentType;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Service\Admin\AdminInput;
use App\Service\CredentialsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdministrationModelTest extends TestCase
{
    public function testProfileValidationIsAtomicAndIdentityChangesRevokeSessions(): void
    {
        $user = new User('Synthetic', 'Employee', 'employee@example.test');
        $version = $user->getCredentialVersion();
        try {
            $user->updateProfile('Changed', 'Name', 'invalid', false, true);
            self::fail('Invalid email must fail before any field changes.');
        } catch (\InvalidArgumentException) {
            self::assertSame('Synthetic', $user->getFirstName());
            self::assertTrue($user->isActive());
            self::assertFalse($user->isAdministrator());
            self::assertSame($version, $user->getCredentialVersion());
        }
        $user->updateProfile('Changed', 'Name', 'employee@example.test', true, false);
        self::assertSame($version, $user->getCredentialVersion());
        $user->updateProfile('Changed', 'Name', 'renamed@example.test', true, true);
        self::assertSame($version + 1, $user->getCredentialVersion());
        $user->updateProfile('Changed', 'Name', 'renamed@example.test', false, true);
        $user->updateProfile('Changed', 'Name', 'renamed@example.test', true, true);
        self::assertSame($version + 3, $user->getCredentialVersion());
    }

    public function testAbsenceNamesStayOnOldVersionAndInvalidOverrideDoesNotAdvanceRevision(): void
    {
        $uop = new EmploymentType('uop', 'Umowa o pracę', true);
        (new \ReflectionProperty($uop, 'id'))->setValue($uop, 1);
        $type = new AbsenceType('paid');
        $old = $type->createVersion('Płatne dni', true, [$uop], ['1' => 'Urlop wypoczynkowy']);
        $new = $type->createVersion('Nowa nazwa', false, [$uop], ['1' => 'Nowa nazwa UoP']);
        self::assertSame('Urlop wypoczynkowy', $old->getNameFor($uop));
        self::assertTrue($old->deductsBalance());
        self::assertFalse($new->deductsBalance());
        try {
            $type->createVersion('Invalid', true, [$uop], ['99' => 'Niedozwolona forma']);
            self::fail('An override must refer to an allowed employment type.');
        } catch (\InvalidArgumentException) {
            self::assertSame(2, $type->getLatestRevision());
        }
    }

    public function testSameDayUnreferencedCorrectionInvalidatesAssignmentIdentity(): void
    {
        $user = new User('Synthetic', 'Employee', 'same-day@example.test');
        $team = new Team('one', 'One');
        $second = new Team('two', 'Two');
        $form = new EmploymentType('uop', 'Umowa o pracę', true);
        $assignment = new StaffAssignment($user, $team, $form, TeamRole::EMPLOYEE, new \DateTimeImmutable('2026-11-07'));
        $revision = $user->getAssignmentRevision();
        $assignment->correctUnreferenced($second, $form, TeamRole::MANAGER);
        self::assertSame($revision + 1, $user->getAssignmentRevision());
        self::assertSame($second, $assignment->getTeam());
        self::assertSame(TeamRole::MANAGER, $assignment->getRole());
        self::assertSame('2026-11-07', $assignment->getValidFrom()->format('Y-m-d'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'explicit null boolean' => [['active' => null], 'bool'];
        yield 'string boolean' => [['active' => 'false'], 'bool'];
        yield 'float id' => [['id' => 1.0], 'int'];
        yield 'string id' => [['id' => '1'], 'int'];
        yield 'invalid calendar day' => [['date' => '2026-02-30'], 'date'];
        yield 'nonpadded date' => [['date' => '2026-2-3'], 'date'];
        yield 'unknown privilege field' => [['passwordHash' => 'arbitrary'], 'only'];
        yield 'explicit null names' => [['namesByEmploymentType' => null], 'names'];
        yield 'invalid name key' => [['namesByEmploymentType' => ['team' => 'Name']], 'names'];
        yield 'list float IDs' => [['ids' => [1.0]], 'ids'];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidInputs')]
    public function testAdministrativeInputsRejectAmbiguousOrUnsupportedValues(array $data, string $method): void
    {
        $this->expectException(CredentialsException::class);
        match ($method) {
            'bool' => AdminInput::bool($data, 'active', true),
            'int' => AdminInput::int($data, 'id'),
            'date' => AdminInput::date($data, 'date'),
            'names' => AdminInput::names($data),
            'ids' => AdminInput::ids($data, 'ids'),
            default => AdminInput::only($data, ['firstName']),
        };
    }
}
