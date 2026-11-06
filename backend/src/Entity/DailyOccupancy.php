<?php

namespace App\Entity;

use App\Model\DateValue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'daily_occupancy')]
#[ORM\UniqueConstraint(name: 'uniq_user_occupancy_date', columns: ['user_id', 'date'])]
class DailyOccupancy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private int $hours = 0;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function __construct(User $user, \DateTimeImmutable $date)
    {
        $this->user = $user;
        $this->date = DateValue::local($date);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getHours(): int
    {
        return $this->hours;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    // Future workflow services update this projection atomically with request, ledger and audit.
    public function changeBy(int $delta): void
    {
        $hours = $this->hours + $delta;
        if ($hours < 0 || $hours > 8) {
            throw new \DomainException('Daily occupancy must remain between 0 and 8 whole hours.');
        }
        $this->hours = $hours;
    }
}
