<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\Model\DateValue;
use App\Security\SecurityIdentity;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

final class CredentialsService
{
    public const BOOTSTRAP_KEY = 'security.bootstrap.v1';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly ClockInterface $clock,
    ) {
    }

    public function changePassword(User $user, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): void
    {
        CredentialsPolicy::validateNewPassword($newPassword);
        $this->em->wrapInTransaction(function () use ($user, $currentPassword, $newPassword): void {
            $this->lockAndRefresh($user);
            $this->requireUsablePassword($user, $currentPassword);
            if ($this->hasher()->verify((string) $user->getPasswordHash(), $newPassword)) {
                throw new CredentialsException('VALIDATION_FAILED', 'Nowe hasło musi różnić się od obecnego.');
            }
            $beforeVersion = $user->getCredentialVersion();
            $user->replacePasswordHash($this->hasher()->hash($newPassword), false, null);
            $this->em->persist($this->audit('security.password_changed', $user, $user, $beforeVersion));
        });
    }

    /** @return array{temporaryPassword: string, expiresAt: \DateTimeImmutable} */
    public function resetPassword(User $actor, User $target, #[\SensitiveParameter] string $adminPassword): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $target, $adminPassword): array {
            $this->lockAndRefresh($actor, $target);
            if (!$actor->isActive() || !$actor->isAdministrator() || $actor->mustChangePassword()) {
                throw new CredentialsException('FORBIDDEN', 'Brak uprawnień do resetowania hasła.', 403);
            }
            if ($actor->getId() === $target->getId()) {
                throw new CredentialsException('VALIDATION_FAILED', 'Własne hasło zmień w formularzu zmiany hasła.');
            }
            $this->requireUsablePassword($actor, $adminPassword);
            if (!$target->isActive()) {
                throw new CredentialsException('VALIDATION_FAILED', 'Nie można ustawić hasła nieaktywnego konta.');
            }
            $beforeVersion = $target->getCredentialVersion();
            $result = $this->setTemporaryPassword($target);
            $this->em->persist($this->audit('security.password_reset', $actor, $target, $beforeVersion));

            return $result;
        });
    }

    /** @return array{user: User, temporaryPassword: string, expiresAt: \DateTimeImmutable} */
    public function bootstrapAdmin(string $email, string $firstName, string $lastName, bool $initializeExisting = false): array
    {
        return $this->em->wrapInTransaction(function () use ($email, $firstName, $lastName, $initializeExisting): array {
            if (null !== $this->em->getRepository(AuditEvent::class)->findOneBy(['operationKey' => self::BOOTSTRAP_KEY])) {
                throw new CredentialsException('BOOTSTRAP_UNAVAILABLE', 'Pierwszy administrator został już zainicjalizowany.');
            }
            $users = $this->em->getRepository(User::class);
            foreach ($users->findBy(['isAdministrator' => true]) as $administrator) {
                if (null !== $administrator->getPasswordHash()) {
                    throw new CredentialsException('BOOTSTRAP_UNAVAILABLE', 'Istnieje administrator z ustawionym hasłem. Bootstrap jest zablokowany.');
                }
            }

            $user = $users->findOneBy(['email' => strtolower(trim($email))]);
            if (null === $user) {
                $user = new User($firstName, $lastName, $email, true);
                $this->em->persist($user);
            } else {
                $this->lockAndRefresh($user);
                if (!$initializeExisting || !$user->isAdministrator() || !$user->isActive() || null !== $user->getPasswordHash()) {
                    throw new CredentialsException('BOOTSTRAP_UNAVAILABLE', 'Istniejące konto nie kwalifikuje się do inicjalizacji administratora.');
                }
            }

            $beforeVersion = $user->getCredentialVersion();
            $result = $this->setTemporaryPassword($user);
            // The unique operation key also serializes two concurrent bootstrap attempts.
            $this->em->persist($this->audit('security.admin_bootstrapped', null, $user, $beforeVersion, self::BOOTSTRAP_KEY));

            return ['user' => $user] + $result;
        });
    }

    /**
     * Recovery is exposed only by an interactive server-console command, never through a public route.
     *
     * @return array{user: User, temporaryPassword: string, expiresAt: \DateTimeImmutable}
     */
    public function recoverAdministrator(string $email): array
    {
        return $this->em->wrapInTransaction(function () use ($email): array {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => strtolower(trim($email))]);
            if (null === $user) {
                throw new CredentialsException('RECOVERY_UNAVAILABLE', 'Nie znaleziono aktywnego administratora z wcześniej ustawionym hasłem.');
            }
            $this->lockAndRefresh($user);
            if (!$user->isActive() || !$user->isAdministrator() || null === $user->getPasswordHash()) {
                throw new CredentialsException('RECOVERY_UNAVAILABLE', 'To konto nie kwalifikuje się do odzyskania dostępu administratora.');
            }
            $beforeVersion = $user->getCredentialVersion();
            $result = $this->setTemporaryPassword($user);
            $this->em->persist($this->audit('security.admin_recovered', null, $user, $beforeVersion));

            return ['user' => $user] + $result;
        });
    }

    private function requireUsablePassword(User $user, #[\SensitiveParameter] string $password): void
    {
        $hash = $user->getPasswordHash();
        $expired = $user->mustChangePassword() && (null === $user->getTemporaryPasswordExpiresAt() || $user->getTemporaryPasswordExpiresAt() <= $this->clock->now());
        if (!$user->isActive() || null === $hash || $expired || !$this->hasher()->verify($hash, $password)) {
            throw new CredentialsException('INVALID_CURRENT_PASSWORD', 'Nieprawidłowe obecne hasło.');
        }
    }

    /** @return array{temporaryPassword: string, expiresAt: \DateTimeImmutable} */
    private function setTemporaryPassword(User $user): array
    {
        $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $expires = DateValue::utc($this->clock->now())->modify('+24 hours');
        $user->replacePasswordHash($this->hasher()->hash($password), true, $expires);

        return ['temporaryPassword' => $password, 'expiresAt' => $expires];
    }

    private function hasher(): PasswordHasherInterface
    {
        return $this->hashers->getPasswordHasher(SecurityIdentity::class);
    }

    private function lockAndRefresh(User ...$users): void
    {
        $ordered = [];
        foreach ($users as $user) {
            if (null === $user->getId()) {
                throw new \LogicException('An existing account is required for this operation.');
            }
            $ordered[$user->getId()] = $user;
        }
        ksort($ordered);
        foreach ($ordered as $user) {
            $this->em->lock($user, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($user);
        }
    }

    private function audit(string $action, ?User $actor, User $subject, int $beforeVersion, ?string $operationKey = null): AuditEvent
    {
        return new AuditEvent(
            $operationKey ?? 'security.'.bin2hex(random_bytes(16)),
            $action,
            $actor,
            $subject,
            $this->clock->now(),
            ['credentialVersion' => $beforeVersion],
            ['credentialVersion' => $subject->getCredentialVersion(), 'mustChangePassword' => $subject->mustChangePassword()],
        );
    }
}
