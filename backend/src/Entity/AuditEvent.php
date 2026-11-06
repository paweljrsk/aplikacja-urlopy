<?php

namespace App\Entity;

use App\Model\DateValue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_event')]
#[ORM\Index(name: 'idx_audit_subject_time', columns: ['subject_id', 'occurred_at'])]
class AuditEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $operationKey;

    #[ORM\Column(length: 80)]
    private string $action;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $actor;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $subject;

    #[ORM\Column(length: 40)]
    private string $targetType;

    #[ORM\Column(length: 255)]
    private string $targetReference;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $beforeData;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $afterData;

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function __construct(string $operationKey, string $action, ?User $actor, ?User $subject, \DateTimeImmutable $occurredAt, array $before = [], array $after = [], ?string $targetType = null, ?string $targetReference = null)
    {
        if (1 !== preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $operationKey)) {
            throw new \InvalidArgumentException('An operation needs a stable key of 1–100 ASCII characters.');
        }
        if (1 !== preg_match('/^[a-z][a-z0-9_.]{0,79}$/D', $action)) {
            throw new \InvalidArgumentException('An audit action must be a stable action code.');
        }
        $targetType ??= 'user';
        $targetReference ??= $subject?->getEmail();
        if (1 !== preg_match('/^[a-z_]{1,40}$/D', $targetType) || null === $targetReference || '' === trim($targetReference) || mb_strlen($targetReference) > 255) {
            throw new \InvalidArgumentException('An audit event requires an explicit target, including global operations.');
        }
        $this->operationKey = $operationKey;
        $this->action = $action;
        $this->actor = $actor;
        $this->subject = $subject;
        $this->targetType = $targetType;
        $this->targetReference = $targetReference;
        $this->occurredAt = DateValue::utc($occurredAt);
        $this->beforeData = self::copyData($before);
        $this->afterData = self::copyData($after);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperationKey(): string
    {
        return $this->operationKey;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getSubject(): ?User
    {
        return $this->subject;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetReference(): string
    {
        return $this->targetReference;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @return array<string, mixed> */
    public function getBeforeData(): array
    {
        return $this->beforeData;
    }

    /** @return array<string, mixed> */
    public function getAfterData(): array
    {
        return $this->afterData;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function copyData(array $data): array
    {
        // JSON roundtrip detaches nested objects and references; getters return arrays by value.
        return array_map(static fn (mixed $value): mixed => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), $data);
    }
}
