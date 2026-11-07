<?php

namespace App\Entity;

use App\Model\DateValue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'holiday')]
class Holiday
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, unique: true)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(\DateTimeImmutable $date, string $name)
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Holiday name must contain 1–255 characters.');
        }

        $this->date = DateValue::local($date);
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function update(string $name, bool $active): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Holiday name must contain 1–255 characters.');
        }
        $this->name = $name;
        $this->active = $active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
