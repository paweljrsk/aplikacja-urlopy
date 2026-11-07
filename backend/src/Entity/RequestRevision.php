<?php

namespace App\Entity;

use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Model\DateValue;
use App\Model\RequestedDay;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'request_revision')]
#[ORM\UniqueConstraint(name: 'uniq_request_revision', columns: ['request_id', 'revision'])]
#[ORM\Index(name: 'idx_revision_range', columns: ['start_date', 'end_date'])]
class RequestRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'revisions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private LeaveRequest $request;

    #[ORM\Column]
    private int $revision;

    #[ORM\Column(length: 16, enumType: RequestChangeKind::class)]
    private RequestChangeKind $kind;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private AbsenceTypeVersion $policy;

    #[ORM\Column(length: 16, enumType: RequestMode::class)]
    private RequestMode $mode;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private AuditEvent $source;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?self $baseRevision;

    /** @var Collection<int, RequestDay> */
    #[ORM\OneToMany(mappedBy: 'revision', targetEntity: RequestDay::class, cascade: ['persist'])]
    #[ORM\OrderBy(['date' => 'ASC'])]
    private Collection $days;

    /** @var Collection<int, RequestAssignmentContext> */
    #[ORM\OneToMany(mappedBy: 'revision', targetEntity: RequestAssignmentContext::class, cascade: ['persist'])]
    private Collection $assignmentContexts;

    #[ORM\OneToOne(mappedBy: 'revision', targetEntity: RequestDecision::class, cascade: ['persist'])]
    private ?RequestDecision $decision = null;

    private bool $building = false;

    /** @param list<RequestedDay> $days */
    public function __construct(LeaveRequest $request, RequestChangeKind $kind, AbsenceTypeVersion $policy, RequestMode $mode, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate, array $days, AuditEvent $source, ?self $baseRevision = null, ?self $calendarSource = null)
    {
        $this->request = $request;
        $this->kind = $kind;
        $this->policy = $policy;
        $this->mode = $mode;
        $this->startDate = DateValue::local($startDate);
        $this->endDate = DateValue::local($endDate);
        $this->source = $source;
        $this->baseRevision = $baseRevision;
        $this->days = new ArrayCollection();
        $this->assignmentContexts = new ArrayCollection();
        if ($source->getSubject() !== $request->getUser()) {
            throw new \InvalidArgumentException('The source operation concerns a different employee.');
        }
        if ($this->startDate > $this->endDate || [] === $days) {
            throw new \InvalidArgumentException('A request needs an ordered date range and at least one working day.');
        }
        if (RequestMode::HOURLY === $mode && ($this->startDate != $this->endDate || 1 !== count($days))) {
            throw new \InvalidArgumentException('Hourly requests cover one calendar day only.');
        }
        $effective = $request->getEffectiveRevision();
        if (RequestChangeKind::INITIAL === $kind) {
            if (null !== $baseRevision || null !== $effective) {
                throw new \DomainException('An initial revision cannot replace an approved absence.');
            }
        } elseif (null === $baseRevision || $baseRevision !== $effective || $baseRevision->getRequest() !== $request) {
            throw new \DomainException('Changes and cancellations must refer to the effective approved revision.');
        }
        // Editing an existing application cannot silently adopt a changed rule for the same type.
        foreach (array_reverse($request->getRevisions()) as $prior) {
            if ($prior->getPolicy()->getType() === $policy->getType()) {
                if ($prior->getPolicy() !== $policy) {
                    throw new \DomainException('This application retains the captured version of this absence type.');
                }
                break;
            }
        }
        if (null !== $calendarSource) {
            if ($calendarSource->getRequest() !== $request || !\in_array($calendarSource, $request->getRevisions(), true)
                || $calendarSource->getStartDate()->format('Y-m-d') !== $this->startDate->format('Y-m-d')
                || $calendarSource->getEndDate()->format('Y-m-d') !== $this->endDate->format('Y-m-d')
                || $calendarSource->getPolicy() !== $policy || $calendarSource->getMode() !== $mode) {
                throw new \DomainException('A calendar correction must retain a previous revision’s range and rules.');
            }
            foreach ($calendarSource->getAssignmentContexts() as $context) {
                $this->assignmentContexts->add(new RequestAssignmentContext($this, $context->getAssignment(), $context->getValidFrom(), $context->getValidUntil()));
            }
            // Legacy revisions predate full-range context. Preserve their actual captured dates.
            if ([] === $calendarSource->getAssignmentContexts()) {
                foreach ($calendarSource->getDays() as $day) {
                    $this->assignmentContexts->add(new RequestAssignmentContext($this, $day->getAssignment(), $day->getDate(), $day->getDate()->modify('+1 day')));
                }
                foreach ($days as $day) {
                    if (!$this->preservesAssignment($day->assignment, $day->date)) {
                        $known = array_filter($calendarSource->getDays(), static fn (RequestDay $captured): bool => $captured->getAssignment() === $day->assignment);
                        if ([] === $known || $day->date->format('Y-m-d') < $day->assignment->getValidFrom()->format('Y-m-d')
                            || (null !== $day->assignment->getValidUntil() && $day->date->format('Y-m-d') >= $day->assignment->getValidUntil()->format('Y-m-d'))) {
                            throw new \DomainException('Legacy calendar correction lacks an unambiguous captured assignment.');
                        }
                        $this->assignmentContexts->add(new RequestAssignmentContext($this, $day->assignment, $day->date, $day->date->modify('+1 day')));
                    }
                }
            }
            foreach ($days as $day) {
                if (!$this->preservesAssignment($day->assignment, $day->date)) {
                    throw new \DomainException('A calendar correction must use the exact captured assignment for every date.');
                }
            }
        } else {
            foreach ($request->getUser()->getAssignments() as $assignment) {
                if ($assignment->getValidFrom()->format('Y-m-d') <= $this->endDate->format('Y-m-d')
                    && (null === $assignment->getValidUntil() || $assignment->getValidUntil()->format('Y-m-d') > $this->startDate->format('Y-m-d'))) {
                    $this->assignmentContexts->add(new RequestAssignmentContext($this, $assignment, $assignment->getValidFrom(), $assignment->getValidUntil()));
                }
            }
        }
        $this->building = true;
        try {
            foreach ($days as $day) {
                new RequestDay($this, $day->assignment, $day->date, $day->hours);
            }
        } finally {
            $this->building = false;
        }
        if (RequestChangeKind::CANCELLATION === $kind && !$this->hasSameContentAs($baseRevision)) {
            throw new \DomainException('Cancellation retains the exact content of the approved absence.');
        }
        $this->revision = $request->registerRevision($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): LeaveRequest
    {
        return $this->request;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getKind(): RequestChangeKind
    {
        return $this->kind;
    }

    public function getPolicy(): AbsenceTypeVersion
    {
        return $this->policy;
    }

    public function getMode(): RequestMode
    {
        return $this->mode;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getSource(): AuditEvent
    {
        return $this->source;
    }

    public function getBaseRevision(): ?self
    {
        return $this->baseRevision;
    }

    public function getDecision(): ?RequestDecision
    {
        return $this->decision;
    }

    /** @return list<RequestDay> */
    public function getDays(): array
    {
        return array_values($this->days->toArray());
    }

    public function getTotalHours(): int
    {
        return array_sum(array_map(static fn (RequestDay $day): int => $day->getHours(), $this->getDays()));
    }

    /** @return list<RequestAssignmentContext> */
    public function getAssignmentContexts(): array
    {
        return array_values($this->assignmentContexts->toArray());
    }

    public function preservesAssignment(StaffAssignment $assignment, \DateTimeImmutable $date): bool
    {
        foreach ($this->assignmentContexts as $context) {
            if ($context->getAssignment() === $assignment && $context->covers($date)) {
                return true;
            }
        }
        return false;
    }

    /** @internal The immutable daily snapshot is constructed with the revision. */
    public function registerDay(RequestDay $day): void
    {
        if (!$this->building || $day->getRevision() !== $this) {
            throw new \LogicException('Days cannot be appended to an existing request revision.');
        }
        foreach ($this->days as $existing) {
            if ($existing->getDate()->format('Y-m-d') === $day->getDate()->format('Y-m-d')) {
                throw new \InvalidArgumentException('A date can appear only once in a request revision.');
            }
        }
        $this->days->add($day);
    }

    /** @internal Persist the decision together with this aggregate and its accounting movements. */
    public function attachDecision(RequestDecision $decision): void
    {
        if ($decision->getRevision() !== $this || null !== $this->decision) {
            throw new \DomainException('A request revision can receive only one terminal decision.');
        }
        $this->request->recordChildMutation($this);
        $this->decision = $decision;
    }

    private function hasSameContentAs(?self $other): bool
    {
        if (null === $other || $this->policy !== $other->policy || $this->mode !== $other->mode
            || $this->startDate->format('Y-m-d') !== $other->startDate->format('Y-m-d')
            || $this->endDate->format('Y-m-d') !== $other->endDate->format('Y-m-d')) {
            return false;
        }
        $snapshot = static function (self $revision): array {
            $data = [];
            foreach ($revision->days as $day) {
                $data[$day->getDate()->format('Y-m-d')] = [$day->getHours(), $day->getAssignment()];
            }
            ksort($data);

            return $data;
        };

        return $snapshot($this) === $snapshot($other);
    }
}
