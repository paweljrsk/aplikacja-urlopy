<?php

namespace App\Security;

use App\Entity\User;
use App\Enum\TeamRole;

/** Current membership only. Historical request visibility remains a separate policy. */
final class AccessPolicy
{
    public function __construct(private CurrentAssignmentResolver $assignments)
    {
    }

    public function canViewProfile(User $actor, User $subject, \DateTimeImmutable $date): bool
    {
        if (!$actor->isActive()) {
            return false;
        }

        return $actor->isAdministrator() || $this->sameUser($actor, $subject) || $this->isTeamSupervisor($actor, $subject, $date);
    }

    public function canDecideFor(User $actor, User $subject, \DateTimeImmutable $date): bool
    {
        if (!$actor->isActive()) {
            return false;
        }
        if ($actor->isAdministrator()) {
            return true;
        }
        if (!$this->isTeamSupervisor($actor, $subject, $date)) {
            return false;
        }

        return !$this->sameUser($actor, $subject) || TeamRole::MANAGER === $this->assignments->resolve($actor, $date)?->getRole();
    }

    private function isTeamSupervisor(User $actor, User $subject, \DateTimeImmutable $date): bool
    {
        $actorAssignment = $this->assignments->resolve($actor, $date);
        $subjectAssignment = $this->assignments->resolve($subject, $date);
        if (null === $actorAssignment || null === $subjectAssignment
            || !\in_array($actorAssignment->getRole(), [TeamRole::MANAGER, TeamRole::DEPUTY], true)) {
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
