<?php

namespace App\Tests\Unit;

use App\Command\BootstrapAdminCommand;
use App\Command\RecoverAdminCommand;
use App\Entity\AuditEvent;
use App\Entity\User;
use App\Service\CredentialsException;
use App\Service\CredentialsPolicy;
use App\Service\CredentialsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class CredentialsTest extends TestCase
{
    private const OLD_PASSWORD = 'Synthetic original password';
    private const NEW_PASSWORD = 'Synthetic replacement password';

    public function testHashMutationRejectsPlaintextAndPreservesOriginalState(): void
    {
        $user = new User('Synthetic', 'Employee', 'synthetic@example.test');
        try {
            $user->replacePasswordHash('this-is-plaintext', false, null);
            self::fail('Plaintext must not be accepted as an encoded hash.');
        } catch (\InvalidArgumentException) {
            self::assertNull($user->getPasswordHash());
            self::assertSame(1, $user->getCredentialVersion());
        }
    }

    public function testTemporaryPasswordRequiresExpiryAndNormalizesItToUtc(): void
    {
        $user = new User('Synthetic', 'Employee', 'synthetic@example.test');
        $hash = $this->hasher()->hash(self::OLD_PASSWORD);
        $expiry = new \DateTimeImmutable('2026-11-07 12:30:00.123456', new \DateTimeZone('Europe/Warsaw'));
        $user->replacePasswordHash($hash, true, $expiry);
        self::assertSame(2, $user->getCredentialVersion());
        self::assertTrue($user->mustChangePassword());
        self::assertSame('2026-11-07 11:30:00.000000 UTC', $user->getTemporaryPasswordExpiresAt()?->format('Y-m-d H:i:s.u T'));
        $user->replacePasswordHash($hash, false, null);
        self::assertSame(3, $user->getCredentialVersion());
        self::assertFalse($user->mustChangePassword());
        self::assertNull($user->getTemporaryPasswordExpiresAt());
    }

    public function testDeactivationRevokesOldSessionsPermanentlyAndIsIdempotent(): void
    {
        $user = $this->user(1);
        $before = $user->getCredentialVersion();
        $user->deactivate();
        self::assertFalse($user->isActive());
        self::assertSame($before + 1, $user->getCredentialVersion());
        $user->deactivate();
        self::assertSame($before + 1, $user->getCredentialVersion());
    }

    /** @return iterable<string, array{bool, ?\DateTimeImmutable}> */
    public static function inconsistentExpiryCases(): iterable
    {
        yield 'temporary without expiry' => [true, null];
        yield 'permanent with expiry' => [false, new \DateTimeImmutable('2026-11-07 12:00:00 UTC')];
    }

    #[DataProvider('inconsistentExpiryCases')]
    public function testHashMutationRejectsInconsistentExpiry(bool $temporary, ?\DateTimeImmutable $expiry): void
    {
        $user = new User('Synthetic', 'Employee', 'synthetic@example.test');
        $this->expectException(\InvalidArgumentException::class);
        $user->replacePasswordHash($this->hasher()->hash(self::OLD_PASSWORD), $temporary, $expiry);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNewPasswords(): iterable
    {
        yield 'too short' => [str_repeat('a', 14)];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'invalid utf8' => [str_repeat('a', 15)."\xff"];
        yield 'null byte' => ['synthetic password'."\0"];
        yield 'whitespace only' => [str_repeat(' ', 15)];
    }

    #[DataProvider('invalidNewPasswords')]
    public function testPasswordPolicyRejectsUnsupportedInput(string $password): void
    {
        $this->expectException(CredentialsException::class);
        CredentialsPolicy::validateNewPassword($password);
    }

    public function testPasswordPolicyAcceptsLongPassphrasesAndCountsUnicodeCharacters(): void
    {
        CredentialsPolicy::validateNewPassword('długa fraza bez cyfr i symboli');
        CredentialsPolicy::validateNewPassword(str_repeat('ą', 128));
        $this->addToAssertionCount(2);
    }

    public function testPasswordChangeHashesNewSecretAndAuditsOnlyNonsecretMetadata(): void
    {
        $user = $this->user(1);
        $oldHash = $user->getPasswordHash();
        $oldVersion = $user->getCredentialVersion();
        $events = [];
        $service = $this->service($events);
        $service->changePassword($user, self::OLD_PASSWORD, self::NEW_PASSWORD);

        self::assertNotSame($oldHash, $user->getPasswordHash());
        self::assertTrue($this->hasher()->verify((string) $user->getPasswordHash(), self::NEW_PASSWORD));
        self::assertSame($oldVersion + 1, $user->getCredentialVersion());
        self::assertFalse($user->mustChangePassword());
        self::assertCount(1, $events);
        self::assertSame('security.password_changed', $events[0]->getAction());
        $record = json_encode([$events[0]->getBeforeData(), $events[0]->getAfterData()], JSON_THROW_ON_ERROR);
        foreach ([self::OLD_PASSWORD, self::NEW_PASSWORD, $oldHash, $user->getPasswordHash()] as $secret) {
            self::assertNotNull($secret);
            self::assertStringNotContainsString($secret, $record);
        }
    }

    public function testWrongCurrentPasswordLeavesCredentialsAndAuditUntouched(): void
    {
        $user = $this->user(1);
        $oldHash = $user->getPasswordHash();
        $events = [];
        try {
            $this->service($events)->changePassword($user, 'Incorrect current password', self::NEW_PASSWORD);
            self::fail('An incorrect current password must be rejected.');
        } catch (CredentialsException $exception) {
            self::assertSame('INVALID_CURRENT_PASSWORD', $exception->errorCode);
            self::assertSame($oldHash, $user->getPasswordHash());
            self::assertSame([], $events);
        }
    }

    public function testExpiredTemporaryPasswordCannotBeChanged(): void
    {
        $user = $this->user(1);
        $user->replacePasswordHash($this->hasher()->hash(self::OLD_PASSWORD), true, new \DateTimeImmutable('2026-11-06 12:00:00 UTC'));
        $events = [];
        $this->expectException(CredentialsException::class);
        $this->service($events)->changePassword($user, self::OLD_PASSWORD, self::NEW_PASSWORD);
    }

    public function testNewPasswordMustDifferFromCurrent(): void
    {
        $user = $this->user(1);
        $events = [];
        $this->expectException(CredentialsException::class);
        $this->service($events)->changePassword($user, self::OLD_PASSWORD, self::OLD_PASSWORD);
    }

    public function testAdminResetCreatesRandomExpiringCredentialAndOneAudit(): void
    {
        $actor = $this->user(1, true);
        $target = $this->user(2);
        $version = $target->getCredentialVersion();
        $events = [];
        $result = $this->service($events)->resetPassword($actor, $target, self::OLD_PASSWORD);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/D', $result['temporaryPassword']);
        self::assertTrue($this->hasher()->verify((string) $target->getPasswordHash(), $result['temporaryPassword']));
        self::assertSame('2026-11-07 12:00:00 UTC', $result['expiresAt']->format('Y-m-d H:i:s T'));
        self::assertTrue($target->mustChangePassword());
        self::assertSame($version + 1, $target->getCredentialVersion());
        self::assertCount(1, $events);
        self::assertSame($actor, $events[0]->getActor());
        self::assertSame($target, $events[0]->getSubject());
        self::assertStringNotContainsString($result['temporaryPassword'], json_encode($events[0]->getAfterData(), JSON_THROW_ON_ERROR));
    }

    public function testNonAdministratorCannotResetCredentials(): void
    {
        $events = [];
        $this->expectException(CredentialsException::class);
        $this->service($events)->resetPassword($this->user(1), $this->user(2), self::OLD_PASSWORD);
    }

    public function testTemporaryPasswordLastsTwentyFourHoursAcrossDstChange(): void
    {
        $clock = new MockClock('2026-10-24 12:00:00 Europe/Warsaw');
        $events = [];
        $result = $this->service($events, $clock)->resetPassword($this->user(1, true), $this->user(2), self::OLD_PASSWORD);
        self::assertSame(86400, $result['expiresAt']->getTimestamp() - $clock->now()->getTimestamp());
        self::assertSame('UTC', $result['expiresAt']->getTimezone()->getName());
    }

    public function testAdministratorCannotUseResetForOwnAccount(): void
    {
        $actor = $this->user(1, true);
        $events = [];
        $this->expectException(CredentialsException::class);
        $this->service($events)->resetPassword($actor, $actor, self::OLD_PASSWORD);
    }

    public function testBootstrapCommandRequiresInteractiveInputBeforeDatabaseAccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $hashers = $this->createStub(PasswordHasherFactoryInterface::class);
        $command = new CommandTester(new BootstrapAdminCommand(new CredentialsService($em, $hashers, new MockClock()), $em));
        self::assertSame(Command::FAILURE, $command->execute([], ['interactive' => false]));
        self::assertStringContainsString('interaktywnie', $command->getDisplay());
    }

    public function testRecoveryCommandRequiresInteractiveInputBeforeDatabaseAccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');
        $hashers = $this->createStub(PasswordHasherFactoryInterface::class);
        $command = new CommandTester(new RecoverAdminCommand(new CredentialsService($em, $hashers, new MockClock())));
        self::assertSame(Command::FAILURE, $command->execute([], ['interactive' => false]));
        self::assertStringContainsString('interaktywnego', $command->getDisplay());
    }

    public function testRecoveryCommandDefaultsToRefusingWithoutConfirmation(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');
        $hashers = $this->createStub(PasswordHasherFactoryInterface::class);
        $command = new CommandTester(new RecoverAdminCommand(new CredentialsService($em, $hashers, new MockClock())));
        $command->setInputs(['synthetic@example.test', '']);
        self::assertSame(Command::FAILURE, $command->execute([], ['interactive' => true]));
        self::assertStringContainsString('Anulowano', $command->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function unsuitableRecoveryTargets(): iterable
    {
        yield 'missing account' => ['missing'];
        yield 'employee account' => ['employee'];
        yield 'inactive admin' => ['inactive'];
        yield 'admin never initialized' => ['uninitialized'];
    }

    #[DataProvider('unsuitableRecoveryTargets')]
    public function testRecoveryRefusesUnsuitableTargets(string $kind): void
    {
        $user = match ($kind) {
            'missing' => null,
            'employee' => $this->user(1),
            'inactive' => $this->user(1, true),
            default => new User('Synthetic', 'Administrator', 'synthetic1@example.test', true),
        };
        if ('inactive' === $kind) {
            $user->deactivate();
        }
        if ('uninitialized' === $kind) {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        }
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): mixed => $work());
        $em->expects(self::never())->method('persist');
        $em->method('getRepository')->willReturn($repository);
        $hashers = $this->createStub(PasswordHasherFactoryInterface::class);
        $service = new CredentialsService($em, $hashers, new MockClock('2026-11-06 12:00:00 UTC'));

        $this->expectException(CredentialsException::class);
        $service->recoverAdministrator('synthetic1@example.test');
    }

    public function testBootstrapRefusesAnExistingAdminEvenIfInactive(): void
    {
        $administrator = $this->user(1, true);
        $administrator->deactivate();
        $userRepository = $this->createStub(EntityRepository::class);
        $userRepository->method('findBy')->willReturn([$administrator]);
        $auditRepository = $this->createStub(EntityRepository::class);
        $auditRepository->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): mixed => $work());
        $em->expects(self::never())->method('persist');
        $em->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => AuditEvent::class === $class ? $auditRepository : $userRepository);
        $hashers = $this->createStub(PasswordHasherFactoryInterface::class);
        $service = new CredentialsService($em, $hashers, new MockClock('2026-11-06 12:00:00 UTC'));

        try {
            $service->bootstrapAdmin('new@example.test', 'Synthetic', 'Administrator');
            self::fail('Bootstrap must not replace an established administrator.');
        } catch (CredentialsException $exception) {
            self::assertSame('BOOTSTRAP_UNAVAILABLE', $exception->errorCode);
        }
    }

    private function user(int $id, bool $administrator = false): User
    {
        $user = new User('Synthetic', 'Employee', 'synthetic'.$id.'@example.test', $administrator);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->replacePasswordHash($this->hasher()->hash(self::OLD_PASSWORD), false, null);

        return $user;
    }

    private function hasher(): NativePasswordHasher
    {
        return new NativePasswordHasher(cost: 4, algorithm: \PASSWORD_BCRYPT);
    }

    /** @param list<AuditEvent> $events */
    private function service(array &$events, ?MockClock $clock = null): CredentialsService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): mixed => $work());
        $em->method('persist')->willReturnCallback(static function (object $event) use (&$events): void {
            self::assertInstanceOf(AuditEvent::class, $event);
            $events[] = $event;
        });
        $hashers = $this->createStub(PasswordHasherFactoryInterface::class);
        $hashers->method('getPasswordHasher')->willReturn($this->hasher());

        return new CredentialsService($em, $hashers, $clock ?? new MockClock('2026-11-06 12:00:00 UTC'));
    }
}
