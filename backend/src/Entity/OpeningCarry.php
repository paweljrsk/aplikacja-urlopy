<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'opening_carry')]
#[ORM\UniqueConstraint(name: 'uniq_opening_carry_origin', columns: ['opening_balance_id', 'origin_year'])]
class OpeningCarry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'carries')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private OpeningBalance $openingBalance,
        #[ORM\Column]
        private int $originYear,
        #[ORM\Column]
        private int $remainingHours,
    ) {
        if ($originYear < 1000 || $originYear >= (int) $openingBalance->getEffectiveDate()->format('Y')) {
            throw new \InvalidArgumentException('An opening carry must originate before the opening year.');
        }
        if ($remainingHours < 0 || $remainingHours > 2147483647) {
            throw new \InvalidArgumentException('Remaining carry must be nonnegative whole hours within the storage range.');
        }

        $openingBalance->registerCarry($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOpeningBalance(): OpeningBalance
    {
        return $this->openingBalance;
    }

    public function getUser(): User
    {
        return $this->openingBalance->getUser();
    }

    public function getOriginYear(): int
    {
        return $this->originYear;
    }

    public function getRemainingHours(): int
    {
        return $this->remainingHours;
    }
}
