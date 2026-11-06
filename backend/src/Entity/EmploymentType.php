<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'employment_type')]
class EmploymentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private bool $carriesUnusedHours;

    #[ORM\Column]
    private bool $active = true;

    public function __construct(string $code, string $name, bool $carriesUnusedHours)
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code)) {
            throw new \InvalidArgumentException('Employment type code must use lowercase ASCII letters, digits and underscores.');
        }

        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Employment type name must contain 1–255 characters.');
        }

        $this->code = $code;
        $this->name = $name;
        $this->carriesUnusedHours = $carriesUnusedHours;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function carriesUnusedHours(): bool
    {
        return $this->carriesUnusedHours;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
