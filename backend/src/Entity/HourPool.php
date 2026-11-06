<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hour_pool')]
#[ORM\UniqueConstraint(name: 'uniq_hour_pool_user_origin', columns: ['user_id', 'origin_year'])]
class HourPool
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column]
    private int $movementRevision = 0;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\Column]
        private int $originYear,
    ) {
        if ($originYear < 1000 || $originYear > 9999) {
            throw new \InvalidArgumentException('The pool origin year must fit the supported calendar date range.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getOriginYear(): int
    {
        return $this->originYear;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getMovementRevision(): int
    {
        return $this->movementRevision;
    }

    /**
     * Serializes changes to a shared originating pool even across different leave years.
     */
    public function touch(): void
    {
        ++$this->movementRevision;
    }
}
