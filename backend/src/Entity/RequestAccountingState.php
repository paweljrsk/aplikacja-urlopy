<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Rebuildable occupancy projection; immutable ledger entries retain the financial history. */
#[ORM\Entity]
#[ORM\Table(name: 'request_accounting_state')]
#[ORM\UniqueConstraint(name: 'UNIQ_ACCOUNTING_REQUEST', columns: ['request_id'])]
class RequestAccountingState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $occupancy = [];

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private LeaveRequest $request,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getRequest(): LeaveRequest { return $this->request; }

    /** @return array<string, int> */
    public function getOccupancy(): array { return $this->occupancy; }

    /** @param array<string, int> $occupancy */
    public function replaceOccupancy(array $occupancy): void
    {
        foreach ($occupancy as $date => $hours) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (false === $parsed || $parsed->format('Y-m-d') !== $date || $hours < 1 || $hours > 8) {
                throw new \InvalidArgumentException('Invalid accounting occupancy projection.');
            }
        }
        ksort($occupancy);
        $this->occupancy = $occupancy;
    }
}
