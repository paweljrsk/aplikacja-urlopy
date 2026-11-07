<?php

namespace App\Entity;

use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Model\DateValue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'request_day')]
#[ORM\UniqueConstraint(name: 'uniq_revision_date', columns: ['revision_id', 'date'])]
#[ORM\Index(name: 'idx_request_day_date', columns: ['date'])]
class RequestDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'days')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private RequestRevision $revision;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private StaffAssignment $assignment;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private int $hours;

    /** @internal Constructed by RequestRevision from RequestedDay inputs. */
    public function __construct(RequestRevision $revision, StaffAssignment $assignment, \DateTimeImmutable $date, int $hours)
    {
        $this->revision = $revision;
        $this->assignment = $assignment;
        $this->date = DateValue::local($date);
        $this->hours = $hours;
        $key = $this->date->format('Y-m-d');
        if ($assignment->getUser() !== $revision->getRequest()->getUser()) {
            throw new \InvalidArgumentException('A request day must belong to its employee.');
        }
        if ($key < $revision->getStartDate()->format('Y-m-d') || $key > $revision->getEndDate()->format('Y-m-d')) {
            throw new \InvalidArgumentException('A request day must be inside the requested range.');
        }
        // Cancellation copies an existing approved snapshot, even if its assignment was later closed.
        if (RequestChangeKind::CANCELLATION !== $revision->getKind() && !$revision->preservesAssignment($assignment, $this->date) && ($key < $assignment->getValidFrom()->format('Y-m-d')
            || (null !== $assignment->getValidUntil() && $key >= $assignment->getValidUntil()->format('Y-m-d')))) {
            throw new \InvalidArgumentException('The assignment must cover the date of absence.');
        }
        if (!$revision->getPolicy()->allowsEmploymentType($assignment->getEmploymentType())) {
            throw new \InvalidArgumentException('The captured policy does not allow this employment type.');
        }
        if ((RequestMode::FULL_DAYS === $revision->getMode() && 8 !== $hours)
            || (RequestMode::HOURLY === $revision->getMode() && ($hours < 1 || $hours > 7))) {
            throw new \InvalidArgumentException('Use 8 hours for a full day, or 1–7 for a single hourly day.');
        }
        $revision->registerDay($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRevision(): RequestRevision
    {
        return $this->revision;
    }

    public function getUser(): User
    {
        return $this->revision->getRequest()->getUser();
    }

    public function getAssignment(): StaffAssignment
    {
        return $this->assignment;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getHours(): int
    {
        return $this->hours;
    }
}
