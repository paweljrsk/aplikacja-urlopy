<?php

declare(strict_types=1);

namespace App\Service\Requests;

use App\Entity\Holiday;
use App\Entity\LeaveRequest;
use App\Entity\RequestDay;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\RequestChangeKind;
use App\Security\CurrentAssignmentResolver;
use App\Security\RequestAccessPolicy;
use App\Service\Admin\AdminInput;
use App\Service\Calendar\WorkingCalendar;
use App\Service\CredentialsException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Public team projection: never serialize private request DTOs or linked entities. */
final class TeamCalendarService
{
    public const MAXIMUM_DAYS = 93;

    public function __construct(
        private EntityManagerInterface $em,
        private RequestAccessPolicy $access,
        private CurrentAssignmentResolver $assignments,
        private WorkingCalendar $calendar,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(User $actor, array $query): array
    {
        AdminInput::only($query, ['from', 'to', 'teamId']);
        $from = AdminInput::date($query, 'from');
        $to = AdminInput::date($query, 'to');
        WorkingCalendar::assertYear((int) $from->format('Y'));
        WorkingCalendar::assertYear((int) $to->format('Y'));
        if ($from > $to || $from->diff($to)->days >= self::MAXIMUM_DAYS) {
            throw new CredentialsException('VALIDATION_FAILED', 'Wybierz uporządkowany okres obejmujący najwyżej 93 dni.');
        }
        $teamId = array_key_exists('teamId', $query) ? $this->teamId($query['teamId']) : null;
        $actorId = $actor->getId();

        return $this->em->getConnection()->transactional(function () use ($actorId, $from, $to, $teamId): array {
            // Authorize and project from one fresh snapshot, not a previously hydrated role.
            $this->em->clear();
            $actor = null === $actorId ? null : $this->em->find(User::class, $actorId);
            if (null === $actor || !$actor->isActive()) {
                throw new CredentialsException('AUTH_REQUIRED', 'Konto nie jest aktywne.', 401);
            }
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'));
            $actorTeam = $this->assignments->resolve($actor, $now)?->getTeam();
            if (null !== $teamId && !$actor->isAdministrator()) {
                throw new CredentialsException('FORBIDDEN', 'Zmiana zespołu jest dostępna administratorowi.', 403);
            }
            $selected = null;
            if ($actor->isAdministrator() && null !== $teamId) {
                $selected = $this->em->find(Team::class, $teamId)
                    ?? throw new CredentialsException('NOT_FOUND', 'Nie znaleziono zespołu.', 404);
            } elseif (!$actor->isAdministrator()) {
                $selected = $actorTeam;
            }
            $teams = $actor->isAdministrator()
                ? $this->em->getRepository(Team::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC'])
                : (null === $actorTeam ? [] : [$actorTeam]);
            $people = $this->people($actor, $selected, $now);
            $entries = [];
            $start = $from->format('Y-m-d');
            $end = $to->format('Y-m-d');
            foreach ($this->candidates($actor, $selected, $start, $end) as $request) {
                $effective = $request->getEffectiveRevision();
                $pending = $request->getPendingRevision();
                $pendingDays = null === $pending ? [] : $this->authorizedDays($actor, $pending, $selected, $now);
                if (null !== $effective) {
                    $status = 'approved';
                    // Do not reveal an inaccessible proposal through the old team's status.
                    if ([] !== $pendingDays) {
                        $status = RequestChangeKind::CANCELLATION === $pending->getKind()
                            ? 'cancellation_pending' : 'change_pending';
                    }
                    $this->append($entries, $people, $actor, $effective, 'effective', $status,
                        $this->authorizedDays($actor, $effective, $selected, $now), $start, $end, $now);
                }
                if (null !== $pending && RequestChangeKind::CANCELLATION !== $pending->getKind()) {
                    $change = RequestChangeKind::CHANGE === $pending->getKind();
                    $this->append($entries, $people, $actor, $pending, $change ? 'proposed_change' : 'pending',
                        $change ? 'change_pending' : 'pending', $pendingDays, $start, $end, $now);
                }
            }
            usort($entries, static fn (array $left, array $right): int =>
                [$left['startDate'], $left['userId'], $left['requestId'], $left['kind']]
                <=> [$right['startDate'], $right['userId'], $right['requestId'], $right['kind']]);
            $people = array_values($people);
            usort($people, static fn (array $left, array $right): int =>
                [$left['lastName'], $left['firstName'], $left['id']] <=> [$right['lastName'], $right['firstName'], $right['id']]);

            return [
                'today' => $now->format('Y-m-d'), 'from' => $start, 'to' => $end, 'timezone' => 'Europe/Warsaw',
                'teams' => array_map(static fn (Team $team): array => ['id' => $team->getId(), 'name' => $team->getName()], $teams),
                'selectedTeamId' => $selected?->getId(), 'people' => $people, 'entries' => $entries,
                'holidays' => $this->holidays($from, $to),
            ];
        });
    }

    /** @return list<LeaveRequest> */
    private function candidates(User $actor, ?Team $selected, string $from, string $to): array
    {
        $builder = $this->em->createQueryBuilder()->select('DISTINCT r', 'u')->from(LeaveRequest::class, 'r')
            ->join('r.user', 'u')->join('r.revisions', 'revision')->join('revision.days', 'd')
            ->where('d.date >= :from AND d.date <= :to')->setParameter('from', $from)->setParameter('to', $to);
        if (null !== $selected) {
            $teamCandidate = 'EXISTS (SELECT a.id FROM '.StaffAssignment::class.' a WHERE a.user = r.user AND a.team = :team)';
            $builder->andWhere($actor->isAdministrator() ? $teamCandidate : '(r.user = :actor OR '.$teamCandidate.')')
                ->setParameter('team', $selected);
            if (!$actor->isAdministrator()) {
                $builder->setParameter('actor', $actor);
            }
        } elseif (!$actor->isAdministrator()) {
            $builder->andWhere('r.user = :actor')->setParameter('actor', $actor);
        }
        // The date range is bounded, not the number of entries; never silently truncate a team.
        return $builder->getQuery()->getResult();
    }

    /** @return array<int, array{id: int|null, firstName: string, lastName: string}> */
    private function people(User $actor, ?Team $selected, \DateTimeImmutable $now): array
    {
        $people = [];
        foreach ($this->em->getRepository(User::class)->findBy(['active' => true]) as $subject) {
            $team = $this->assignments->resolve($subject, $now)?->getTeam();
            $own = $subject->getId() === $actor->getId();
            $visible = null !== $selected
                ? $team?->getId() === $selected->getId()
                : $actor->isAdministrator();
            if ($visible || ($own && !$actor->isAdministrator())) {
                $people[(int) $subject->getId()] = $this->person($subject);
            }
        }
        return $people;
    }

    /** @return list<RequestDay> */
    private function authorizedDays(User $actor, RequestRevision $revision, ?Team $selected, \DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->access->visibleDays($actor, $revision, $now), function (RequestDay $day) use ($actor, $selected, $now): bool {
            // Non-admin own history is explicitly visible across transfers. Team selection is
            // an admin filter; everybody else already passed the authoritative day policy.
            if (!$actor->isAdministrator() || null === $selected) {
                return true;
            }
            $date = min($day->getDate()->format('Y-m-d'), $now->format('Y-m-d'));
            $responsible = $this->assignments->resolve($day->getUser(), new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw')));
            return $responsible?->getTeam()->getId() === $selected->getId();
        }));
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<int, array{id: int|null, firstName: string, lastName: string}> $people
     * @param list<RequestDay> $authorized
     */
    private function append(array &$entries, array &$people, User $actor, RequestRevision $revision, string $kind,
        string $status, array $authorized, string $from, string $to, \DateTimeImmutable $now): void
    {
        $days = [];
        foreach ($authorized as $day) {
            $date = $day->getDate()->format('Y-m-d');
            if ($date >= $from && $date <= $to) {
                $days[] = ['date' => $date, 'hours' => $day->getHours()];
            }
        }
        if ([] === $days) {
            return;
        }
        usort($days, static fn (array $left, array $right): int => $left['date'] <=> $right['date']);
        $request = $revision->getRequest();
        $subject = $request->getUser();
        $people[(int) $subject->getId()] = $this->person($subject);
        $entries[] = [
            'key' => $request->getId().':'.$kind, 'requestId' => $request->getId(),
            'canViewDetails' => $this->access->canViewRevisionDetails($actor, $revision, $now),
            'userId' => $subject->getId(), 'status' => $status, 'kind' => $kind,
            'days' => $days, 'startDate' => $days[0]['date'], 'endDate' => $days[array_key_last($days)]['date'],
            'totalHours' => array_sum(array_column($days, 'hours')),
        ];
    }

    /** @return array{id: int|null, firstName: string, lastName: string} */
    private function person(User $subject): array
    {
        return ['id' => $subject->getId(), 'firstName' => $subject->getFirstName(), 'lastName' => $subject->getLastName()];
    }

    /** @return list<array{date: string, name: string}> */
    private function holidays(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $dates = [];
        for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); ++$year) {
            $dates += $this->calendar->statutoryHolidays($year);
        }
        foreach ($this->em->getRepository(Holiday::class)->findBy(['active' => true]) as $holiday) {
            $date = $holiday->getDate()->format('Y-m-d');
            // Keep both meaningful labels if a company day coincides with a public holiday.
            $dates[$date] = isset($dates[$date]) ? $dates[$date].' · '.$holiday->getName() : $holiday->getName();
        }
        ksort($dates);
        $result = [];
        foreach ($dates as $date => $name) {
            if ($date >= $from->format('Y-m-d') && $date <= $to->format('Y-m-d')) {
                $result[] = ['date' => $date, 'name' => $name];
            }
        }
        return $result;
    }

    private function teamId(mixed $value): int
    {
        if (!\is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)
            || false === filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowy identyfikator zespołu.');
        }
        return (int) $value;
    }
}
