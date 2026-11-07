<?php

namespace App\Security;

use App\Entity\User;
use App\Enum\TeamRole;

/** Balance administration follows current team membership, never a client-supplied year. */
final class BalanceAccessPolicy
{
    public function __construct(private CurrentAssignmentResolver $assignments)
    {
    }

    public function canViewBalance(User $actor, User $subject, \DateTimeImmutable $date): bool
    {
        if (!$actor->isActive()) {
            return false;
        }

        return $actor->isAdministrator() || $this->sameUser($actor, $subject)
            || $this->hasTeamRole($actor, $subject, $date, [TeamRole::MANAGER, TeamRole::DEPUTY]);
    }

    public function canManageBalance(User $actor, User $subject, \DateTimeImmutable $date): bool
    {
        if (!$actor->isActive()) {
            return false;
        }
        if ($actor->isAdministrator()) {
            return true;
        }

        return !$this->sameUser($actor, $subject)
            && $this->hasTeamRole($actor, $subject, $date, [TeamRole::MANAGER]);
    }

    /** @param list<TeamRole> $roles */
    private function hasTeamRole(User $actor, User $subject, \DateTimeImmutable $date, array $roles): bool
    {
        $actorAssignment = $this->assignments->resolve($actor, $date);
        $subjectAssignment = $this->assignments->resolve($subject, $date);
        if (null === $actorAssignment || null === $subjectAssignment || !\in_array($actorAssignment->getRole(), $roles, true)) {
            return false;
        }
        $actorTeam = $actorAssignment->getTeam();
        $subjectTeam = $subjectAssignment->getTeam();

        return $actorTeam === $subjectTeam || (null !== $actorTeam->getId() && $actorTeam->getId() === $subjectTeam->getId());
    }

    private function sameUser(User $actor, User $subject): bool
    {
        return $actor === $subject || (null !== $actor->getId() && $actor->getId() === $subject->getId());
    }
}
