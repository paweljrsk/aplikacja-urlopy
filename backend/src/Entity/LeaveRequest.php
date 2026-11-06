<?php

namespace App\Entity;

use App\Enum\DecisionOutcome;
use App\Enum\RequestChangeKind;
use App\Model\DateValue;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'leave_request')]
class LeaveRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 32, unique: true)]
    private string $reference;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column]
    private int $mutationRevision = 0;

    /** @var Collection<int, RequestRevision> */
    #[ORM\OneToMany(mappedBy: 'request', targetEntity: RequestRevision::class, cascade: ['persist'])]
    #[ORM\OrderBy(['revision' => 'ASC'])]
    private Collection $revisions;

    public function __construct(User $user, \DateTimeImmutable $createdAt, ?string $reference = null)
    {
        $reference ??= bin2hex(random_bytes(16));
        if (1 !== preg_match('/^[a-f0-9]{32}$/D', $reference)) {
            throw new \InvalidArgumentException('A request reference must be 32 lowercase hexadecimal characters.');
        }
        $this->user = $user;
        $this->createdAt = DateValue::utc($createdAt);
        $this->reference = $reference;
        $this->revisions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return list<RequestRevision> */
    public function getRevisions(): array
    {
        return array_values($this->revisions->toArray());
    }

    public function getPendingRevision(): ?RequestRevision
    {
        foreach ($this->revisions as $revision) {
            if (null === $revision->getDecision()) {
                return $revision;
            }
        }

        return null;
    }

    public function getEffectiveRevision(): ?RequestRevision
    {
        $latest = null;
        foreach ($this->revisions as $revision) {
            if (DecisionOutcome::APPROVED === $revision->getDecision()?->getOutcome()
                && (null === $latest || $revision->getRevision() > $latest->getRevision())) {
                $latest = $revision;
            }
        }

        return RequestChangeKind::CANCELLATION === $latest?->getKind() ? null : $latest;
    }

    /** @internal Child creation and aggregate update must be flushed atomically. */
    public function registerRevision(RequestRevision $revision): int
    {
        if ($revision->getRequest() !== $this || $this->revisions->contains($revision)) {
            throw new \LogicException('A revision must be new and belong to this request.');
        }
        if (null !== $this->getPendingRevision()) {
            throw new \DomainException('A request can have only one pending revision.');
        }
        $sequence = 1;
        foreach ($this->revisions as $existing) {
            $sequence = max($sequence, $existing->getRevision() + 1);
        }
        $this->revisions->add($revision);
        ++$this->mutationRevision;

        return $sequence;
    }

    /** @internal A decision dirties the parent to serialize concurrent decisions/revisions. */
    public function recordChildMutation(RequestRevision $revision): void
    {
        if (!$this->revisions->contains($revision)) {
            throw new \LogicException('Unknown request revision.');
        }
        ++$this->mutationRevision;
    }
}
