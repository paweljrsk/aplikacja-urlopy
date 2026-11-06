<?php

namespace App\Security;

use App\Entity\User;
use Psr\Clock\ClockInterface;

final class IdentityFactory
{
    public function __construct(private CurrentAssignmentResolver $assignments, private ClockInterface $clock)
    {
    }

    public function create(User $user): SecurityIdentity
    {
        $id = $user->getId() ?? throw new \LogicException('Only persisted users may authenticate.');
        $assignment = $this->assignments->resolve($user, $this->clock->now());
        $team = $assignment?->getTeam();
        $teamData = null;
        if (null !== $team) {
            $teamData = ['id' => $team->getId() ?? throw new \LogicException('The assigned team must be persisted.'), 'code' => $team->getCode(), 'name' => $team->getName()];
        }

        return new SecurityIdentity($id, $user->getEmail(), $user->getFirstName(), $user->getLastName(), $user->isActive(), $user->isAdministrator(), $user->mustChangePassword(), $user->getCredentialVersion(), $user->getAssignmentRevision(), null === $assignment ? 'none' : implode(':', [$assignment->getId(), $team?->getId(), $assignment->getRole()->value]), $teamData, $assignment?->getRole()->value, $user->getTemporaryPasswordExpiresAt()?->getTimestamp(), $user->getPasswordHash());
    }
}
