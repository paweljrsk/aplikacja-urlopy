<?php

declare(strict_types=1);

namespace App\Service\Requests;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\LeaveRequest;
use App\Entity\RequestDecision;
use App\Entity\RequestRevision;
use App\Entity\User;
use App\Enum\DecisionOutcome;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Model\RequestedDay;
use App\Security\CurrentAssignmentResolver;
use App\Security\RequestAccessPolicy;
use App\Service\Accounting\AccountingService;
use App\Service\Calendar\WorkingCalendar;
use App\Service\CredentialsException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Public stage 6 workflow: one transaction for the request, its decisions, ledger and audit. */
final class RequestWorkflowService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private CurrentAssignmentResolver $assignments,
        private RequestAccessPolicy $access,
        private WorkingCalendar $calendar,
        private AccountingService $accounting,
    ) {
    }

    /** @param array<string, mixed> $data
     * @return array{id: int, reference: string, version: int, revision: int, status: string}
     */
    public function create(User $actor, array $data): array
    {
        return $this->perform($actor, null, 'created', RequestInput::application($data));
    }

    /** @param array<string, mixed> $data
     * @return array{id: int, reference: string, version: int, revision: int, status: string}
     */
    public function edit(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'edited', RequestInput::application($data, true));
    }

    /** @param array<string, mixed> $data
     * @return array{id: int, reference: string, version: int, revision: int, status: string}
     */
    public function withdraw(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'withdrawn', RequestInput::decision($data));
    }

    /** @param array<string, mixed> $data
     * @return array{id: int, reference: string, version: int, revision: int, status: string}
     */
    public function approve(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'approved', RequestInput::decision($data));
    }

    /** @param array<string, mixed> $data
     * @return array{id: int, reference: string, version: int, revision: int, status: string}
     */
    public function reject(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'rejected', RequestInput::decision($data, true));
    }

    /** @param array<string, mixed> $data
     * @return array{id: int, reference: string, version: int, revision: int, status: string}
     */
    private function perform(User $actor, ?int $id, string $action, array $data): array
    {
        $actorId = $actor->getId() ?? throw new \LogicException('The workflow requires a persisted actor.');
        $key = 'request:'.$actorId.':'.$data['operationKey'];
        $payload = $data;
        unset($payload['operationKey'], $payload['expectedVersion']);
        $hash = hash('sha256', json_encode(['action' => $action, 'id' => $id, 'input' => $payload], JSON_THROW_ON_ERROR));

        return $this->em->wrapInTransaction(function () use ($actorId, $id, $action, $data, $key, $hash): array {
            // This is a public transaction boundary. Discard previously read managed snapshots
            // only after serialization, then load a consistent current graph by stable IDs.
            // Callers must persist their separate setup operations before invoking the workflow.
            $connection = $this->em->getConnection();
            $connection->executeQuery('SELECT id FROM administration_lock WHERE id = 1 FOR UPDATE')->fetchOne();
            $this->em->clear();
            $subjectId = null === $id ? $actorId : $connection->fetchOne('SELECT user_id FROM leave_request WHERE id = ?', [$id]);
            if (false === $subjectId) { throw new CredentialsException('NOT_FOUND', 'Nie znaleziono wniosku.', 404); }
            $userIds = array_values(array_unique([$actorId, (int) $subjectId]));
            sort($userIds, SORT_NUMERIC);
            $users = [];
            foreach ($userIds as $userId) {
                $users[$userId] = $this->em->find(User::class, $userId, LockMode::PESSIMISTIC_WRITE)
                    ?? throw new CredentialsException('NOT_FOUND', 'Nie znaleziono użytkownika.', 404);
            }
            $freshActor = $users[$actorId];
            if (!$freshActor->isActive()) { throw new CredentialsException('FORBIDDEN', 'Konto nie jest aktywne.', 403); }
            $request = null === $id ? null : $this->em->find(LeaveRequest::class, $id, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->em->getRepository(AuditEvent::class)->findOneBy(['operationKey' => $key]);
            if (null !== $existing) { return $this->replay($freshActor, $existing, $hash); }
            $pending = null;
            if (null !== $request) {
                $current = RequestState::current($request);
                if (null === $current || !$this->access->canViewRevisionDetails($freshActor, $current, $this->clock->now())) {
                    throw new CredentialsException('FORBIDDEN', 'Brak dostępu do szczegółów tego wniosku.', 403);
                }
                if ($request->getVersion() !== $data['expectedVersion']) {
                    throw new CredentialsException('CONFLICT', 'Wniosek zmienił się. Odśwież dane przed operacją.', 409);
                }
                $pending = $request->getPendingRevision();
                if (null === $pending || RequestChangeKind::INITIAL !== $pending->getKind() || null !== $request->getEffectiveRevision()) {
                    throw new CredentialsException('INVALID_TRANSITION', 'Operacja wymaga oczekującego, jeszcze niezaakceptowanego wniosku.', 409);
                }
                if (\in_array($action, ['edited', 'withdrawn'], true)) {
                    if ($freshActor->getId() !== $request->getUser()->getId()) {
                        throw new CredentialsException('FORBIDDEN', 'Edycja i wycofanie dotyczą własnego oczekującego wniosku.', 403);
                    }
                    $this->notPast($pending->getStartDate()->format('Y-m-d'));
                } elseif (!$this->access->canDecideRevision($freshActor, $pending, $this->clock->now())) {
                    throw new CredentialsException('FORBIDDEN', 'Nie możesz podjąć decyzji o tym wniosku.', 403);
                }
            }

            $subject = $users[(int) $subjectId];
            $before = null === $pending ? [] : $this->snapshot($pending);
            if ('created' === $action || 'edited' === $action) {
                [$policy, $mode, $start, $end, $days] = $this->application($subject, $data, $request, $pending);
                $request ??= new LeaveRequest($subject, $this->clock->now());
                $event = $this->event($freshActor, $subject, $request, $action, $before, [
                    'absenceTypeId' => $policy->getType()->getId(), 'policyRevision' => $policy->getRevision(),
                    'mode' => $mode->value, 'startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d'),
                    'days' => array_map(static fn (RequestedDay $day): array => ['date' => $day->date->format('Y-m-d'), 'hours' => $day->hours], $days),
                ]);
                if (null !== $pending) { $this->em->persist(new RequestDecision($pending, DecisionOutcome::SUPERSEDED, $event)); }
                $revision = new RequestRevision($request, RequestChangeKind::INITIAL, $policy, $mode, $start, $end, $days, $event, editSource: $pending);
                $this->em->persist($request);
                $this->em->persist($revision);
            } else {
                if (null === $request) { throw new \LogicException('A decision requires an existing pending revision.'); }
                $after = $before + ['outcome' => $action];
                if ('rejected' === $action) { $after['reason'] = $data['reason']; }
                $event = $this->event($freshActor, $subject, $request, $action, $before, $after);
                $outcome = match ($action) {
                    'approved' => DecisionOutcome::APPROVED,
                    'rejected' => DecisionOutcome::REJECTED,
                    'withdrawn' => DecisionOutcome::WITHDRAWN,
                    default => throw new \LogicException('Unsupported decision.'),
                };
                $this->em->persist(new RequestDecision($pending, $outcome, $event));
                $revision = $pending;
            }
            $this->accounting->reconcileRequest($request, $event);
            $this->em->flush();
            $receipt = ['id' => $request->getId() ?? throw new \LogicException('Request was not persisted.'),
                'reference' => $request->getReference(), 'version' => $request->getVersion(),
                'revision' => $revision->getRevision(), 'status' => RequestState::status($request)];
            // The operation receipt is written after IDs and the optimistic version are final.
            // It is not a second financial event and is not exposed as raw request history.
            $this->em->persist(new AuditEvent($key, 'request.operation_completed', $freshActor, $subject, $this->clock->now(), [],
                ['payloadHash' => $hash, 'action' => $action, 'receipt' => $receipt], 'request_operation', $request->getReference()));
            $this->em->flush();
            return $receipt;
        });
    }

    /** @param array<string, mixed> $data
     * @return array{AbsenceTypeVersion, RequestMode, \DateTimeImmutable, \DateTimeImmutable, list<RequestedDay>}
     */
    private function application(User $subject, array $data, ?LeaveRequest $request, ?RequestRevision $previous): array
    {
        $this->notPast((string) $data['startDate']);
        $zone = new \DateTimeZone('Europe/Warsaw');
        $start = new \DateTimeImmutable((string) $data['startDate'], $zone);
        $end = new \DateTimeImmutable((string) $data['endDate'], $zone);
        $mode = RequestMode::from((string) $data['mode']);
        $type = $this->em->find(AbsenceType::class, $data['absenceTypeId'])
            ?? throw new CredentialsException('ABSENCE_TYPE_UNAVAILABLE', 'Nie znaleziono rodzaju nieobecności.');
        $policy = null;
        foreach (array_reverse($request?->getRevisions() ?? []) as $prior) {
            if ($prior->getPolicy()->getType() === $type) { $policy = $prior->getPolicy(); break; }
        }
        if (null === $policy) {
            if (!$type->isActive()) { throw new CredentialsException('ABSENCE_TYPE_UNAVAILABLE', 'Ten rodzaj nieobecności nie jest aktywny.'); }
            $policy = $this->em->getRepository(AbsenceTypeVersion::class)->findOneBy(['type' => $type, 'revision' => $type->getLatestRevision()])
                ?? throw new CredentialsException('ABSENCE_TYPE_UNAVAILABLE', 'Brak skonfigurowanych zasad tego rodzaju.');
        }
        $working = $this->calendar->days($start, $end, isset($data['hours']) ? (int) $data['hours'] : null);
        if ([] === $working) { throw new CredentialsException('NO_WORKING_DAYS', 'Wniosek musi zawierać co najmniej jeden dzień roboczy.'); }
        $days = [];
        foreach ($working as $key => $hours) {
            $date = new \DateTimeImmutable($key, $zone);
            $retained = null !== $previous && $key >= $previous->getStartDate()->format('Y-m-d') && $key <= $previous->getEndDate()->format('Y-m-d');
            $assignment = $retained ? $previous->capturedAssignmentFor($date) : $this->assignments->resolve($subject, $date);
            if (null === $assignment) {
                throw new CredentialsException($retained ? 'LEGACY_CONTEXT_MISSING' : 'ASSIGNMENT_REQUIRED',
                    $retained ? 'Nie można jednoznacznie odtworzyć zapisanych zasad dla dnia '.$key.'.' : 'Brak przypisania do zespołu i formy zatrudnienia na dzień '.$key.'.', $retained ? 409 : 422);
            }
            if (!$retained && (!$assignment->getTeam()->isActive() || !$assignment->getEmploymentType()->isActive())) {
                throw new CredentialsException('INACTIVE_ASSIGNMENT', 'Zespół i forma zatrudnienia muszą być aktywne dla nowego dnia nieobecności.');
            }
            if (!$policy->allowsEmploymentType($assignment->getEmploymentType())) {
                throw new CredentialsException('ABSENCE_TYPE_UNAVAILABLE', 'Ten rodzaj nieobecności nie jest dostępny dla formy zatrudnienia w dniu '.$key.'.');
            }
            $days[] = new RequestedDay($assignment, $date, $hours);
        }
        return [$policy, $mode, $start, $end, $days];
    }

    private function notPast(string $start): void
    {
        if ($start < $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d')) {
            throw new CredentialsException('PAST_REQUEST', 'Własny wniosek można obsłużyć wyłącznie od dzisiejszego dnia.');
        }
    }

    /** @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function event(User $actor, User $subject, LeaveRequest $request, string $action, array $before, array $after): AuditEvent
    {
        $event = new AuditEvent('request.event:'.bin2hex(random_bytes(16)), 'request.'.$action, $actor, $subject, $this->clock->now(), $before, $after, 'request', $request->getReference());
        $this->em->persist($event);
        return $event;
    }

    /** @return array<string, mixed> */
    private function snapshot(RequestRevision $revision): array
    {
        return ['revision' => $revision->getRevision(), 'absenceTypeId' => $revision->getPolicy()->getType()->getId(),
            'policyRevision' => $revision->getPolicy()->getRevision(), 'mode' => $revision->getMode()->value,
            'startDate' => $revision->getStartDate()->format('Y-m-d'), 'endDate' => $revision->getEndDate()->format('Y-m-d'),
            'days' => array_map(static fn ($day): array => ['date' => $day->getDate()->format('Y-m-d'), 'hours' => $day->getHours()], $revision->getDays())];
    }

    /** @return array{id: int, reference: string, version: int, revision: int, status: string} */
    private function replay(User $actor, AuditEvent $event, string $hash): array
    {
        $stored = $event->getAfterData();
        if ('request.operation_completed' !== $event->getAction() || $event->getActor()?->getId() !== $actor->getId()
            || ($stored['payloadHash'] ?? null) !== $hash) {
            throw new CredentialsException('IDEMPOTENCY_CONFLICT', 'Klucz operacji został wykorzystany z innymi danymi.', 409);
        }
        /** @var array{id: int, reference: string, version: int, revision: int, status: string} $receipt */
        $receipt = $stored['receipt'];
        $request = $this->em->find(LeaveRequest::class, $receipt['id']);
        $revision = null === $request ? null : $this->em->getRepository(RequestRevision::class)->findOneBy(['request' => $request, 'revision' => $receipt['revision']]);
        if (null === $revision || !$this->access->canViewRevisionDetails($actor, $revision, $this->clock->now())) {
            throw new CredentialsException('FORBIDDEN', 'Brak aktualnego dostępu do wersji powiązanej z tą operacją.', 403);
        }
        return $receipt;
    }
}
