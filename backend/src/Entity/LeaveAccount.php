<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'leave_account')]
#[ORM\UniqueConstraint(name: 'uniq_leave_account_user_year', columns: ['user_id', 'leave_year'])]
class LeaveAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column]
    private int $movementRevision = 0;

    // Null is an unconfigured year; zero is an explicitly configured zero entitlement.
    #[ORM\Column(nullable: true)]
    private ?int $annualLimitHours = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\Column(name: 'leave_year')]
        private int $year,
    ) {
        if ($year < 1000 || $year > 9999) {
            throw new \InvalidArgumentException('The leave year must fit the supported calendar date range.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getMovementRevision(): int
    {
        return $this->movementRevision;
    }

    public function getAnnualLimitHours(): ?int
    {
        return $this->annualLimitHours;
    }

    public function setAnnualLimitHours(int $hours): void
    {
        if ($hours < 0 || $hours > 1000000) {
            throw new \InvalidArgumentException('An annual limit must be nonnegative whole hours within the supported range.');
        }
        $this->annualLimitHours = $hours;
        $this->touch();
    }

    /**
     * Marks the account for an optimistic-lock update when an immutable entry is added.
     * Flush the account, pool, event and entries in the same transaction.
     */
    public function touch(): void
    {
        ++$this->movementRevision;
    }
}
