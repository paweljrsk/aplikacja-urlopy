<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Security\CurrentAssignmentResolver;
use App\Security\IdentityFactory;
use App\Security\IdentityProvider;
use App\Security\SecurityIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class IdentityProviderTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function unavailableAccounts(): iterable
    {
        yield 'unknown address' => ['unknown'];
        yield 'disabled account' => ['inactive'];
        yield 'account without password' => ['no-password'];
        yield 'expired initial password' => ['expired'];
    }

    #[DataProvider('unavailableAccounts')]
    public function testUnavailableLoginDoesNotSkipPasswordHashingCost(string $case): void
    {
        $user = 'unknown' === $case ? null : $this->user();
        if (null !== $user) {
            if ('no-password' !== $case) {
                $user->replacePasswordHash('synthetic-hash', 'expired' === $case,
                    'expired' === $case ? new \DateTimeImmutable('2026-11-01T00:00:00Z') : null);
            }
            if ('inactive' === $case) {
                $user->deactivate();
            }
        }
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findOneBy')->with(['email' => 'synthetic@example.test'])->willReturn($user);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(User::class)->willReturn($repository);
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hash')->with('Unavailable account timing equalization')->willReturn('discarded-synthetic-hash');
        $hashers = $this->createMock(PasswordHasherFactoryInterface::class);
        $hashers->expects(self::once())->method('getPasswordHasher')->with(SecurityIdentity::class)->willReturn($hasher);
        $clock = new MockClock('2026-11-08T12:00:00Z');
        $provider = new IdentityProvider($em, new IdentityFactory(new CurrentAssignmentResolver(), $clock), $clock, $hashers);

        // A call-count assertion is stable; wall-clock equality would be a flaky
        // unit test and would not establish constant-time HTTP behaviour anyway.
        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('Invalid credentials.');
        $provider->loadUserByIdentifier('  SYNTHETIC@example.test  ');
    }

    public function testEligibleLoginLeavesPasswordVerificationToSymfony(): void
    {
        $user = $this->user();
        $user->replacePasswordHash('synthetic-hash', false, null);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findOneBy')->willReturn($user);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->willReturn($repository);
        $hashers = $this->createMock(PasswordHasherFactoryInterface::class);
        $hashers->expects(self::never())->method('getPasswordHasher');
        $clock = new MockClock('2026-11-08T12:00:00Z');
        $provider = new IdentityProvider($em, new IdentityFactory(new CurrentAssignmentResolver(), $clock), $clock, $hashers);

        $identity = $provider->loadUserByIdentifier($user->getEmail());
        self::assertSame(1, $identity->getId());
        self::assertSame('synthetic-hash', $identity->getPassword());
    }

    public function testRefreshingRevokedSessionDoesNotPerformLoginHashWork(): void
    {
        $user = $this->user();
        $user->replacePasswordHash('synthetic-hash', false, null);
        $clock = new MockClock('2026-11-08T12:00:00Z');
        $factory = new IdentityFactory(new CurrentAssignmentResolver(), $clock);
        $snapshot = $factory->create($user);
        $user->deactivate();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('find')->with(User::class, 1)->willReturn($user);
        $hashers = $this->createMock(PasswordHasherFactoryInterface::class);
        $hashers->expects(self::never())->method('getPasswordHasher');
        $provider = new IdentityProvider($em, $factory, $clock, $hashers);

        $this->expectException(UserNotFoundException::class);
        $provider->refreshUser($snapshot);
    }

    private function user(): User
    {
        $user = new User('Synthetic', 'Employee', 'synthetic@example.test');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);

        return $user;
    }
}
