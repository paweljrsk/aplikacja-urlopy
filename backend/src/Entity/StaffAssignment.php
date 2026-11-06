<?php

namespace App\Entity;

use App\Enum\TeamRole;
use App\Model\DateValue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'staff_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_staff_assignment_start', columns: ['user_id', 'valid_from'])]
#[ORM\Index(name: 'idx_staff_assignment_team_period', columns: ['team_id', 'valid_from', 'valid_until'])]
class StaffAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Team $team;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private EmploymentType $employmentType;

    #[ORM\Column(length: 16, enumType: TeamRole::class)]
    private TeamRole $role;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil;

    public function __construct(User $user, Team $team, EmploymentType $employmentType, TeamRole $role, \DateTimeImmutable $validFrom, ?\DateTimeImmutable $validUntil = null)
    {
        $this->user = $user;
        $this->team = $team;
        $this->employmentType = $employmentType;
        $this->role = $role;
        $this->validFrom = DateValue::local($validFrom);
        $this->validUntil = null === $validUntil ? null : DateValue::local($validUntil);
        if (null !== $this->validUntil && $this->validUntil <= $this->validFrom) {
            throw new \InvalidArgumentException('Assignment end must follow its start; the end date is exclusive.');
        }

        $user->registerAssignment($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getEmploymentType(): EmploymentType
    {
        return $this->employmentType;
    }

    public function getRole(): TeamRole
    {
        return $this->role;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function closeAt(\DateTimeImmutable $exclusiveEnd): void
    {
        $exclusiveEnd = DateValue::local($exclusiveEnd);
        if ($exclusiveEnd->format('Y-m-d') <= $this->validFrom->format('Y-m-d')) {
            throw new \InvalidArgumentException('Assignment end must follow its start.');
        }
        if ($this->validUntil?->format('Y-m-d') === $exclusiveEnd->format('Y-m-d')) {
            return;
        }
        if (null !== $this->validUntil) {
            throw new \DomainException('An already closed assignment retains its historical end date.');
        }

        $this->user->assertAssignmentPeriodAvailable($this, $exclusiveEnd);
        $this->user->markAssignmentChanged($this);
        $this->validUntil = $exclusiveEnd;
    }
}
