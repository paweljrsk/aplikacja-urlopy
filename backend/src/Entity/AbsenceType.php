<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'absence_type')]
class AbsenceType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $code;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $latestRevision = 0;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(string $code)
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code)) {
            throw new \InvalidArgumentException('Absence type code must use lowercase ASCII letters, digits and underscores.');
        }

        $this->code = $code;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getLatestRevision(): int
    {
        return $this->latestRevision;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @param list<EmploymentType> $employmentTypes
     * @param array<int|string, string> $namesByEmploymentType
     */
    public function createVersion(string $name, bool $deductsBalance, array $employmentTypes, array $namesByEmploymentType = []): AbsenceTypeVersion
    {
        $version = new AbsenceTypeVersion($this, $this->latestRevision + 1, $name, $deductsBalance, $employmentTypes, $namesByEmploymentType);
        ++$this->latestRevision;

        return $version;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
