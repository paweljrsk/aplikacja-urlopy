<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'absence_type_version')]
#[ORM\UniqueConstraint(name: 'uniq_absence_type_revision', columns: ['type_id', 'revision'])]
class AbsenceTypeVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private AbsenceType $type;

    #[ORM\Column]
    private int $revision;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private bool $deductsBalance;

    /** @var Collection<int, EmploymentType> */
    #[ORM\ManyToMany(targetEntity: EmploymentType::class)]
    #[ORM\JoinTable(name: 'absence_type_availability')]
    #[ORM\JoinColumn(name: 'version_id', nullable: false, onDelete: 'RESTRICT')]
    #[ORM\InverseJoinColumn(name: 'employment_type_id', nullable: false, onDelete: 'RESTRICT')]
    private Collection $employmentTypes;

    /**
     * @internal create through AbsenceType::createVersion() to advance the aggregate version
     *
     * @param list<EmploymentType> $employmentTypes
     */
    public function __construct(AbsenceType $type, int $revision, string $name, bool $deductsBalance, array $employmentTypes)
    {
        if ($revision < 1) {
            throw new \InvalidArgumentException('An absence type revision must be positive.');
        }
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Absence type name must contain 1–255 characters.');
        }
        if ([] === $employmentTypes) {
            throw new \InvalidArgumentException('An absence type version must allow at least one employment type.');
        }

        $this->type = $type;
        $this->revision = $revision;
        $this->name = $name;
        $this->deductsBalance = $deductsBalance;
        $uniqueEmploymentTypes = [];
        foreach ($employmentTypes as $employmentType) {
            $uniqueEmploymentTypes[spl_object_id($employmentType)] = $employmentType;
        }
        $this->employmentTypes = new ArrayCollection(array_values($uniqueEmploymentTypes));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): AbsenceType
    {
        return $this->type;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function deductsBalance(): bool
    {
        return $this->deductsBalance;
    }

    /** @return list<EmploymentType> */
    public function getEmploymentTypes(): array
    {
        return array_values($this->employmentTypes->toArray());
    }

    public function allowsEmploymentType(EmploymentType $employmentType): bool
    {
        return $this->employmentTypes->contains($employmentType);
    }
}
