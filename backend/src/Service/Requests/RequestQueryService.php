<?php

namespace App\Service\Requests;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\LeaveRequest;
use App\Entity\RequestDay;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\User;
use App\Enum\RequestChangeKind;
use App\Enum\TeamRole;
use App\Security\CurrentAssignmentResolver;
use App\Security\RequestAccessPolicy;
use App\Service\Admin\AdminInput;
use App\Service\CredentialsException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Explicit DTOs: each historical revision has its own authorization boundary. */
final class RequestQueryService
{
    private const CANDIDATE_LIMIT = 500;

    public function __construct(
        private EntityManagerInterface $em,
        private RequestAccessPolicy $access,
        private CurrentAssignmentResolver $assignments,
        private ClockInterface $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function options(User $actor): array
    {
        return $this->read($actor, function (User $actor): array {
            $now = $this->now();
            $employment = $this->assignments->resolve($actor, $now)?->getEmploymentType();
            $types = [];
            foreach ($this->em->getRepository(AbsenceType::class)->findBy(['active' => true], ['id' => 'ASC']) as $type) {
                $policy = $this->em->getRepository(AbsenceTypeVersion::class)->findOneBy(['type' => $type, 'revision' => $type->getLatestRevision()]);
                if (null === $policy) {
                    continue;
                }
                $types[] = [
                    'id' => $type->getId(), 'code' => $type->getCode(), 'revision' => $policy->getRevision(),
                    'name' => null === $employment ? $policy->getName() : $policy->getNameFor($employment),
                    'deductsBalance' => $policy->deductsBalance(),
                    'availableForCurrentEmployment' => null !== $employment && $employment->isActive() && $policy->allowsEmploymentType($employment),
                    'employmentTypeIds' => array_map(static fn ($item): ?int => $item->getId(), $policy->getEmploymentTypes()),
                    'namesByEmploymentType' => $policy->getNamesByEmploymentType(),
                ];
            }

            return [
                'today' => $now->format('Y-m-d'), 'timezone' => 'Europe/Warsaw', 'hoursPerDay' => 8,
                'modes' => ['full_days', 'hourly'], 'absenceTypes' => $types,
            ];
        });
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(User $actor, array $query = []): array
    {
        AdminInput::only($query, ['scope', 'limit', 'beforeId', 'status']);
        $scope = $query['scope'] ?? 'mine';
        if (!\is_string($scope) || !\in_array($scope, ['mine', 'managed'], true)) {
            throw new CredentialsException('VALIDATION_FAILED', 'Wybierz poprawny zakres listy.');
        }
        $limit = array_key_exists('limit', $query) ? $this->positiveQueryInteger($query['limit'], 'limit', 100) : 50;
        $beforeId = array_key_exists('beforeId', $query) ? $this->positiveQueryInteger($query['beforeId'], 'beforeId') : null;
        $status = $query['status'] ?? null;
        if (null !== $status && (!\is_string($status) || !\in_array($status, ['pending', 'approved', 'rejected', 'withdrawn', 'cancelled', 'change_pending', 'cancellation_pending'], true))) {
            throw new CredentialsException('VALIDATION_FAILED', 'Wybierz poprawny status.');
        }

        return $this->read($actor, function (User $actor) use ($scope, $limit, $beforeId, $status): array {
            $now = $this->now();
            $assignment = $this->assignments->resolve($actor, $now);
            if ('managed' === $scope && !$actor->isAdministrator() && (null === $assignment || !\in_array($assignment->getRole(), [TeamRole::MANAGER, TeamRole::DEPUTY], true))) {
                throw new CredentialsException('FORBIDDEN', 'Nie masz dostępu do listy obsługiwanych wniosków.', 403);
            }

            $builder = $this->em->createQueryBuilder()->select('r')->from(LeaveRequest::class, 'r')->orderBy('r.id', 'DESC')->setMaxResults(self::CANDIDATE_LIMIT + 1);
            if ('mine' === $scope) {
                $builder->andWhere('r.user = :actor')->setParameter('actor', $actor);
            } elseif (!$actor->isAdministrator()) {
                // Coarse candidate filter only. The policy below still authorizes every revision.
                $builder->andWhere('EXISTS (SELECT a.id FROM '.StaffAssignment::class.' a WHERE a.user = r.user AND a.team = :team)')->setParameter('team', $assignment?->getTeam());
            }
            if (null !== $beforeId) {
                $builder->andWhere('r.id < :before')->setParameter('before', $beforeId);
            }
            /** @var list<LeaveRequest> $candidates */
            $candidates = $builder->getQuery()->getResult();
            $rows = [];
            $lastScanned = null;
            $scanned = 0;
            foreach ($candidates as $request) {
                if ($scanned >= self::CANDIDATE_LIMIT || count($rows) >= $limit) {
                    break;
                }
                ++$scanned;
                $lastScanned = $request->getId();
                $visible = $this->visibleRevisions($actor, $request, $now);
                if ([] === $visible) {
                    continue;
                }
                $current = RequestState::current($request);
                $currentVisible = null !== $current && \in_array($current, $visible, true);
                // A status filter must not reveal an unauthorized current version.
                if (null !== $status && (!$currentVisible || RequestState::status($request) !== $status)) {
                    continue;
                }
                $rows[] = $this->summary($actor, $request, $visible, $now);
            }

            return ['requests' => $rows, 'nextBeforeId' => $scanned < count($candidates) ? $lastScanned : null];
        });
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, int $id): array
    {
        return $this->read($actor, function (User $actor) use ($id): array {
            $request = $this->em->find(LeaveRequest::class, $id) ?? throw new CredentialsException('NOT_FOUND', 'Nie znaleziono wniosku.', 404);
            $now = $this->now();
            $visible = $this->visibleRevisions($actor, $request, $now);
            if ([] === $visible) {
                throw new CredentialsException('FORBIDDEN', 'Nie masz dostępu do szczegółów tego wniosku.', 403);
            }
            $summary = $this->summary($actor, $request, $visible, $now);
            $current = RequestState::current($request);
            $summary['current'] = $summary['currentVisible'] && null !== $current ? $this->revision($current) : null;
            // Effective absence remains in force while a separately authorized proposal waits.
            // Null deliberately does not distinguish absence from an unauthorized version.
            $effective = $request->getEffectiveRevision();
            $pending = $request->getPendingRevision();
            $summary['effective'] = null !== $effective && \in_array($effective, $visible, true) ? $this->revision($effective) : null;
            $summary['pending'] = null !== $pending && \in_array($pending, $visible, true) ? $this->revision($pending) : null;
            $summary['history'] = array_map($this->revision(...), $visible);

            return $summary;
        });
    }

    /**
     * @param non-empty-list<RequestRevision> $visible
     * @return array<string, mixed>
     */
    private function summary(User $actor, LeaveRequest $request, array $visible, \DateTimeImmutable $now): array
    {
        $current = RequestState::current($request);
        $currentVisible = null !== $current && \in_array($current, $visible, true);
        $subject = $request->getUser();

        return [
            'id' => $request->getId(), 'reference' => $request->getReference(),
            'user' => ['id' => $subject->getId(), 'firstName' => $subject->getFirstName(), 'lastName' => $subject->getLastName()],
            'version' => $currentVisible ? $request->getVersion() : null,
            'status' => $currentVisible ? RequestState::status($request) : null, 'currentVisible' => $currentVisible,
            'latestVisibleRevision' => $this->revision($visible[array_key_last($visible)]),
            'actions' => $this->actions($actor, $request, $currentVisible ? $current : null, $now),
        ];
    }

    /** @return list<RequestRevision> */
    private function visibleRevisions(User $actor, LeaveRequest $request, \DateTimeImmutable $now): array
    {
        $visible = array_values(array_filter($request->getRevisions(), fn (RequestRevision $revision): bool => $this->access->canViewRevisionDetails($actor, $revision, $now)));
        usort($visible, static fn (RequestRevision $left, RequestRevision $right): int => $left->getRevision() <=> $right->getRevision());

        return $visible;
    }

    /** @return array{edit: bool, withdraw: bool, approve: bool, reject: bool, change: bool, cancel: bool, correct: bool} */
    private function actions(User $actor, LeaveRequest $request, ?RequestRevision $current, \DateTimeImmutable $now): array
    {
        $pending = $request->getPendingRevision();
        $effective = $request->getEffectiveRevision();
        $visiblePending = null !== $current && $current === $pending;
        $initial = $visiblePending && RequestChangeKind::INITIAL === $current->getKind() && null === $effective;
        $own = $actor->getId() === $request->getUser()->getId();
        $currentFuture = null !== $current && $current->getStartDate()->format('Y-m-d') >= $now->format('Y-m-d');
        $baseFuture = null === $current?->getBaseRevision() || $current->getBaseRevision()->getStartDate()->format('Y-m-d') >= $now->format('Y-m-d');
        $ownFuture = $own && $currentFuture;
        $decide = $visiblePending && $this->access->canDecideRevision($actor, $current, $now);
        $propose = null !== $current && $current === $effective && null === $pending && $ownFuture;
        $correctableState = null !== $current && (($current === $effective && null === $pending) || $initial);
        $correct = $correctableState && !$currentFuture && $this->access->canManageRevision($actor, $current, $now);

        return ['edit' => $initial && $ownFuture, 'withdraw' => $visiblePending && $ownFuture && $baseFuture,
            'approve' => $decide, 'reject' => $decide, 'change' => $propose, 'cancel' => $propose, 'correct' => $correct];
    }

    /** @return array<string, mixed> */
    private function revision(RequestRevision $revision): array
    {
        $policy = $revision->getPolicy();
        $source = $revision->getSource();
        $decision = $revision->getDecision();
        $event = $decision?->getEvent();
        $reason = $event?->getAfterData()['reason'] ?? null;
        $submittedReason = $this->submittedReason($revision);

        return [
            'id' => $revision->getId(), 'revision' => $revision->getRevision(), 'kind' => $revision->getKind()->value,
            'baseRevision' => $revision->getBaseRevision()?->getRevision(),
            'mode' => $revision->getMode()->value, 'startDate' => $revision->getStartDate()->format('Y-m-d'),
            'endDate' => $revision->getEndDate()->format('Y-m-d'), 'totalHours' => $revision->getTotalHours(),
            'days' => array_map(static fn (RequestDay $day): array => ['date' => $day->getDate()->format('Y-m-d'), 'hours' => $day->getHours()], $revision->getDays()),
            'absenceType' => ['id' => $policy->getType()->getId(), 'code' => $policy->getType()->getCode(), 'revision' => $policy->getRevision(), 'name' => $policy->getName(), 'deductsBalance' => $policy->deductsBalance()],
            'submitted' => ['at' => $source->getOccurredAt()->format(\DATE_ATOM), 'actorName' => $this->actorName($source->getActor()),
                'reason' => \is_string($submittedReason) ? $submittedReason : null],
            'decision' => null === $decision || null === $event ? null : [
                'outcome' => $decision->getOutcome()->value, 'at' => $event->getOccurredAt()->format(\DATE_ATOM),
                'actorName' => $this->actorName($event->getActor()), 'reason' => \is_string($reason) ? $reason : null,
            ],
        ];
    }

    private function submittedReason(RequestRevision $revision): ?string
    {
        $source = $revision->getSource();
        $data = $source->getAfterData();
        // Legacy edits can share the previous revision's decision event. A generic reason
        // in that event belongs to its own authorized history, not to the new submission.
        if ('request.corrected' !== $source->getAction() || 'request' !== $source->getTargetType()
            || $source->getTargetReference() !== $revision->getRequest()->getReference()
            || !\is_string($data['reason'] ?? null)
            || ($data['correctionAction'] ?? null) !== (RequestChangeKind::CANCELLATION === $revision->getKind() ? 'cancel' : 'change')) {
            return null;
        }
        $policy = $revision->getPolicy();
        $expected = ['absenceTypeId' => $policy->getType()->getId(), 'policyRevision' => $policy->getRevision(),
            'mode' => $revision->getMode()->value, 'startDate' => $revision->getStartDate()->format('Y-m-d'),
            'endDate' => $revision->getEndDate()->format('Y-m-d')];
        foreach ($expected as $field => $value) {
            if (($data[$field] ?? null) !== $value) {
                return null;
            }
        }
        $recordedDays = $data['days'] ?? null;
        $days = $revision->getDays();
        if (!\is_array($recordedDays) || !array_is_list($recordedDays) || count($recordedDays) !== count($days)) {
            return null;
        }
        foreach ($days as $index => $day) {
            $recorded = $recordedDays[$index];
            if (!\is_array($recorded) || ($recorded['date'] ?? null) !== $day->getDate()->format('Y-m-d')
                || ($recorded['hours'] ?? null) !== $day->getHours()) {
                return null;
            }
        }

        return $data['reason'];
    }

    private function actorName(?User $actor): ?string
    {
        return null === $actor ? null : $actor->getFirstName().' '.$actor->getLastName();
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'));
    }

    private function positiveQueryInteger(mixed $value, string $field, int $maximum = PHP_INT_MAX): int
    {
        if (!\is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value) || false === filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]])) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowy parametr '.$field.'.');
        }

        return (int) $value;
    }

    /**
     * Public read entry point: called without unflushed writes, never inside a mutation.
     * @param callable(User): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function read(User $actor, callable $operation): array
    {
        $actorId = $actor->getId();

        return $this->em->getConnection()->transactional(function () use ($actorId, $operation): array {
            // Detach graphs hydrated before the read snapshot, including initialized revisions
            // and assignment collections. Refreshing only the actor cannot refresh those graphs.
            $this->em->clear();
            $freshActor = null === $actorId ? null : $this->em->find(User::class, $actorId);
            if (null === $freshActor || !$freshActor->isActive()) {
                throw new CredentialsException('AUTH_REQUIRED', 'Konto nie jest aktywne.', 401);
            }

            return $operation($freshActor);
        });
    }
}
