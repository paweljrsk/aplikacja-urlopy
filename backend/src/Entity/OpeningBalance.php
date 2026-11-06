<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'opening_balance')]
class OpeningBalance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $effectiveDate;

    /** @var Collection<int, OpeningCarry> */
    #[ORM\OneToMany(mappedBy: 'openingBalance', targetEntity: OpeningCarry::class, cascade: ['persist'])]
    private Collection $carries;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        \DateTimeImmutable $effectiveDate,
        #[ORM\Column]
        private int $annualHours,
        #[ORM\Column]
        private int $currentYearUsedHours,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private AuditEvent $source,
    ) {
        if ('00:00:00.000000' !== $effectiveDate->format('H:i:s.u')) {
            throw new \InvalidArgumentException('The opening date must be a calendar date without a time of day.');
        }
        if ((int) $effectiveDate->format('Y') < 1000 || (int) $effectiveDate->format('Y') > 9999) {
            throw new \InvalidArgumentException('The opening date must fit the supported calendar date range.');
        }
        if ($annualHours < 0 || $annualHours > 2147483647 || $currentYearUsedHours < 0 || $currentYearUsedHours > 2147483647) {
            throw new \InvalidArgumentException('Opening amounts must be nonnegative whole hours within the storage range.');
        }
        if ($source->getSubject() !== $user) {
            throw new \InvalidArgumentException('The opening source must belong to the same employee.');
        }

        $this->effectiveDate = new \DateTimeImmutable($effectiveDate->format('Y-m-d'), new \DateTimeZone('Europe/Warsaw'));
        $this->carries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->effectiveDate;
    }

    public function getAnnualHours(): int
    {
        return $this->annualHours;
    }

    public function getCurrentYearUsedHours(): int
    {
        return $this->currentYearUsedHours;
    }

    public function getSource(): AuditEvent
    {
        return $this->source;
    }

    /** @return list<OpeningCarry> */
    public function getCarries(): array
    {
        return array_values($this->carries->toArray());
    }

    /** @internal Called only by OpeningCarry while assembling the initial snapshot. */
    public function registerCarry(OpeningCarry $carry): void
    {
        if ($carry->getOpeningBalance() !== $this) {
            throw new \InvalidArgumentException('The carry belongs to a different opening snapshot.');
        }
        if ($this->carries->contains($carry)) {
            return;
        }
        if (null !== $this->id) {
            throw new \LogicException('A persisted opening snapshot cannot receive additional carry rows.');
        }
        foreach ($this->carries as $existing) {
            if ($existing->getOriginYear() === $carry->getOriginYear()) {
                throw new \InvalidArgumentException('An opening snapshot already contains this origin year.');
            }
        }

        $this->carries->add($carry);
    }
}
