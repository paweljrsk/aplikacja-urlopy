<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** A session snapshot, never a serialized Doctrine entity graph or password hash. */
#[Exclude]
final class SecurityIdentity implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** @param array{id: int, code: string, name: string}|null $team */
    public function __construct(
        private int $id,
        private string $email,
        private string $firstName,
        private string $lastName,
        private bool $active,
        private bool $administrator,
        private bool $mustChangePassword,
        private int $credentialVersion,
        private int $assignmentRevision,
        private string $assignmentFingerprint,
        private ?array $team,
        private ?string $role,
        private ?int $temporaryPasswordExpiresAt,
        #[\SensitiveParameter] private ?string $passwordHash = null,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isAdministrator(): bool
    {
        return $this->administrator;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function getTemporaryPasswordExpiresAt(): ?int
    {
        return $this->temporaryPasswordExpiresAt;
    }

    public function eraseCredentials(): void
    {
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->administrator ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }

    /** @return array{id: int, firstName: string, lastName: string, email: string, isAdministrator: bool, mustChangePassword: bool, team: array{id: int, code: string, name: string}|null, role: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'firstName' => $this->firstName, 'lastName' => $this->lastName, 'email' => $this->email, 'isAdministrator' => $this->administrator, 'mustChangePassword' => $this->mustChangePassword, 'team' => $this->team, 'role' => $this->role];
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $this->id === $user->id && $this->email === $user->email
            && $this->active === $user->active && $this->administrator === $user->administrator
            && $this->mustChangePassword === $user->mustChangePassword
            && $this->credentialVersion === $user->credentialVersion
            && $this->assignmentRevision === $user->assignmentRevision
            && $this->assignmentFingerprint === $user->assignmentFingerprint
            && $this->temporaryPasswordExpiresAt === $user->temporaryPasswordExpiresAt;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'firstName' => $this->firstName, 'lastName' => $this->lastName, 'active' => $this->active, 'administrator' => $this->administrator, 'mustChangePassword' => $this->mustChangePassword, 'credentialVersion' => $this->credentialVersion, 'assignmentRevision' => $this->assignmentRevision, 'assignmentFingerprint' => $this->assignmentFingerprint, 'team' => $this->team, 'role' => $this->role, 'temporaryPasswordExpiresAt' => $this->temporaryPasswordExpiresAt];
    }

    /** @param array{id: int, email: string, firstName: string, lastName: string, active: bool, administrator: bool, mustChangePassword: bool, credentialVersion: int, assignmentRevision: int, assignmentFingerprint: string, team: array{id: int, code: string, name: string}|null, role: string|null, temporaryPasswordExpiresAt: int|null} $data */
    public function __unserialize(array $data): void
    {
        $this->__construct(...$data);
    }
}
