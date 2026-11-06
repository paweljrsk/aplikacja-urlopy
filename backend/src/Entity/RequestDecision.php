<?php

namespace App\Entity;

use App\Enum\DecisionOutcome;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'request_decision')]
class RequestDecision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'decision')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private RequestRevision $revision;

    #[ORM\Column(length: 16, enumType: DecisionOutcome::class)]
    private DecisionOutcome $outcome;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private AuditEvent $event;

    public function __construct(RequestRevision $revision, DecisionOutcome $outcome, AuditEvent $event)
    {
        if ($event->getSubject() !== $revision->getRequest()->getUser() || null === $event->getActor()) {
            throw new \InvalidArgumentException('A decision needs an actor and must concern this employee.');
        }
        if ($event->getOccurredAt() < $revision->getSource()->getOccurredAt()) {
            throw new \InvalidArgumentException('A decision cannot precede its proposal.');
        }
        $this->revision = $revision;
        $this->outcome = $outcome;
        $this->event = $event;
        $revision->attachDecision($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRevision(): RequestRevision
    {
        return $this->revision;
    }

    public function getOutcome(): DecisionOutcome
    {
        return $this->outcome;
    }

    public function getEvent(): AuditEvent
    {
        return $this->event;
    }
}
