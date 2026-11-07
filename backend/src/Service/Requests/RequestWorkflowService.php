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
use App\Security\BalanceAccessPolicy;
use App\Security\CurrentAssignmentResolver;
use App\Security\RequestAccessPolicy;
use App\Service\Accounting\AccountingService;
use App\Service\Calendar\WorkingCalendar;
use App\Service\CredentialsException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** One transaction covers the request, decisions, accounting and immutable audit. */
final class RequestWorkflowService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private CurrentAssignmentResolver $assignments,
        private RequestAccessPolicy $access,
        private WorkingCalendar $calendar,
        private AccountingService $accounting,
        #[Autowire('%kernel.secret%')] private string $previewSecret = '',
    ) {
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data): array
    {
        return $this->perform($actor, null, 'created', RequestInput::application($data));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function edit(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'edited', RequestInput::application($data, true));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function proposeChange(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'change_proposed', RequestInput::application($data, true));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function previewCreate(User $actor, array $data): array
    {
        return $this->previewApplication($actor, null, 'created', $data);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function previewEdit(User $actor, int $id, array $data): array
    {
        return $this->previewApplication($actor, $id, 'edited', $data);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function previewChange(User $actor, int $id, array $data): array
    {
        return $this->previewApplication($actor, $id, 'change_proposed', $data);
    }

    /** Run the actual workflow and accounting inside an always-rolled-back transaction.
     * A successful preview neither reserves hours nor authorizes a later submission.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function previewApplication(User $actor, ?int $id, string $action, array $data): array
    {
        $input = RequestInput::applicationPreview($data, null !== $id);
        $actorId = $actor->getId() ?? throw new \LogicException('A persisted actor is required.');
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            return $this->execute($actorId, $id, $action, $input, true);
        } finally {
            $connection->rollBack();
            $this->em->clear();
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function proposeCancellation(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'cancellation_proposed', RequestInput::decision($data));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function withdraw(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'withdrawn', RequestInput::decision($data));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function approve(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'approved', RequestInput::decision($data));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function reject(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'rejected', RequestInput::decision($data, true));
    }

    /** A preview executes the same validation and accounting, but always rolls it back.
     * Callers must flush independent work before entering this public transaction boundary.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function previewCorrection(User $actor, int $id, array $data): array
    {
        $input = RequestInput::correction($data);
        $actorId = $actor->getId() ?? throw new \LogicException('A persisted actor is required.');
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            return $this->execute($actorId, $id, 'corrected', $input, true);
        } finally {
            $connection->rollBack();
            $this->em->clear();
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function correct(User $actor, int $id, array $data): array
    {
        return $this->perform($actor, $id, 'corrected', RequestInput::correction($data, true));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function perform(User $actor, ?int $id, string $action, array $data): array
    {
        $actorId = $actor->getId() ?? throw new \LogicException('The workflow requires a persisted actor.');
        return $this->em->wrapInTransaction(fn (): array => $this->execute($actorId, $id, $action, $data, false));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function execute(int $actorId, ?int $id, string $action, array $data, bool $preview): array
    {
        [$actor, $subject, $request] = $this->locked($actorId, $id);
        $key = 'request:'.$actorId.':'.($data['operationKey'] ?? 'preview');
        $hash = $this->payloadHash($id, $action, $data);
        if (!$preview) {
            $existing = $this->em->getRepository(AuditEvent::class)->findOneBy(['operationKey' => $key]);
            if (null !== $existing) { return $this->replay($actor, $existing, $hash); }
        }
        if (null !== $request) {
            $current = RequestState::current($request);
            if (null === $current || !$this->access->canViewRevisionDetails($actor, $current, $this->clock->now())) {
                throw new CredentialsException('FORBIDDEN', 'Brak dostępu do szczegółów tego wniosku.', 403);
            }
            if ($request->getVersion() !== $data['expectedVersion']) {
                throw new CredentialsException('CONFLICT', 'Wniosek zmienił się. Odśwież dane przed operacją.', 409);
            }
        }
        if ('corrected' === $action) {
            if (null === $request) { throw new \LogicException('A correction requires a request.'); }
            return $this->correction($actor, $subject, $request, $data, $key, $hash, $preview);
        }

        $pending = $request?->getPendingRevision();
        $effective = $request?->getEffectiveRevision();
        $previous = null;
        if (null !== $request) {
            if (\in_array($action, ['change_proposed', 'cancellation_proposed'], true)) {
                if (null === $effective || null !== $pending) { $this->invalidTransition('Zmiana wymaga zaakceptowanego wniosku bez oczekującej propozycji.'); }
                $this->own($actor, $subject);
                $this->notPast($effective->getStartDate()->format('Y-m-d'));
                $previous = $effective;
            } else {
                if (null === $pending) { $this->invalidTransition('Wniosek nie oczekuje na tę operację.'); }
                if ('edited' === $action && (RequestChangeKind::INITIAL !== $pending->getKind() || null !== $effective)) {
                    $this->invalidTransition('Edytować można oczekujący wniosek początkowy. Propozycję zmiany najpierw wycofaj.');
                }
                if (\in_array($action, ['edited', 'withdrawn'], true)) {
                    $this->own($actor, $subject);
                    $this->notPast($pending->getStartDate()->format('Y-m-d'));
                    if (null !== $pending->getBaseRevision()) { $this->notPast($pending->getBaseRevision()->getStartDate()->format('Y-m-d')); }
                } elseif (!$this->access->canDecideRevision($actor, $pending, $this->clock->now())) {
                    throw new CredentialsException('FORBIDDEN', 'Nie możesz podjąć decyzji o tym wniosku.', 403);
                }
                $previous = $pending;
            }
        }
        $before = null === $previous ? [] : $this->snapshot($previous);
        $beforeBalances = [];
        if (\in_array($action, ['created', 'edited', 'change_proposed'], true)) {
            [$policy, $mode, $start, $end, $days] = $this->application($subject, $data, $request, $previous);
            if ($preview) {
                $years = [];
                foreach ($days as $day) { $years[(int) $day->date->format('Y')] = true; }
                foreach ($previous?->getDays() ?? [] as $day) { $years[(int) $day->getDate()->format('Y')] = true; }
                ksort($years, SORT_NUMERIC);
                foreach (array_keys($years) as $year) {
                    $beforeBalances[$year] = $this->accounting->getBalance($subject, $year);
                }
            }
            $request ??= new LeaveRequest($subject, $this->clock->now());
            $event = $this->event($actor, $subject, $request, $action, $before, $this->applicationSnapshot($policy, $mode, $start, $end, $days));
            if ('edited' === $action && null !== $pending) { $this->em->persist(new RequestDecision($pending, DecisionOutcome::SUPERSEDED, $event)); }
            $kind = 'change_proposed' === $action ? RequestChangeKind::CHANGE : RequestChangeKind::INITIAL;
            $revision = new RequestRevision($request, $kind, $policy, $mode, $start, $end, $days, $event,
                baseRevision: 'change_proposed' === $action ? $effective : null, editSource: 'edited' === $action ? $pending : null);
            $this->em->persist($request);
            $this->em->persist($revision);
        } elseif ('cancellation_proposed' === $action) {
            if (null === $request || null === $effective) { throw new \LogicException('A cancellation requires the approved revision.'); }
            $event = $this->event($actor, $subject, $request, $action, $before, $before + ['cancellationRequested' => true]);
            $revision = $this->cancellation($request, $effective, $event);
        } else {
            if (null === $request || null === $pending) { throw new \LogicException('A decision requires a pending revision.'); }
            $after = $before + ['outcome' => $action];
            if ('rejected' === $action) { $after['reason'] = $data['reason']; }
            $event = $this->event($actor, $subject, $request, $action, $before, $after);
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
        if ($preview) {
            $years = [];
            foreach ($beforeBalances as $year => $balance) {
                $after = $this->accounting->getBalance($subject, $year);
                $years[] = ['year' => $year,
                    'beforeAvailableHours' => $balance['availableHours'], 'afterAvailableHours' => $after['availableHours'],
                    // Reservation belongs to the leave year, while available pools may be shared.
                    // Never derive this value by subtracting/summing available balances across years.
                    'additionalReservedHours' => max(0, $after['reservedHours'] + $after['changeReservedHours']
                        - $balance['reservedHours'] - $balance['changeReservedHours'])];
            }
            return ['totalHours' => $revision->getTotalHours(),
                'days' => array_map(static fn ($day): array => ['date' => $day->getDate()->format('Y-m-d'), 'hours' => $day->getHours()], $revision->getDays()),
                'deductsBalance' => $revision->getPolicy()->deductsBalance(), 'years' => $years, 'timezone' => 'Europe/Warsaw'];
        }
        return $this->receipt($actor, $subject, $request, $revision, $key, $hash, $action);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function correction(User $actor, User $subject, LeaveRequest $request, array $data, string $key, string $hash, bool $preview): array
    {
        $pending = $request->getPendingRevision();
        $effective = $request->getEffectiveRevision();
        if (null !== $pending && RequestChangeKind::INITIAL !== $pending->getKind()) {
            $this->invalidTransition('Najpierw rozstrzygnij oczekującą zmianę lub anulowanie.');
        }
        $previous = $effective ?? $pending;
        if (null === $previous) { $this->invalidTransition('Korekta wymaga obowiązującego lub oczekującego wniosku.'); }
        if ($previous->getStartDate()->format('Y-m-d') >= $this->today()) {
            throw new CredentialsException('HISTORICAL_CORRECTION_REQUIRED', 'Dla dzisiejszych i przyszłych wniosków użyj zwykłego obiegu zmiany lub anulowania.');
        }
        if (!$this->access->canManageRevision($actor, $previous, $this->clock->now())) {
            throw new CredentialsException('FORBIDDEN', 'Brak uprawnień do korekty tej nieobecności.', 403);
        }
        // A legacy application may predate accounting. Cancellation has no desired days,
        // so validate the original dates as well before it could bypass the opening boundary.
        $this->accounting->assertWithinRecordingPeriod($subject,
            array_map(static fn ($day): \DateTimeImmutable => $day->getDate(), $previous->getDays()));
        if (true === ($data['allowDeficit'] ?? false) && !$actor->isAdministrator()) {
            throw new CredentialsException('FORBIDDEN', 'Tylko administrator może potwierdzić zwiększenie niedoboru.', 403);
        }
        $token = $this->previewToken($actor, $subject, $request, $data);
        if (!$preview && !hash_equals($token, (string) $data['previewToken'])) {
            throw new CredentialsException('STALE_PREVIEW', 'Dane lub propozycja zmieniły się. Wykonaj ponownie podgląd korekty.', 409);
        }
        $version = $request->getVersion();
        $before = $this->snapshot($previous);
        $beforePools = $this->accounting->impactSnapshot($subject);
        $cancel = 'cancel' === $data['action'];
        if ($cancel) {
            $event = $this->event($actor, $subject, $request, 'corrected', $before,
                $before + ['correctionAction' => 'cancel', 'reason' => $data['reason'], 'directDecision' => true]);
            if (null === $effective) {
                // A never-approved application is withdrawn, not retrospectively approved.
                $revision = $previous;
                $this->em->persist(new RequestDecision($revision, DecisionOutcome::WITHDRAWN, $event));
            } else {
                $revision = $this->cancellation($request, $effective, $event);
                $this->em->persist(new RequestDecision($revision, DecisionOutcome::APPROVED, $event));
            }
        } else {
            [$policy, $mode, $start, $end, $days] = $this->application($subject, $data, $request, $previous, true);
            $event = $this->event($actor, $subject, $request, 'corrected', $before,
                $this->applicationSnapshot($policy, $mode, $start, $end, $days) + ['correctionAction' => 'change', 'reason' => $data['reason'], 'directDecision' => null !== $effective]);
            if (null === $effective) { $this->em->persist(new RequestDecision($previous, DecisionOutcome::SUPERSEDED, $event)); }
            $revision = new RequestRevision($request, null !== $effective ? RequestChangeKind::CHANGE : RequestChangeKind::INITIAL,
                $policy, $mode, $start, $end, $days, $event, baseRevision: $effective, editSource: null === $effective ? $previous : null);
            if (!$this->access->canManageRevision($actor, $revision, $this->clock->now())) {
                throw new CredentialsException('FORBIDDEN', 'Korekta obejmuje terminy spoza Twojego zakresu uprawnień.', 403);
            }
            $this->em->persist($revision);
            // Correcting a pending INITIAL preserves its need for the original approval.
            if (null !== $effective) { $this->em->persist(new RequestDecision($revision, DecisionOutcome::APPROVED, $event)); }
        }
        // Even an administrator's historical exception cannot finance new future leave.
        $this->accounting->reconcileRequest($request, $event, $actor->isAdministrator(), true);
        $afterPools = $this->accounting->impactSnapshot($subject);
        $requiresDeficit = $this->increasesDeficit($beforePools, $afterPools);
        if ($requiresDeficit && !$actor->isAdministrator()) {
            throw new CredentialsException('FORBIDDEN', 'Korektę zwiększającą niedobór musi wykonać administrator.', 403);
        }
        if (!$preview && $requiresDeficit && true !== $data['allowDeficit']) {
            throw new CredentialsException('DEFICIT_CONFIRMATION_REQUIRED', 'Potwierdź jawnie zwiększenie niedoboru wskazane w podglądzie.');
        }
        if ($preview) {
            $after = $this->snapshot($revision);
            if ($cancel) { $after['days'] = []; $after['totalHours'] = 0; $after['cancelled'] = true; }
            $balanceVisible = (new BalanceAccessPolicy($this->assignments))->canViewBalance($actor, $subject, $this->clock->now());
            return ['id' => $request->getId(), 'version' => $version, 'action' => $data['action'], 'previewToken' => $token,
                'before' => $before, 'after' => $after,
                'impact' => ['hoursByYear' => $this->yearImpact($previous, $revision, $cancel), 'pools' => $balanceVisible ? ['before' => $beforePools, 'after' => $afterPools] : null],
                'requiresDeficitConfirmation' => $requiresDeficit];
        }
        return $this->receipt($actor, $subject, $request, $revision, $key, $hash, 'corrected');
    }

    /** @return array{User, User, ?LeaveRequest} */
    private function locked(int $actorId, ?int $id): array
    {
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
        $actor = $users[$actorId];
        if (!$actor->isActive()) { throw new CredentialsException('FORBIDDEN', 'Konto nie jest aktywne.', 403); }
        $request = null === $id ? null : $this->em->find(LeaveRequest::class, $id, LockMode::PESSIMISTIC_WRITE);
        return [$actor, $users[(int) $subjectId], $request];
    }

    /** @param array<string, mixed> $data
     * @return array{AbsenceTypeVersion, RequestMode, \DateTimeImmutable, \DateTimeImmutable, list<RequestedDay>}
     */
    private function application(User $subject, array $data, ?LeaveRequest $request, ?RequestRevision $previous, bool $historical = false): array
    {
        if (!$historical) { $this->notPast((string) $data['startDate']); }
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

    private function cancellation(LeaveRequest $request, RequestRevision $base, AuditEvent $event): RequestRevision
    {
        $days = array_map(static fn ($day): RequestedDay => new RequestedDay($day->getAssignment(), $day->getDate(), $day->getHours()), $base->getDays());
        $revision = new RequestRevision($request, RequestChangeKind::CANCELLATION, $base->getPolicy(), $base->getMode(),
            $base->getStartDate(), $base->getEndDate(), $days, $event, baseRevision: $base, calendarSource: $base);
        $this->em->persist($revision);
        return $revision;
    }

    private function own(User $actor, User $subject): void
    {
        if ($actor->getId() !== $subject->getId()) {
            throw new CredentialsException('FORBIDDEN', 'Ta operacja dotyczy własnego wniosku.', 403);
        }
    }

    private function notPast(string $start): void
    {
        if ($start < $this->today()) {
            throw new CredentialsException('PAST_REQUEST', 'Własny wniosek można obsłużyć wyłącznie od dzisiejszego dnia.');
        }
    }

    private function today(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
    }

    private function invalidTransition(string $message): never
    {
        throw new CredentialsException('INVALID_TRANSITION', $message, 409);
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
        return ['revision' => $revision->getRevision(), 'kind' => $revision->getKind()->value, 'absenceTypeId' => $revision->getPolicy()->getType()->getId(),
            'policyRevision' => $revision->getPolicy()->getRevision(), 'deductsBalance' => $revision->getPolicy()->deductsBalance(),
            'mode' => $revision->getMode()->value, 'startDate' => $revision->getStartDate()->format('Y-m-d'), 'endDate' => $revision->getEndDate()->format('Y-m-d'),
            'totalHours' => $revision->getTotalHours(),
            'days' => array_map(static fn ($day): array => ['date' => $day->getDate()->format('Y-m-d'), 'hours' => $day->getHours()], $revision->getDays())];
    }

    /** @param list<RequestedDay> $days
     * @return array<string, mixed>
     */
    private function applicationSnapshot(AbsenceTypeVersion $policy, RequestMode $mode, \DateTimeImmutable $start, \DateTimeImmutable $end, array $days): array
    {
        return ['absenceTypeId' => $policy->getType()->getId(), 'policyRevision' => $policy->getRevision(), 'deductsBalance' => $policy->deductsBalance(),
            'mode' => $mode->value, 'startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d'),
            'days' => array_map(static fn (RequestedDay $day): array => ['date' => $day->date->format('Y-m-d'), 'hours' => $day->hours], $days)];
    }

    /** @return array{id: int, reference: string, version: int, revision: int, status: string} */
    private function receipt(User $actor, User $subject, LeaveRequest $request, RequestRevision $revision, string $key, string $hash, string $action): array
    {
        $this->em->flush();
        $receipt = ['id' => $request->getId() ?? throw new \LogicException('Request was not persisted.'),
            'reference' => $request->getReference(), 'version' => $request->getVersion(), 'revision' => $revision->getRevision(), 'status' => RequestState::status($request)];
        $this->em->persist(new AuditEvent($key, 'request.operation_completed', $actor, $subject, $this->clock->now(), [],
            ['payloadHash' => $hash, 'action' => $action, 'receipt' => $receipt], 'request_operation', $request->getReference()));
        $this->em->flush();
        return $receipt;
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(?int $id, string $action, array $data): string
    {
        unset($data['operationKey'], $data['expectedVersion'], $data['previewToken'], $data['confirm']);
        return hash('sha256', json_encode(['action' => $action, 'id' => $id, 'input' => $data], JSON_THROW_ON_ERROR));
    }

    /** A signed confirmation binds the normalized change to current rules and financial state.
     * The global audit marker is deliberately conservative: any committed mutation can stale it.
     * @param array<string, mixed> $data
     */
    private function previewToken(User $actor, User $subject, LeaveRequest $request, array $data): string
    {
        if ('' === $this->previewSecret) { throw new \LogicException('Correction previews require the configured application secret.'); }
        unset($data['operationKey'], $data['previewToken'], $data['confirm'], $data['allowDeficit']);
        $db = $this->em->getConnection();
        $state = [
            'actor' => $actor->getId(), 'request' => $request->getId(), 'version' => $request->getVersion(), 'today' => $this->today(), 'input' => $data,
            'audit' => $db->fetchOne('SELECT MAX(id) FROM audit_event'),
            'users' => $db->fetchAllAssociative('SELECT id, version, credential_version FROM app_user WHERE id IN (?, ?) ORDER BY id', [$actor->getId(), $subject->getId()]),
            'assignments' => $db->fetchAllAssociative('SELECT * FROM staff_assignment WHERE user_id IN (?, ?) ORDER BY id', [$actor->getId(), $subject->getId()]),
            'requests' => $db->fetchAllAssociative('SELECT id, version FROM leave_request WHERE user_id = ? ORDER BY id', [$subject->getId()]),
            'accounts' => $db->fetchAllAssociative('SELECT * FROM leave_account WHERE user_id = ? ORDER BY id', [$subject->getId()]),
            'pools' => $this->accounting->impactSnapshot($subject),
        ];
        foreach (['holiday', 'absence_type', 'absence_type_version', 'absence_type_availability', 'employment_type', 'team'] as $table) {
            // All names are fixed here, never supplied by a caller. Rows have no authentication secrets.
            $rows = $db->fetchAllAssociative('SELECT * FROM '.$table);
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $state[$table] = $rows;
        }
        return hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), $this->previewSecret);
    }

    /** @param list<array{originYear: int, allocatedHours: int, reservedHours: int, chargedHours: int, availableHours: int}> $before
     * @param list<array{originYear: int, allocatedHours: int, reservedHours: int, chargedHours: int, availableHours: int}> $after
     */
    private function increasesDeficit(array $before, array $after): bool
    {
        $old = [];
        foreach ($before as $pool) { $old[$pool['originYear']] = max(0, -$pool['availableHours']); }
        foreach ($after as $pool) {
            if (max(0, -$pool['availableHours']) > ($old[$pool['originYear']] ?? 0)) { return true; }
        }
        return false;
    }

    /** @return list<array{year: int, beforeHours: int, afterHours: int, deltaHours: int}> */
    private function yearImpact(RequestRevision $before, RequestRevision $after, bool $cancel): array
    {
        $old = $new = [];
        foreach ($before->getDays() as $day) {
            $year = (int) $day->getDate()->format('Y');
            $old[$year] = ($old[$year] ?? 0) + ($before->getPolicy()->deductsBalance() ? $day->getHours() : 0);
        }
        if (!$cancel) {
            foreach ($after->getDays() as $day) {
                $year = (int) $day->getDate()->format('Y');
                $new[$year] = ($new[$year] ?? 0) + ($after->getPolicy()->deductsBalance() ? $day->getHours() : 0);
            }
        }
        $years = array_unique([...array_keys($old), ...array_keys($new)]);
        sort($years, SORT_NUMERIC);
        return array_map(static fn (int $year): array => ['year' => $year, 'beforeHours' => $old[$year] ?? 0, 'afterHours' => $new[$year] ?? 0,
            'deltaHours' => ($new[$year] ?? 0) - ($old[$year] ?? 0)], $years);
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
