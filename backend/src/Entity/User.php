<?php

namespace App\Entity;

use App\Model\DateValue;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 254, unique: true)]
    private string $email;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private bool $isAdministrator;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column]
    private bool $mustChangePassword = true;

    #[ORM\Column(options: ['default' => 1])]
    private int $credentialVersion = 1;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $temporaryPasswordExpiresAt = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    // Dirty the aggregate when a child interval changes: Doctrine then checks its version.
    #[ORM\Column]
    private int $assignmentRevision = 0;

    /** @var Collection<int, StaffAssignment> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: StaffAssignment::class, cascade: ['persist'])]
    #[ORM\OrderBy(['validFrom' => 'ASC'])]
    private Collection $assignments;

    public function __construct(string $firstName, string $lastName, string $email, bool $isAdministrator = false)
    {
        $this->firstName = self::validateName($firstName);
        $this->lastName = self::validateName($lastName);
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid e-mail address of at most 254 characters is required.');
        }

        $this->email = $email;
        $this->isAdministrator = $isAdministrator;
        $this->assignments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isAdministrator(): bool
    {
        return $this->isAdministrator;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function getCredentialVersion(): int
    {
        return $this->credentialVersion;
    }

    public function getTemporaryPasswordExpiresAt(): ?\DateTimeImmutable
    {
        return $this->temporaryPasswordExpiresAt;
    }

    /** Replace only an encoded password; the application service owns plaintext validation and audit. */
    public function replacePasswordHash(string $hash, bool $mustChangePassword, ?\DateTimeImmutable $expiresAt): void
    {
        if (strlen($hash) > 255 || 'unknown' === password_get_info($hash)['algoName']) {
            throw new \InvalidArgumentException('An encoded supported password hash is required.');
        }
        if ($mustChangePassword !== (null !== $expiresAt)) {
            throw new \InvalidArgumentException('Only a temporary password has a required expiry time.');
        }
        $expiresAt = null === $expiresAt ? null : DateValue::utc($expiresAt);
        $this->advanceCredentialVersion();
        $this->passwordHash = $hash;
        $this->mustChangePassword = $mustChangePassword;
        $this->temporaryPasswordExpiresAt = $expiresAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getAssignmentRevision(): int
    {
        return $this->assignmentRevision;
    }

    /** @return list<StaffAssignment> */
    public function getAssignments(): array
    {
        return array_values($this->assignments->toArray());
    }

    /** @internal Called by StaffAssignment; persist changes in the same transaction as this aggregate. */
    public function registerAssignment(StaffAssignment $assignment): void
    {
        if ($assignment->getUser() !== $this) {
            throw new \InvalidArgumentException('The assignment belongs to a different employee.');
        }
        if ($this->assignments->contains($assignment)) {
            return;
        }

        $this->assertAssignmentPeriodAvailable($assignment, $assignment->getValidUntil());
        $this->assignments->add($assignment);
        ++$this->assignmentRevision;
    }

    /** @internal Called before changing an assignment interval. */
    public function assertAssignmentPeriodAvailable(StaffAssignment $assignment, ?\DateTimeImmutable $validUntil): void
    {
        foreach ($this->assignments as $existing) {
            if ($existing === $assignment) {
                continue;
            }

            // DATE columns have no timezone; compare calendar dates even after DBAL hydration.
            $endsAfterExistingStart = null === $validUntil || $validUntil->format('Y-m-d') > $existing->getValidFrom()->format('Y-m-d');
            $existingEndsAfterStart = null === $existing->getValidUntil() || $existing->getValidUntil()->format('Y-m-d') > $assignment->getValidFrom()->format('Y-m-d');
            if ($endsAfterExistingStart && $existingEndsAfterStart) {
                throw new \DomainException('An employee cannot have overlapping staff assignments.');
            }
        }
    }

    /** @internal Records a child mutation for aggregate optimistic locking. */
    public function markAssignmentChanged(StaffAssignment $assignment): void
    {
        if (!$this->assignments->contains($assignment)) {
            throw new \LogicException('The assignment is not registered on this employee.');
        }

        ++$this->assignmentRevision;
    }

    public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }
        $this->advanceCredentialVersion();
        $this->active = false;
    }

    private function advanceCredentialVersion(): void
    {
        if ($this->credentialVersion >= 2147483647) {
            throw new \OverflowException('Credential version exhausted.');
        }
        ++$this->credentialVersion;
    }

    private static function validateName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('An employee name must contain 1–100 characters.');
        }

        return $name;
    }
}
