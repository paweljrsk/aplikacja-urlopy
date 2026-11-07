<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'team')]
class Team
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
    private bool $active = true;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(string $code, string $name)
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code)) {
            throw new \InvalidArgumentException('Team code must use lowercase ASCII letters, digits and underscores.');
        }

        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Team name must contain 1–255 characters.');
        }

        $this->code = $code;
        $this->name = $name;
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
            throw new \InvalidArgumentException('A name of 1–255 characters is required.');
        }
        $this->name = $name;
        $this->active = $active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
