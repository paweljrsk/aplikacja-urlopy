<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Singleton row locked transactionally before administrative writes. */
#[ORM\Entity]
#[ORM\Table(name: 'administration_lock')]
class AdministrationLock
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    public function getId(): int
    {
        return $this->id;
    }
}
