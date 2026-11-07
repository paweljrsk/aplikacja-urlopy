<?php

namespace App\Security;

use App\Entity\RequestDay;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;

/**
 * Q5 authorization for future request/calendar endpoints; this class does not expose an API.
 *
 * RequestDay.assignment is the immutable calculation snapshot, not the access-control owner.
 * Past days belong to the subject's team on that day. Future days belong to the subject's
 * currently effective team: a scheduled transfer grants the new team access only when it
 * takes effect in Europe/Warsaw. Roles are always checked at the evaluation time.
 *
 * These methods authorize data/actors, not workflow transitions, balance or Q2 corrections.
 * Callers must apply those rules separately and serialize only the fields authorized below.
 */
final class RequestAccessPolicy
{
    public function __construct(private CurrentAssignmentResolver $assignments)
    {
    }

    /**
     * Calendar visibility only: name, date, status and hours. This does not authorize the
     * absence type, balance, assignment snapshot, revision source or decision audit.
     */
    public function canViewDay(User $actor, RequestDay $day, \DateTimeImmutable $at): bool
    {
        if (!$actor->isActive()) {
            return false;
        }
        if ($actor->isAdministrator() || $this->sameUser($actor, $day->getUser())) {
            return true;
        }

        return $this->sameTeam(
            $this->assignments->resolve($actor, $at)?->getTeam(),
            $this->responsibleAssignment($day, $at)?->getTeam(),
        );
    }

    /**
     * Minimum allowed calendar subset. Project each returned day to the public calendar
     * fields; never serialize its linked entities. Apply this separately to every revision.
     *
     * @return list<RequestDay>
     */
    public function visibleDays(User $actor, RequestRevision $revision, \DateTimeImmutable $at): array
    {
        return array_values(array_filter(
            $revision->getDays(),
            fn (RequestDay $day): bool => $this->canViewDay($actor, $day, $at),
        ));
    }

    /**
     * Authorizes this revision's private details and its base revision, never the request's
     * entire history. A mixed-team revision has no supervisor-wide detail access; use the
     * filtered calendar projection instead. The employee and administrator see all details.
     */
    public function canViewRevisionDetails(User $actor, RequestRevision $revision, \DateTimeImmutable $at): bool
    {
        if (!$actor->isActive() || [] === $revision->getDays()) {
            return false;
        }
        if ($actor->isAdministrator() || $this->sameUser($actor, $revision->getRequest()->getUser())) {
            return true;
        }
        $actorAssignment = $this->supervisorAssignment($actor, $at);
        if (null === $actorAssignment) {
            return false;
        }
        foreach ($this->affectedDays($revision) as $day) {
            if (!$this->sameTeam($actorAssignment->getTeam(), $this->responsibleAssignment($day, $at)?->getTeam())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Actor authorization for the pending revision. Both proposed and replaced absence
     * days must belong to the supervisor's team; a transfer-spanning operation is therefore
     * administrator-only, including a transfer scheduled for the future. Changes of
     * employment type or role inside one team do not require
     * an administrator. Existing approvals and calculation snapshots are never modified.
     */
    public function canDecideRevision(User $actor, RequestRevision $revision, \DateTimeImmutable $at): bool
    {
        if (!$actor->isActive() || [] === $revision->getDays() || null !== $revision->getDecision()
            || $revision !== $revision->getRequest()->getPendingRevision()) {
            return false;
        }
        if ($actor->isAdministrator()) {
            return true;
        }
        if (!$this->hasSingleScheduledTeam($revision)) {
            return false;
        }
        $actorAssignment = $this->supervisorAssignment($actor, $at);
        if (null === $actorAssignment
            || ($this->sameUser($actor, $revision->getRequest()->getUser()) && TeamRole::MANAGER !== $actorAssignment->getRole())) {
            return false;
        }
        foreach ($this->affectedDays($revision) as $day) {
            if (!$this->sameTeam($actorAssignment->getTeam(), $this->responsibleAssignment($day, $at)?->getTeam())) {
                return false;
            }
        }

        return true;
    }

    private function responsibleAssignment(RequestDay $day, \DateTimeImmutable $at): ?StaffAssignment
    {
        $today = $at->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
        $date = min($day->getDate()->format('Y-m-d'), $today);

        return $this->assignments->resolve($day->getUser(), new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw')));
    }

    private function hasSingleScheduledTeam(RequestRevision $revision): bool
    {
        $team = null;
        foreach ($this->affectedDays($revision) as $day) {
            $dayTeam = $this->assignments->resolve($day->getUser(), $day->getDate())?->getTeam();
            if (null === $dayTeam || (null !== $team && !$this->sameTeam($team, $dayTeam))) {
                return false;
            }
            $team = $dayTeam;
        }

        return null !== $team;
    }

    private function supervisorAssignment(User $actor, \DateTimeImmutable $at): ?StaffAssignment
    {
        $assignment = $this->assignments->resolve($actor, $at);

        return null !== $assignment && \in_array($assignment->getRole(), [TeamRole::MANAGER, TeamRole::DEPUTY], true)
            ? $assignment : null;
    }

    /** @return list<RequestDay> */
    private function affectedDays(RequestRevision $revision): array
    {
        $days = $revision->getDays();
        // The direct base is the currently approved absence replaced by this proposal.
        // Older superseded revisions are separate history and need separate authorization.
        if (null !== $revision->getBaseRevision()) {
            $days = [...$days, ...$revision->getBaseRevision()->getDays()];
        }

        return $days;
    }

    private function sameTeam(?Team $left, ?Team $right): bool
    {
        return null !== $left && null !== $right
            && ($left === $right || (null !== $left->getId() && $left->getId() === $right->getId()));
    }

    private function sameUser(User $left, User $right): bool
    {
        return $left === $right || (null !== $left->getId() && $left->getId() === $right->getId());
    }
}
