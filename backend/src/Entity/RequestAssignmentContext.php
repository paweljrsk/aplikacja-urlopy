<?php

namespace App\Entity;

use App\Model\DateValue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Captures rules for the entire requested range, including initially excluded holidays. */
#[ORM\Entity]
#[ORM\Table(name: 'request_assignment_context')]
class RequestAssignmentContext
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'assignmentContexts')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private RequestRevision $revision,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private StaffAssignment $assignment,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $validFrom,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $validUntil,
    ) {
        $this->validFrom = DateValue::local($validFrom);
        $this->validUntil = null === $validUntil ? null : DateValue::local($validUntil);
        if ($assignment->getUser() !== $revision->getRequest()->getUser() || (null !== $validUntil && $validUntil <= $validFrom)) {
            throw new \InvalidArgumentException('Captured assignment must concern this employee and an ordered period.');
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getRevision(): RequestRevision { return $this->revision; }
    public function getAssignment(): StaffAssignment { return $this->assignment; }
    public function getValidFrom(): \DateTimeImmutable { return $this->validFrom; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }

    public function covers(\DateTimeImmutable $date): bool
    {
        $key = $date->format('Y-m-d');
        return $key >= $this->validFrom->format('Y-m-d') && (null === $this->validUntil || $key < $this->validUntil->format('Y-m-d'));
    }
}
