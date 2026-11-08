<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<SecurityIdentity> */
final class IdentityProvider implements UserProviderInterface
{
    public function __construct(private EntityManagerInterface $em, private IdentityFactory $identities, private ClockInterface $clock, private PasswordHasherFactoryInterface $hashers)
    {
    }

    public function loadUserByIdentifier(string $identifier): SecurityIdentity
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => strtolower(trim($identifier))]);

        try {
            return $this->eligibleIdentity($user);
        } catch (UserNotFoundException $exception) {
            // The real-account path verifies a password hash. Do equivalent work for
            // an unavailable account, rather than exposing it through a fast exit.
            // Use the configured algorithm/cost; the result is discarded, never
            // persisted or usable as credentials. Symfony checks throttling and
            // CSRF before invoking this loader. Session refresh does not use it.
            $this->hashers->getPasswordHasher(SecurityIdentity::class)->hash('Unavailable account timing equalization');

            throw $exception;
        }
    }

    public function refreshUser(UserInterface $user): SecurityIdentity
    {
        if (!$user instanceof SecurityIdentity) {
            throw new UnsupportedUserException('Unsupported identity.');
        }

        // A new request reloads the entity through Doctrine, never trusting session roles.
        return $this->eligibleIdentity($this->em->find(User::class, $user->getId()));
    }

    public function supportsClass(string $class): bool
    {
        return SecurityIdentity::class === $class;
    }

    private function eligibleIdentity(?User $user): SecurityIdentity
    {
        if (null === $user || !$user->isActive() || null === $user->getPasswordHash()
            || ($user->mustChangePassword() && (null === $user->getTemporaryPasswordExpiresAt() || $user->getTemporaryPasswordExpiresAt() <= $this->clock->now()))) {
            throw new UserNotFoundException('Invalid credentials.');
        }

        return $this->identities->create($user);
    }
}
