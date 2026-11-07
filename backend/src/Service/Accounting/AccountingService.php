<?php

namespace App\Service\Accounting;

use App\Entity\AuditEvent;
use App\Entity\DailyOccupancy;
use App\Entity\HourPool;
use App\Entity\LeaveAccount;
use App\Entity\LeaveRequest;
use App\Entity\LedgerEntry;
use App\Entity\OpeningBalance;
use App\Entity\OpeningCarry;
use App\Entity\RequestAccountingState;
use App\Entity\RequestDay;
use App\Entity\RequestDecision;
use App\Entity\RequestRevision;
use App\Entity\User;
use App\Enum\DecisionOutcome;
use App\Enum\LedgerEntryKind;
use App\Enum\RequestChangeKind;
use App\Security\BalanceAccessPolicy;
use App\Security\CurrentAssignmentResolver;
use App\Security\RequestAccessPolicy;
use App\Service\Admin\AdminInput;
use App\Service\CredentialsException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Whole-hour accounting. All mutating entry points serialize against administration and the employee. */
final class AccountingService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private CurrentAssignmentResolver $assignments,
        private BalanceAccessPolicy $access,
        private RequestAccessPolicy $requestAccess,
    ) {
    }

    /** Read authorization belongs to the caller; write authorization is repeated under the locks below.
     * @return array<string, mixed>
     */
    public function getBalance(User $subject, int $year): array
    {
        // A balance consists of several ledger/projection queries. Keep them on one
        // MySQL REPEATABLE READ snapshot, also when called outside a write transaction.
        $connection = $this->em->getConnection();
        if (!$connection->isTransactionActive()) {
            return $connection->transactional(fn (): array => $this->readBalance($subject, $year));
        }
        return $this->readBalance($subject, $year);
    }

    /** @return array<string, mixed> */
    private function readBalance(User $subject, int $year): array
    {
        $this->year($year);
        $account = $this->findAccount($subject, $year);
        $opening = $this->openingValues($subject);
        $carry = $this->carries($subject, $year);
        $pools = $this->poolTotals($subject);
        $allocated = $available = $carried = $openingUsed = $used = $future = $reserved = $changeReserved = 0;
        $today = $this->today()->format('Y-m-d');
        $poolRows = [];
        foreach ($pools as $row) {
            $origin = $row['pool']->getOriginYear();
            $eligible = $this->eligible($subject, $origin, $year, $carry);
            $free = $row['allocated'] - $row['reserved'] - $row['charged'];
            if ($eligible) {
                $allocated += $row['allocated'];
                $available += $free;
                if ($origin < $year) {
                    $carried += $free;
                }
            }
            $poolRows[] = ['originYear' => $origin, 'allocatedHours' => $row['allocated'], 'reservedHours' => $row['reserved'], 'chargedHours' => $row['charged'], 'availableHours' => $free, 'eligible' => $eligible];
        }
        foreach ($this->entries($subject) as $entry) {
            if ($entry->getAccount()->getYear() !== $year) {
                continue;
            }
            $day = $entry->getDay();
            if (null === $day) {
                $openingUsed += $entry->getChargedDelta();
            } elseif ($day->getDate()->format('Y-m-d') <= $today) {
                $used += $entry->getChargedDelta();
            } else {
                $future += $entry->getChargedDelta();
            }
            if (RequestChangeKind::CHANGE === $day?->getRevision()->getKind()) {
                $changeReserved += $entry->getReservedDelta();
            } else {
                $reserved += $entry->getReservedDelta();
            }
        }
        $history = [];
        foreach ($this->em->getRepository(AuditEvent::class)->findBy(['subject' => $subject], ['id' => 'DESC'], 100) as $event) {
            if ('request.operation_completed' === $event->getAction()
                || (!str_starts_with($event->getAction(), 'balance.') && !str_starts_with($event->getAction(), 'opening.') && !str_starts_with($event->getAction(), 'accounting.') && !str_starts_with($event->getAction(), 'calendar.') && !str_starts_with($event->getAction(), 'request.'))) {
                continue;
            }
            $after = $event->getAfterData();
            unset($after['payloadHash']);
            $actor = $event->getActor();
            $history[] = ['id' => $event->getId(), 'action' => $event->getAction(), 'occurredAt' => $event->getOccurredAt()->format(\DATE_ATOM), 'actorName' => null === $actor ? null : $actor->getFirstName().' '.$actor->getLastName(), 'reason' => $after['reason'] ?? null, 'before' => $event->getBeforeData(), 'after' => $after];
        }
        return [
            'userId' => $subject->getId(), 'year' => $year, 'version' => $this->version($subject),
            'configured' => null !== $account?->getAnnualLimitHours(), 'annualHours' => $account?->getAnnualLimitHours(),
            'opening' => $opening, 'carriedHours' => $carried, 'allocatedHours' => $allocated,
            'openingUsedHours' => $openingUsed, 'usedHours' => $used, 'approvedFutureHours' => $future,
            'reservedHours' => $reserved, 'changeReservedHours' => $changeReserved,
            'availableHours' => $available, 'deficitHours' => max(0, -$available),
            'displayAvailable' => Hours::display($available), 'pools' => $poolRows, 'history' => $history,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function initializeOpening(User $actor, User $subject, array $data): array
    {
        AdminInput::only($data, ['operationKey', 'expectedVersion', 'effectiveDate', 'annualHours', 'currentYearUsedHours', 'carries']);
        $date = AdminInput::date($data, 'effectiveDate');
        $year = (int) $date->format('Y');
        $this->year($year);
        $annual = Hours::nonnegative($data['annualHours'] ?? null, 'annualHours');
        $used = Hours::nonnegative($data['currentYearUsedHours'] ?? null, 'currentYearUsedHours');
        $carries = $this->carryInput($data['carries'] ?? null, $year);
        return $this->em->wrapInTransaction(function () use ($actor, $subject, $data, $date, $year, $annual, $used, $carries): array {
            $this->start($actor, $subject);
            $this->manage($actor, $subject);
            if ($this->replayed($actor, $subject, 'balance.opening_initialized', $data)) {
                return $this->getBalance($subject, $year);
            }
            $this->checkVersion($subject, $data);
            if (null !== $this->em->getRepository(OpeningBalance::class)->findOneBy(['user' => $subject]) || [] !== $this->entries($subject)) {
                throw new CredentialsException('OPENING_EXISTS', 'Dane początkowe lub rozliczenia już istnieją. Użyj korekty.', 409);
            }
            $this->validateCarries($subject, $date, $carries);
            $account = $this->account($subject, $year);
            if (null !== $account->getAnnualLimitHours()) {
                throw new CredentialsException('OPENING_EXISTS', 'Limit jest już skonfigurowany. Dane początkowe należy wprowadzić przed przydziałami.', 409);
            }
            $values = ['effectiveDate' => $date->format('Y-m-d'), 'annualHours' => $annual, 'currentYearUsedHours' => $used, 'carries' => $carries];
            $event = $this->event($actor, $subject, 'balance.opening_initialized', $data, [], ['opening' => $values]);
            $opening = new OpeningBalance($subject, $date, $annual, $used, $event);
            foreach ($carries as $row) {
                new OpeningCarry($opening, $row['originYear'], $row['remainingHours']);
            }
            $this->em->persist($opening);
            $account->setAnnualLimitHours($annual);
            $pool = $this->pool($subject, $year);
            if ($annual > 0) {
                $this->line($account, $pool, $event, LedgerEntryKind::GRANT, $annual, 0, 0);
            }
            if ($used > 0) {
                $this->line($account, $pool, $event, LedgerEntryKind::OPENING_USED, 0, 0, $used);
            }
            foreach ($carries as $row) {
                $oldPool = $this->pool($subject, $row['originYear']);
                if ($row['remainingHours'] > 0) {
                    $this->line($account, $oldPool, $event, LedgerEntryKind::GRANT, $row['remainingHours'], 0, 0);
                }
            }
            $this->em->flush();
            return $this->getBalance($subject, $year);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function setAnnualLimit(User $actor, User $subject, int $year, array $data): array
    {
        $this->year($year);
        AdminInput::only($data, ['operationKey', 'expectedVersion', 'annualHours', 'reason']);
        $hours = Hours::nonnegative($data['annualHours'] ?? null, 'annualHours');
        return $this->em->wrapInTransaction(function () use ($actor, $subject, $year, $data, $hours): array {
            $this->start($actor, $subject);
            $this->manage($actor, $subject);
            $payload = $data + ['year' => $year];
            if ($this->replayed($actor, $subject, 'balance.limit_set', $payload)) {
                return $this->getBalance($subject, $year);
            }
            $this->checkVersion($subject, $data);
            $opening = $this->openingValues($subject);
            if (null === $opening) {
                throw new CredentialsException('OPENING_REQUIRED', 'Najpierw wprowadź datę rozpoczęcia ewidencji i dane początkowe.');
            }
            if ($year < (int) substr($opening['effectiveDate'], 0, 4)) {
                throw new CredentialsException('BEFORE_OPENING', 'Starsze pule popraw przez korektę danych początkowych.');
            }
            $account = $this->account($subject, $year);
            $before = $account->getAnnualLimitHours();
            if (null !== $before) {
                $this->reason($data);
            }
            $event = $this->event($actor, $subject, 'balance.limit_set', $payload, ['year' => $year, 'annualHours' => $before], ['year' => $year, 'annualHours' => $hours, 'reason' => $data['reason'] ?? null]);
            $difference = $hours - ($before ?? 0);
            $account->setAnnualLimitHours($hours);
            if (0 !== $difference) {
                $this->line($account, $this->pool($subject, $year), $event, $difference > 0 && null === $before ? LedgerEntryKind::GRANT : LedgerEntryKind::ADJUSTMENT, $difference, 0, 0);
            }
            $this->em->flush();
            return $this->getBalance($subject, $year);
        });
    }

    /** Absolute current initial values, never replacement of the immutable original snapshot.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function correctOpening(User $actor, User $subject, array $data): array
    {
        AdminInput::only($data, ['operationKey', 'expectedVersion', 'effectiveDate', 'currentYearUsedHours', 'carries', 'reason']);
        $this->reason($data);
        $used = Hours::nonnegative($data['currentYearUsedHours'] ?? null, 'currentYearUsedHours');
        return $this->em->wrapInTransaction(function () use ($actor, $subject, $data, $used): array {
            $this->start($actor, $subject);
            $this->manage($actor, $subject);
            $before = $this->openingValues($subject);
            if (null === $before) {
                throw new CredentialsException('OPENING_REQUIRED', 'Brak danych początkowych do skorygowania.');
            }
            $year = (int) substr($before['effectiveDate'], 0, 4);
            if ($this->replayed($actor, $subject, 'balance.opening_corrected', $data)) {
                return $this->getBalance($subject, $year);
            }
            $this->checkVersion($subject, $data);
            $date = array_key_exists('effectiveDate', $data) ? AdminInput::date($data, 'effectiveDate') : $this->date($before['effectiveDate']);
            if ((int) $date->format('Y') !== $year) {
                throw new CredentialsException('OPENING_YEAR_LOCKED', 'Zmiana roku rozpoczęcia ewidencji wymaga osobnej migracji danych.');
            }
            if ($date->format('Y-m-d') !== $before['effectiveDate'] && 0 !== $this->em->getRepository(LeaveRequest::class)->count(['user' => $subject])) {
                throw new CredentialsException('OPENING_DATE_LOCKED', 'Data rozpoczęcia jest zablokowana po wprowadzeniu wniosków.', 409);
            }
            $carries = $this->carryInput($data['carries'] ?? null, $year);
            $this->validateCarries($subject, $date, $carries);
            $after = ['effectiveDate' => $date->format('Y-m-d'), 'annualHours' => $before['annualHours'], 'currentYearUsedHours' => $used, 'carries' => $carries];
            $event = $this->event($actor, $subject, 'balance.opening_corrected', $data, ['opening' => $before], ['opening' => $after, 'reason' => $data['reason']]);
            $account = $this->account($subject, $year);
            $delta = $used - $before['currentYearUsedHours'];
            if (0 !== $delta) {
                $this->line($account, $this->pool($subject, $year), $event, LedgerEntryKind::ADJUSTMENT, 0, 0, $delta);
            }
            $old = [];
            foreach ($before['carries'] as $row) { $old[$row['originYear']] = $row['remainingHours']; }
            $new = [];
            foreach ($carries as $row) { $new[$row['originYear']] = $row['remainingHours']; }
            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $origin) {
                $change = ($new[$origin] ?? 0) - ($old[$origin] ?? 0);
                if (0 !== $change) {
                    $this->line($account, $this->pool($subject, $origin), $event, LedgerEntryKind::ADJUSTMENT, $change, 0, 0);
                }
            }
            $account->touch();
            $this->em->flush();
            return $this->getBalance($subject, $year);
        });
    }

    /** The origin pool stays shared; settlement records availability, never duplicates its hours.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function settleYear(User $actor, User $subject, int $year, array $data): array
    {
        $this->year($year);
        AdminInput::only($data, ['operationKey', 'expectedVersion']);
        return $this->em->wrapInTransaction(function () use ($actor, $subject, $year, $data): array {
            $this->start($actor, $subject);
            $this->manage($actor, $subject);
            if ($year > (int) $this->today()->format('Y')) {
                throw new CredentialsException('YEAR_NOT_STARTED', 'Zaległość można udostępnić dopiero po rozpoczęciu roku.');
            }
            if ($this->replayed($actor, $subject, 'balance.year_settled', $data + ['year' => $year])) {
                return $this->getBalance($subject, $year);
            }
            $this->checkVersion($subject, $data);
            $existing = $this->em->getRepository(AuditEvent::class)->findOneBy(['subject' => $subject, 'action' => 'balance.year_settled', 'targetReference' => (string) $year]);
            if (null === $existing) {
                $balance = $this->getBalance($subject, $year);
                if (null === $balance['opening']) {
                    throw new CredentialsException('OPENING_REQUIRED', 'Najpierw wprowadź dane początkowe.');
                }
                $this->event($actor, $subject, 'balance.year_settled', $data + ['year' => $year], [], ['year' => $year, 'carriedHours' => $balance['carriedHours'], 'policy' => 'shared_origin_pool'], (string) $year);
                $this->em->flush();
            }
            return $this->getBalance($subject, $year);
        });
    }

    /** @return array<string, mixed> */
    public function reserveRevision(User $actor, RequestRevision $revision, string $operationKey): array
    {
        return $this->workflow($actor, $revision, $operationKey, 'reserve', null);
    }

    /** @return array<string, mixed> */
    public function releaseRevision(User $actor, RequestRevision $revision, string $operationKey, DecisionOutcome $outcome = DecisionOutcome::WITHDRAWN): array
    {
        if (!\in_array($outcome, [DecisionOutcome::WITHDRAWN, DecisionOutcome::REJECTED, DecisionOutcome::SUPERSEDED], true)) {
            throw new CredentialsException('INVALID_TRANSITION', 'Nieprawidłowy sposób zwolnienia rezerwacji.');
        }
        return $this->workflow($actor, $revision, $operationKey, 'release', $outcome);
    }

    /** @return array<string, mixed> */
    public function approveRevision(User $actor, RequestRevision $revision, string $operationKey): array
    {
        return $this->workflow($actor, $revision, $operationKey, 'approve', DecisionOutcome::APPROVED);
    }

    /**
     * Internal transaction primitive for the calendar and future workflows. Caller authorizes
     * the workflow transition and locks administration, users and requests before mutating them.
     * This method neither commits nor changes decisions. It reconciles the exact effective and
     * pending snapshots, preserving the immutable event/ledger trail and daily occupancy.
     */
    public function reconcileRequest(LeaveRequest $request, AuditEvent $event, bool $allowDeficit = false): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) {
            throw new \LogicException('Request reconciliation requires an enclosing transaction.');
        }
        if ($event->getSubject() !== $request->getUser() || null === $event->getActor()) {
            throw new \LogicException('The accounting event must identify the employee and actor.');
        }
        if ($allowDeficit && !$event->getActor()->isAdministrator()) {
            throw new CredentialsException('FORBIDDEN', 'Tylko jawna korekta administratora może zwiększyć niedobór.', 403);
        }
        $this->em->getConnection()->executeQuery('SELECT id FROM administration_lock WHERE id = 1 FOR UPDATE')->fetchOne();
        $this->em->persist($event);
        $this->em->flush();
        $subject = $request->getUser();
        $opening = $this->openingValues($subject);
        $state = $this->em->getRepository(RequestAccountingState::class)->findOneBy(['request' => $request]);
        if (null === $state) {
            $state = new RequestAccountingState($request);
            $this->em->persist($state);
            // Legacy demo created after migrations already has a posted occupancy projection.
            $legacy = $this->requestLedger($request);
            if ([] !== $legacy) {
                $legacyOccupancy = [];
                foreach ($legacy as $row) {
                    if ($row['reserved'] + $row['charged'] > 0) {
                        $key = $row['day']->getDate()->format('Y-m-d');
                        $legacyOccupancy[$key] = ($legacyOccupancy[$key] ?? 0) + $row['reserved'] + $row['charged'];
                    }
                }
                $state->replaceOccupancy($legacyOccupancy);
            }
        }
        $oldLedger = $this->requestLedger($request);
        $oldBudget = [];
        $oldCurrentPoolFunding = [];
        foreach ($oldLedger as $row) {
            $year = $row['account']->getYear();
            $oldBudget[$year] = ($oldBudget[$year] ?? 0) + $row['reserved'] + $row['charged'];
            if ($row['pool']->getOriginYear() === $year) {
                $oldCurrentPoolFunding[$year] = ($oldCurrentPoolFunding[$year] ?? 0) + $row['reserved'] + $row['charged'];
            }
            if (0 !== $row['reserved'] || 0 !== $row['charged']) {
                $this->line($row['account'], $row['pool'], $event, LedgerEntryKind::ADJUSTMENT, 0, -$row['reserved'], -$row['charged'], $row['day']);
            }
        }
        $this->em->flush();
        $effective = $request->getEffectiveRevision();
        $pending = $request->getPendingRevision();
        $desiredOccupancy = [];
        $effectiveHours = [];
        /** @var list<array{day: RequestDay, amount: int, charged: bool}> $desired */
        $desired = [];
        foreach ($effective?->getDays() ?? [] as $day) {
            $key = $day->getDate()->format('Y-m-d');
            $desiredOccupancy[$key] = $day->getHours();
            if ($effective->getPolicy()->deductsBalance()) {
                $effectiveHours[$key] = $day->getHours();
                $desired[] = ['day' => $day, 'amount' => $day->getHours(), 'charged' => true];
            }
        }
        if (null !== $pending && RequestChangeKind::CANCELLATION !== $pending->getKind()) {
            foreach ($pending->getDays() as $day) {
                $key = $day->getDate()->format('Y-m-d');
                $desiredOccupancy[$key] = max($desiredOccupancy[$key] ?? 0, $day->getHours());
                if ($pending->getPolicy()->deductsBalance()) {
                    $extra = max(0, $day->getHours() - ($effectiveHours[$key] ?? 0));
                    if ($extra > 0) { $desired[] = ['day' => $day, 'amount' => $extra, 'charged' => false]; }
                }
            }
        }
        foreach (array_keys($desiredOccupancy) as $date) {
            if (null === $opening || $date < $opening['effectiveDate']) {
                throw new CredentialsException('BEFORE_OPENING', 'Nieobecności sprzed rozpoczęcia ewidencji popraw przez dane początkowe.');
            }
        }
        $newBudget = [];
        $newNonCarryBudget = [];
        foreach ($desired as $item) {
            $day = $item['day'];
            $year = (int) $day->getDate()->format('Y');
            $newBudget[$year] = ($newBudget[$year] ?? 0) + $item['amount'];
            if (!$day->getAssignment()->getEmploymentType()->carriesUnusedHours()) {
                $newNonCarryBudget[$year] = ($newNonCarryBudget[$year] ?? 0) + $item['amount'];
            }
            if (null === $opening || $day->getDate()->format('Y-m-d') < $opening['effectiveDate']) {
                throw new CredentialsException('BEFORE_OPENING', 'Nieobecności sprzed rozpoczęcia ewidencji popraw przez dane początkowe.');
            }
            if (null === $this->findAccount($subject, $year)?->getAnnualLimitHours()) {
                throw new CredentialsException('LIMIT_NOT_CONFIGURED', 'Brak ustawionego limitu na rok '.$year.'.');
            }
        }
        usort($desired, static fn (array $a, array $b): int => [$a['day']->getDate()->format('Y-m-d'), !$a['charged']] <=> [$b['day']->getDate()->format('Y-m-d'), !$b['charged']]);
        foreach ($desired as $item) {
            $year = (int) $item['day']->getDate()->format('Y');
            // An existing deficit may be retained, but changing to non-carry employment
            // cannot turn an old carry-funded commitment into an unfunded annual one.
            // Both the whole year's commitment and its restricted current-pool subset matter.
            $preservesCommitment = ($newBudget[$year] ?? 0) <= ($oldBudget[$year] ?? 0)
                && ($newNonCarryBudget[$year] ?? 0) <= ($oldCurrentPoolFunding[$year] ?? 0);
            $this->allocate($item['day'], $item['amount'], $item['charged'], $event, $allowDeficit || $preservesCommitment);
        }
        foreach (array_unique([...array_keys($state->getOccupancy()), ...array_keys($desiredOccupancy)]) as $date) {
            $delta = ($desiredOccupancy[$date] ?? 0) - ($state->getOccupancy()[$date] ?? 0);
            if (0 === $delta) { continue; }
            $day = $this->date($date);
            $occupancy = $this->em->getRepository(DailyOccupancy::class)->findOneBy(['user' => $subject, 'date' => $day]);
            if (null === $occupancy) {
                $occupancy = new DailyOccupancy($subject, $day);
                $this->em->persist($occupancy);
            }
            try { $occupancy->changeBy($delta); } catch (\DomainException) {
                throw new CredentialsException('DAILY_CAPACITY_EXCEEDED', 'Łączna nieobecność w dniu '.$date.' przekroczyłaby 8 godzin.', 409);
            }
        }
        $state->replaceOccupancy($desiredOccupancy);
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function workflow(User $actor, RequestRevision $revision, string $operationKey, string $operation, ?DecisionOutcome $outcome): array
    {
        return $this->em->wrapInTransaction(function () use ($actor, $revision, $operationKey, $operation, $outcome): array {
            $subject = $revision->getRequest()->getUser();
            $this->start($actor, $subject);
            $request = $revision->getRequest();
            $this->em->lock($request, LockMode::PESSIMISTIC_WRITE);
            $payload = ['operationKey' => $operationKey, 'request' => $request->getReference(), 'revision' => $revision->getRevision(), 'outcome' => $outcome?->value];
            if ($this->replayed($actor, $subject, 'accounting.'.$operation, $payload)) {
                if (!$this->requestAccess->canViewRevisionDetails($actor, $revision, $this->clock->now())) {
                    throw new CredentialsException('FORBIDDEN', 'Brak aktualnych uprawnień do tego wniosku.', 403);
                }
                return ['requestReference' => $request->getReference(), 'revision' => $revision->getRevision(), 'operation' => $operation, 'outcome' => $outcome?->value];
            }
            // A caller may have hydrated the revision before another transaction decided it.
            // The fresh database transition check must precede reconciliation of that stale graph.
            if (null !== $revision->getId() && false !== $this->em->getConnection()->fetchOne('SELECT id FROM request_decision WHERE revision_id = ?', [$revision->getId()])) {
                throw new CredentialsException('INVALID_TRANSITION', 'Wniosek został już rozstrzygnięty. Odśwież dane.', 409);
            }
            if (null !== $revision->getDecision() || $revision !== $request->getPendingRevision()) {
                throw new CredentialsException('INVALID_TRANSITION', 'Wniosek nie oczekuje już na tę operację.', 409);
            }
            $self = $actor->getId() === $subject->getId();
            if ('approve' === $operation || DecisionOutcome::REJECTED === $outcome) {
                $allowed = $this->requestAccess->canDecideRevision($actor, $revision, $this->clock->now());
            } else {
                $allowed = $self || $this->requestAccess->canDecideRevision($actor, $revision, $this->clock->now());
            }
            if (!$allowed) { throw new CredentialsException('FORBIDDEN', 'Brak uprawnień do obsługi tego wniosku.', 403); }
            $event = $this->event($actor, $subject, 'accounting.'.$operation, $payload, [], ['request' => $request->getReference(), 'revision' => $revision->getRevision(), 'outcome' => $outcome?->value]);
            if (null !== $outcome) { $this->em->persist(new RequestDecision($revision, $outcome, $event)); }
            $this->reconcileRequest($request, $event);
            // A former team's manager can decide its historical absence but cannot read the
            // employee's current balance. Workflow receipts therefore contain no balance data.
            return ['requestReference' => $request->getReference(), 'revision' => $revision->getRevision(), 'operation' => $operation, 'outcome' => $outcome?->value];
        });
    }

    private function allocate(RequestDay $day, int $amount, bool $charged, AuditEvent $event, bool $allowDeficit): void
    {
        $subject = $day->getUser();
        $year = (int) $day->getDate()->format('Y');
        $carry = $day->getAssignment()->getEmploymentType()->carriesUnusedHours();
        $eligible = array_values(array_filter($this->poolTotals($subject), fn (array $row): bool => $this->eligible($subject, $row['pool']->getOriginYear(), $year, $carry)));
        $free = array_sum(array_map(static fn (array $row): int => $row['allocated'] - $row['reserved'] - $row['charged'], $eligible));
        if (!$allowDeficit && $free < $amount) {
            throw new CredentialsException('INSUFFICIENT_BALANCE', 'Brak dostępnego salda na rok '.$year.'.');
        }
        $account = $this->account($subject, $year);
        foreach ($eligible as $row) {
            $part = min($amount, max(0, $row['allocated'] - $row['reserved'] - $row['charged']));
            if ($part < 1) { continue; }
            $this->line($account, $row['pool'], $event, $charged ? LedgerEntryKind::ADJUSTMENT : LedgerEntryKind::RESERVE, 0, $charged ? 0 : $part, $charged ? $part : 0, $day);
            $amount -= $part;
            if (0 === $amount) { break; }
        }
        if ($amount > 0) {
            $this->line($account, $this->pool($subject, $year), $event, LedgerEntryKind::ADJUSTMENT, 0, $charged ? 0 : $amount, $charged ? $amount : 0, $day);
        }
        $this->em->flush();
    }

    private function start(User $actor, User $subject): void
    {
        $this->em->getConnection()->executeQuery('SELECT id FROM administration_lock WHERE id = 1 FOR UPDATE')->fetchOne();
        $users = [];
        foreach ([$actor, $subject] as $user) {
            $users[$user->getId() ?? throw new \LogicException('Persisted users are required.')] = $user;
        }
        ksort($users);
        foreach ($users as $user) {
            $this->em->lock($user, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($user);
            foreach ($user->getAssignments() as $assignment) { $this->em->refresh($assignment); }
        }
        if (!$actor->isActive()) { throw new CredentialsException('FORBIDDEN', 'Konto nie jest aktywne.', 403); }
    }

    private function manage(User $actor, User $subject): void
    {
        if (!$this->access->canManageBalance($actor, $subject, $this->clock->now())) {
            throw new CredentialsException('FORBIDDEN', 'Nie możesz zmieniać limitów tej osoby. Własny limit kierownika ustawia administrator.', 403);
        }
    }

    /** @param array<string, mixed> $data */
    private function checkVersion(User $subject, array $data): void
    {
        if (($data['expectedVersion'] ?? null) !== $this->version($subject)) {
            throw new CredentialsException('CONFLICT', 'Saldo lub ustawienia zmieniły się. Odśwież dane przed zapisem.', 409);
        }
    }

    private function version(User $subject): string
    {
        $events = [];
        foreach ($this->em->getRepository(AuditEvent::class)->findBy(['subject' => $subject], ['id' => 'ASC']) as $event) { $events[] = $event->getId(); }
        $accounts = [];
        foreach ($this->em->getRepository(LeaveAccount::class)->findBy(['user' => $subject], ['year' => 'ASC']) as $account) { $accounts[] = [$account->getYear(), $account->getVersion(), $account->getAnnualLimitHours()]; }
        $pools = [];
        foreach ($this->em->getRepository(HourPool::class)->findBy(['user' => $subject], ['originYear' => 'ASC']) as $pool) { $pools[] = [$pool->getOriginYear(), $pool->getVersion()]; }
        return hash('sha256', json_encode([$subject->getId(), $subject->getVersion(), $this->today()->format('Y-m-d'), $accounts, $pools, $events], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    private function replayed(User $actor, User $subject, string $action, array $data): bool
    {
        $key = $this->operationKey($subject, $data);
        $existing = $this->em->getRepository(AuditEvent::class)->findOneBy(['operationKey' => $key]);
        if (null === $existing) { return false; }
        if ($existing->getAction() !== $action || $existing->getActor()?->getId() !== $actor->getId() || $existing->getAfterData()['payloadHash'] !== $this->payloadHash($data)) {
            throw new CredentialsException('IDEMPOTENCY_CONFLICT', 'Klucz operacji został już użyty z innymi danymi.', 409);
        }
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function event(User $actor, User $subject, string $action, array $data, array $before, array $after, ?string $reference = null): AuditEvent
    {
        $event = new AuditEvent($this->operationKey($subject, $data), $action, $actor, $subject, $this->clock->now(), $before, $after + ['payloadHash' => $this->payloadHash($data)], 'balance', $reference ?? (string) $subject->getId());
        $this->em->persist($event);
        return $event;
    }

    /** @param array<string, mixed> $data */
    private function operationKey(User $subject, array $data): string
    {
        $key = $data['operationKey'] ?? null;
        if (!\is_string($key) || 1 !== preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $key)) {
            throw new CredentialsException('VALIDATION_FAILED', 'Operacja wymaga identyfikatora złożonego z 1–64 znaków ASCII.');
        }
        return 'balance:'.$subject->getId().':'.$key;
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(array $data): string
    {
        unset($data['expectedVersion'], $data['operationKey']);
        ksort($data);
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function line(LeaveAccount $account, HourPool $pool, AuditEvent $event, LedgerEntryKind $kind, int $allocated, int $reserved, int $charged, ?RequestDay $day = null): void
    {
        // Queries need the generated event identifier; flush prior immutable lines as part of this transaction.
        $this->em->flush();
        $line = $this->em->getRepository(LedgerEntry::class)->count(['event' => $event]) + 1;
        $this->em->persist(new LedgerEntry($account, $pool, $event, $line, $kind, $allocated, $reserved, $charged, $day));
    }

    /** @return list<LedgerEntry> */
    private function entries(User $subject): array
    {
        return $this->em->createQuery('SELECT l FROM App\\Entity\\LedgerEntry l JOIN l.account a WHERE a.user = :user ORDER BY l.id ASC')->setParameter('user', $subject)->getResult();
    }

    /** @return list<array{pool: HourPool, allocated: int, reserved: int, charged: int}> */
    private function poolTotals(User $subject): array
    {
        $rows = [];
        foreach ($this->em->getRepository(HourPool::class)->findBy(['user' => $subject], ['originYear' => 'ASC']) as $pool) {
            $rows[$pool->getOriginYear()] = ['pool' => $pool, 'allocated' => 0, 'reserved' => 0, 'charged' => 0];
        }
        foreach ($this->entries($subject) as $entry) {
            $origin = $entry->getPool()->getOriginYear();
            $rows[$origin]['allocated'] += $entry->getAllocatedDelta();
            $rows[$origin]['reserved'] += $entry->getReservedDelta();
            $rows[$origin]['charged'] += $entry->getChargedDelta();
        }
        ksort($rows);
        return array_values($rows);
    }

    /** @return list<array{account: LeaveAccount, pool: HourPool, day: RequestDay, reserved: int, charged: int}> */
    private function requestLedger(LeaveRequest $request): array
    {
        /** @var list<LedgerEntry> $entries */
        $entries = $this->em->createQuery('SELECT l FROM App\\Entity\\LedgerEntry l JOIN l.day d JOIN d.revision r WHERE r.request = :request ORDER BY l.id ASC')->setParameter('request', $request)->getResult();
        $rows = [];
        foreach ($entries as $entry) {
            $day = $entry->getDay() ?? throw new \LogicException('Joined day is required.');
            $key = $day->getId().':'.$entry->getPool()->getId();
            $rows[$key] ??= ['account' => $entry->getAccount(), 'pool' => $entry->getPool(), 'day' => $day, 'reserved' => 0, 'charged' => 0];
            $rows[$key]['reserved'] += $entry->getReservedDelta();
            $rows[$key]['charged'] += $entry->getChargedDelta();
        }
        return array_values($rows);
    }

    private function findAccount(User $subject, int $year): ?LeaveAccount
    {
        return $this->em->getRepository(LeaveAccount::class)->findOneBy(['user' => $subject, 'year' => $year]);
    }

    private function account(User $subject, int $year): LeaveAccount
    {
        $account = $this->findAccount($subject, $year);
        if (null === $account) { $account = new LeaveAccount($subject, $year); $this->em->persist($account); $this->em->flush(); }
        return $account;
    }

    private function pool(User $subject, int $year): HourPool
    {
        $pool = $this->em->getRepository(HourPool::class)->findOneBy(['user' => $subject, 'originYear' => $year]);
        if (null === $pool) { $pool = new HourPool($subject, $year); $this->em->persist($pool); $this->em->flush(); }
        return $pool;
    }

    /** @return array{effectiveDate: string, annualHours: int, currentYearUsedHours: int, carries: list<array{originYear: int, remainingHours: int}>}|null */
    private function openingValues(User $subject): ?array
    {
        $opening = $this->em->getRepository(OpeningBalance::class)->findOneBy(['user' => $subject]);
        if (null === $opening) { return null; }
        $values = ['effectiveDate' => $opening->getEffectiveDate()->format('Y-m-d'), 'annualHours' => $opening->getAnnualHours(), 'currentYearUsedHours' => $opening->getCurrentYearUsedHours(), 'carries' => array_map(static fn (OpeningCarry $carry): array => ['originYear' => $carry->getOriginYear(), 'remainingHours' => $carry->getRemainingHours()], $opening->getCarries())];
        $latest = $this->em->getRepository(AuditEvent::class)->findOneBy(['subject' => $subject, 'action' => 'balance.opening_corrected'], ['id' => 'DESC']);
        if (null !== $latest) {
            /** @var array{effectiveDate: string, annualHours: int, currentYearUsedHours: int, carries: list<array{originYear: int, remainingHours: int}>} $values */
            $values = $latest->getAfterData()['opening'];
        }
        $values['annualHours'] = $this->findAccount($subject, (int) substr($values['effectiveDate'], 0, 4))?->getAnnualLimitHours() ?? $values['annualHours'];
        return $values;
    }

    /** @return list<array{originYear: int, remainingHours: int}> */
    private function carryInput(mixed $input, int $year): array
    {
        if (!\is_array($input) || !array_is_list($input) || count($input) > 100) { throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowa lista zaległości.'); }
        $rows = [];
        foreach ($input as $row) {
            if (!\is_array($row)) { throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowa pula zaległości.'); }
            AdminInput::only($row, ['originYear', 'remainingHours']);
            $origin = $row['originYear'] ?? null;
            if (!\is_int($origin) || $origin < 1000 || $origin >= $year || isset($rows[$origin])) { throw new CredentialsException('VALIDATION_FAILED', 'Rok zaległości musi być wcześniejszy i wystąpić tylko raz.'); }
            $rows[$origin] = ['originYear' => $origin, 'remainingHours' => Hours::nonnegative($row['remainingHours'] ?? null, 'remainingHours')];
        }
        ksort($rows);
        return array_values($rows);
    }

    /** @param list<array{originYear: int, remainingHours: int}> $carries */
    private function validateCarries(User $subject, \DateTimeImmutable $date, array $carries): void
    {
        if ([] !== $carries && true !== $this->assignments->resolve($subject, $date)?->getEmploymentType()->carriesUnusedHours()) {
            throw new CredentialsException('CARRY_NOT_ALLOWED', 'Zaległości przysługują tylko formie zatrudnienia z przenoszeniem godzin (UoP).');
        }
    }

    private function carries(User $subject, int $year): bool
    {
        $today = $this->today();
        $date = $year === (int) $today->format('Y') ? $today : $this->date($year.($year > (int) $today->format('Y') ? '-01-01' : '-12-31'));
        return true === $this->assignments->resolve($subject, $date)?->getEmploymentType()->carriesUnusedHours();
    }

    private function eligible(User $subject, int $origin, int $year, bool $carries): bool
    {
        if ($origin === $year) {
            return true;
        }
        return $origin < $year && $carries && $year <= (int) $this->today()->format('Y')
            && ($this->wasImportedCarry($subject, $origin)
                || true === $this->assignments->resolve($subject, $this->date($origin.'-12-31'))?->getEmploymentType()->carriesUnusedHours());
    }

    /** Imported remaining balances already certify their origin; no fabricated employment
     * history is required before the accounting boundary. Retain provenance after a correction
     * removes an imported amount so an existing charge/deficit cannot disappear from the view.
     */
    private function wasImportedCarry(User $subject, int $origin): bool
    {
        $opening = $this->em->getRepository(OpeningBalance::class)->findOneBy(['user' => $subject]);
        foreach ($opening?->getCarries() ?? [] as $carry) {
            if ($carry->getOriginYear() === $origin) {
                return true;
            }
        }
        foreach ($this->em->getRepository(AuditEvent::class)->findBy(['subject' => $subject, 'action' => 'balance.opening_corrected']) as $event) {
            $data = $event->getAfterData();
            if (!isset($data['opening']) || !\is_array($data['opening']) || !isset($data['opening']['carries']) || !\is_array($data['opening']['carries'])) {
                continue;
            }
            foreach ($data['opening']['carries'] as $carry) {
                if (\is_array($carry) && ($carry['originYear'] ?? null) === $origin) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string, mixed> $data */
    private function reason(array $data): string { return trim(AdminInput::string($data, 'reason', 1000)); }
    private function year(int $year): void { if ($year < 2025 || $year > 2100) { throw new CredentialsException('INVALID_YEAR', 'Obsługiwane lata ewidencji: 2025–2100.'); } }
    private function today(): \DateTimeImmutable { return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->setTime(0, 0); }
    private function date(string $date): \DateTimeImmutable { return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw')); }
}
