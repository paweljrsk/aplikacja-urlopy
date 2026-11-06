<?php

namespace App\Entity;

use App\Enum\LedgerEntryKind;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ledger_entry')]
#[ORM\UniqueConstraint(name: 'uniq_ledger_event_line', columns: ['event_id', 'line_number'])]
#[ORM\Index(name: 'idx_ledger_account_pool', columns: ['account_id', 'pool_id'])]
class LedgerEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private LeaveAccount $account,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private HourPool $pool,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private AuditEvent $event,
        #[ORM\Column]
        private int $lineNumber,
        #[ORM\Column(length: 20, enumType: LedgerEntryKind::class)]
        private LedgerEntryKind $kind,
        #[ORM\Column]
        private int $allocatedDelta,
        #[ORM\Column]
        private int $reservedDelta,
        #[ORM\Column]
        private int $chargedDelta,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        private ?RequestDay $day = null,
    ) {
        if ($lineNumber < 1 || $lineNumber > 2147483647) {
            throw new \InvalidArgumentException('A ledger line number must be a positive integer within the storage range.');
        }
        if ($pool->getUser() !== $account->getUser() || $event->getSubject() !== $account->getUser()) {
            throw new \InvalidArgumentException('The account, originating pool and audit source must belong to the same employee.');
        }
        if ($pool->getOriginYear() > $account->getYear()) {
            throw new \InvalidArgumentException('A movement cannot use a pool originating after its leave year.');
        }
        if (LedgerEntryKind::OPENING_USED === $kind && $pool->getOriginYear() !== $account->getYear()) {
            throw new \InvalidArgumentException('Opening usage belongs only to the current annual pool, not to remaining carry.');
        }

        $this->validateDeltas();
        $this->validateDay();

        // Both updates and the immutable source/entry must be flushed atomically.
        $account->touch();
        $pool->touch();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): LeaveAccount
    {
        return $this->account;
    }

    public function getPool(): HourPool
    {
        return $this->pool;
    }

    public function getEvent(): AuditEvent
    {
        return $this->event;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function getKind(): LedgerEntryKind
    {
        return $this->kind;
    }

    public function getAllocatedDelta(): int
    {
        return $this->allocatedDelta;
    }

    public function getReservedDelta(): int
    {
        return $this->reservedDelta;
    }

    public function getChargedDelta(): int
    {
        return $this->chargedDelta;
    }

    public function getDay(): ?RequestDay
    {
        return $this->day;
    }

    private function validateDeltas(): void
    {
        foreach ([$this->allocatedDelta, $this->reservedDelta, $this->chargedDelta] as $amount) {
            if ($amount < -2147483647 || $amount > 2147483647) {
                throw new \InvalidArgumentException('Ledger deltas must fit the signed integer storage range.');
            }
        }

        $valid = match ($this->kind) {
            LedgerEntryKind::GRANT => $this->allocatedDelta > 0 && 0 === $this->reservedDelta && 0 === $this->chargedDelta,
            LedgerEntryKind::OPENING_USED => 0 === $this->allocatedDelta && 0 === $this->reservedDelta && $this->chargedDelta > 0,
            LedgerEntryKind::RESERVE => 0 === $this->allocatedDelta && $this->reservedDelta > 0 && 0 === $this->chargedDelta,
            LedgerEntryKind::RELEASE => 0 === $this->allocatedDelta && $this->reservedDelta < 0 && 0 === $this->chargedDelta,
            LedgerEntryKind::APPROVE => 0 === $this->allocatedDelta && $this->reservedDelta < 0 && $this->chargedDelta === -$this->reservedDelta,
            LedgerEntryKind::CANCEL => 0 === $this->allocatedDelta && 0 === $this->reservedDelta && $this->chargedDelta < 0,
            LedgerEntryKind::ADJUSTMENT => 0 !== $this->allocatedDelta || 0 !== $this->reservedDelta || 0 !== $this->chargedDelta,
        };

        if (!$valid) {
            throw new \InvalidArgumentException('Ledger deltas do not match the movement kind.');
        }
    }

    private function validateDay(): void
    {
        if (LedgerEntryKind::OPENING_USED === $this->kind && null !== $this->day) {
            throw new \InvalidArgumentException('Opening usage is an aggregate without a fabricated request day.');
        }
        $requiresDay = \in_array($this->kind, [LedgerEntryKind::RESERVE, LedgerEntryKind::RELEASE, LedgerEntryKind::APPROVE, LedgerEntryKind::CANCEL], true);
        if ($requiresDay && null === $this->day) {
            throw new \InvalidArgumentException('This movement requires a specific request day.');
        }

        if (null === $this->day) {
            return;
        }
        if ($this->day->getUser() !== $this->account->getUser()) {
            throw new \InvalidArgumentException('The request day must belong to the ledger employee.');
        }
        if ((int) $this->day->getDate()->format('Y') !== $this->account->getYear()) {
            throw new \InvalidArgumentException('The request day and leave account must belong to the same year.');
        }
        if (!$this->day->getRevision()->getPolicy()->deductsBalance()) {
            throw new \InvalidArgumentException('A non-deducting absence cannot produce a balance movement.');
        }
        foreach ([$this->allocatedDelta, $this->reservedDelta, $this->chargedDelta] as $amount) {
            if (abs($amount) > $this->day->getHours()) {
                throw new \InvalidArgumentException('A single day movement cannot exceed that request day\'s hours.');
            }
        }
    }
}
