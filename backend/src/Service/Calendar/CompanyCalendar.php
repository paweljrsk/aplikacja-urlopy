<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\AuditEvent;
use App\Entity\Holiday;
use App\Entity\LeaveRequest;
use App\Entity\RequestDay;
use App\Entity\RequestDecision;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\User;
use App\Enum\DecisionOutcome;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Model\RequestedDay;
use App\Service\Accounting\AccountingService;
use App\Service\Admin\AdminInput;
use App\Service\CredentialsException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Administrator confirms a fresh, all-or-nothing calendar/request/ledger change. */
final class CompanyCalendar
{
    public function __construct(private EntityManagerInterface $em, private WorkingCalendar $calendar, private AccountingService $accounting, private ClockInterface $clock)
    {
    }

    /** @return array<string, mixed> */
    public function list(int $year): array
    {
        $holidays = [];
        foreach ($this->calendar->statutoryHolidays($year) as $date => $name) {
            $holidays[] = ['date' => $date, 'name' => $name, 'source' => 'statutory', 'active' => true];
        }
        foreach ($this->em->getRepository(Holiday::class)->findAll() as $holiday) {
            if ((int) $holiday->getDate()->format('Y') === $year) {
                $holidays[] = ['date' => $holiday->getDate()->format('Y-m-d'), 'name' => $holiday->getName(), 'source' => 'company', 'active' => $holiday->isActive()];
            }
        }
        usort($holidays, static fn (array $a, array $b): int => [$a['date'], $a['source']] <=> [$b['date'], $b['source']]);
        return ['year' => $year, 'timezone' => 'Europe/Warsaw', 'hoursPerDay' => WorkingCalendar::HOURS_PER_DAY, 'minimumYear' => WorkingCalendar::MINIMUM_YEAR, 'maximumYear' => WorkingCalendar::MAXIMUM_YEAR, 'holidays' => $holidays];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, array $data): array
    {
        AdminInput::only($data, ['date', 'name', 'active']);
        return $this->em->wrapInTransaction(function () use ($actor, $data): array {
            $this->start($actor);
            $change = $this->change($data);
            return $this->buildPreview($actor, $change);
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function apply(User $actor, array $data): array
    {
        AdminInput::only($data, ['date', 'name', 'active', 'previewToken', 'operationKey', 'reason']);
        return $this->em->wrapInTransaction(function () use ($actor, $data): array {
            $this->start($actor);
            $change = $this->change($data, false);
            $reason = trim(AdminInput::string($data, 'reason', 1000));
            $key = AdminInput::string($data, 'operationKey', 64);
            if (1 !== preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $key)) {
                throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowy identyfikator operacji.');
            }
            $key = 'calendar:'.$actor->getId().':'.$key;
            $token = AdminInput::string($data, 'previewToken', 64);
            $inputHash = hash('sha256', json_encode([$change, $token, $reason], JSON_THROW_ON_ERROR));
            $prior = $this->em->getRepository(AuditEvent::class)->findOneBy(['operationKey' => $key]);
            if (null !== $prior) {
                $saved = $prior->getAfterData();
                if (($saved['inputHash'] ?? null) !== $inputHash || !\is_array($saved['result'] ?? null)) {
                    throw new CredentialsException('IDEMPOTENCY_CONFLICT', 'Ten identyfikator operacji został już użyty dla innych danych.', 409);
                }
                return $saved['result'];
            }
            $this->change($data);
            $requests = $this->affectedRequests($change['date']);
            $users = [$actor->getId() => $actor];
            foreach ($requests as $request) {
                $users[$request->getUser()->getId()] = $request->getUser();
            }
            ksort($users);
            foreach ($users as $user) {
                $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            }
            $this->administrator($actor);
            foreach ($requests as $request) {
                $this->em->refresh($request, LockMode::PESSIMISTIC_WRITE);
            }
            $preview = $this->buildPreview($actor, $change, $requests);
            if (!hash_equals((string) $preview['previewToken'], $token)) {
                throw new CredentialsException('CALENDAR_PREVIEW_STALE', 'Dane zmieniły się od podglądu. Wygeneruj nowy podgląd i sprawdź wpływ zmiany.', 409);
            }
            $holiday = $this->holiday($change['date']);
            $before = null === $holiday ? [] : ['date' => $change['date'], 'name' => $holiday->getName(), 'active' => $holiday->isActive()];
            if (null === $holiday) {
                $holiday = new Holiday(new \DateTimeImmutable($change['date'], new \DateTimeZone('Europe/Warsaw')), $change['name']);
                $this->em->persist($holiday);
            }
            $holiday->update($change['name'], $change['active']);
            $this->em->flush();
            foreach ($requests as $request) {
                if (!$this->hasImpact($request, $change)) {
                    continue;
                }
                $event = new AuditEvent('calendar.request:'.bin2hex(random_bytes(16)), 'calendar.request_corrected', $actor, $request->getUser(), $this->clock->now(), $this->requestSnapshot($request), ['reason' => $reason, 'calendarOperation' => $key, 'date' => $change['date'], 'active' => $change['active']], 'request', $request->getReference());
                $this->em->persist($event);
                $this->correctRequest($request, $change, $event);
                $this->accounting->reconcileRequest($request, $event, true);
            }
            $result = $preview + ['applied' => true];
            $this->em->persist(new AuditEvent($key, 'calendar.company_day_changed', $actor, null, $this->clock->now(), $before, ['inputHash' => $inputHash, 'reason' => $reason, 'result' => $result], 'calendar', $change['date']));
            return $result;
        });
    }

    private function start(User $actor): void
    {
        $this->em->getConnection()->executeQuery('SELECT id FROM administration_lock WHERE id = 1 FOR UPDATE')->fetchOne();
        $this->em->refresh($actor);
        $this->administrator($actor);
    }

    private function administrator(User $actor): void
    {
        if (!$actor->isActive() || !$actor->isAdministrator()) {
            throw new CredentialsException('FORBIDDEN', 'Zmiany kalendarza wymagają uprawnień administratora.', 403);
        }
    }

    /** @param array<string, mixed> $data
     * @return array{date: string, name: string, active: bool}
     */
    private function change(array $data, bool $requireFuture = true): array
    {
        $date = AdminInput::date($data, 'date');
        WorkingCalendar::assertYear((int) $date->format('Y'));
        if ($requireFuture && $date->format('Y-m-d') <= $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d')) {
            throw new CredentialsException('CALENDAR_DATE_NOT_FUTURE', 'Zbiorcza zmiana kalendarza dotyczy wyłącznie przyszłych dni.');
        }
        return ['date' => $date->format('Y-m-d'), 'name' => trim(AdminInput::string($data, 'name')), 'active' => AdminInput::bool($data, 'active')];
    }

    private function holiday(string $date): ?Holiday
    {
        return $this->em->getRepository(Holiday::class)->findOneBy(['date' => new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'))]);
    }

    /** @return list<LeaveRequest> */
    private function affectedRequests(string $date): array
    {
        /** @var list<LeaveRequest> $requests */
        $requests = $this->em->createQuery('SELECT DISTINCT r FROM App\\Entity\\LeaveRequest r JOIN r.revisions v WHERE v.startDate <= :date AND v.endDate >= :date ORDER BY r.id')->setParameter('date', $date)->getResult();
        return $requests;
    }

    /** @param array{date: string, name: string, active: bool} $change
     * @param null|list<LeaveRequest> $requests
     * @return array<string, mixed>
     */
    private function buildPreview(User $actor, array $change, ?array $requests = null): array
    {
        $requests ??= $this->affectedRequests($change['date']);
        $impacts = [];
        $versions = [];
        foreach ($requests as $request) {
            $effective = $request->getEffectiveRevision();
            $pending = $request->getPendingRevision();
            if (!$this->hasImpact($request, $change)) {
                continue;
            }
            $after = null === $effective ? 0 : $this->total($this->revisedDays($effective, $change));
            $afterPending = null === $pending ? 0 : $this->total($this->revisedDays($pending, $change));
            if (RequestChangeKind::CANCELLATION === $pending?->getKind() && 0 === $after) {
                $afterPending = 0;
            }
            $impacts[] = ['requestId' => $request->getId(), 'reference' => $request->getReference(), 'userId' => $request->getUser()->getId(), 'employeeName' => $request->getUser()->getFirstName().' '.$request->getUser()->getLastName(), 'status' => null !== $effective ? (null === $pending ? 'approved' : 'approved_with_pending') : 'pending', 'beforeHours' => $effective?->getTotalHours() ?? 0, 'afterHours' => $after, 'beforePendingHours' => $pending?->getTotalHours() ?? 0, 'afterPendingHours' => $afterPending, 'action' => null !== $effective && 0 === $after ? 'Anulowanie urlopu' : (null === $effective && 0 === $afterPending ? 'Wycofanie wniosku' : 'Przeliczenie godzin')];
            $userId = $request->getUser()->getId();
            $ledgerVersion = $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(l.id), 0) FROM ledger_entry l INNER JOIN leave_account a ON a.id = l.account_id WHERE a.user_id = ?', [$userId]);
            $accounts = $this->em->getConnection()->fetchAllAssociative('SELECT leave_year, version, movement_revision FROM leave_account WHERE user_id = ? ORDER BY leave_year', [$userId]);
            $versions[] = [$request->getId(), $request->getVersion(), $request->getUser()->getVersion(), (int) $ledgerVersion, $accounts];
        }
        $holiday = $this->holiday($change['date']);
        $token = hash('sha256', json_encode([$actor->getId(), $change, $holiday?->getId(), $holiday?->getVersion(), $holiday?->isActive(), $holiday?->getName(), $versions, $impacts], JSON_THROW_ON_ERROR));
        return $change + ['previewToken' => $token, 'impacts' => $impacts, 'changedRequests' => count($impacts)];
    }

    /** @param array{date: string, name: string, active: bool} $change */
    private function hasImpact(LeaveRequest $request, array $change): bool
    {
        foreach ([$request->getEffectiveRevision(), $request->getPendingRevision()] as $revision) {
            if (null !== $revision && $revision->getTotalHours() !== $this->total($this->revisedDays($revision, $change))) {
                return true;
            }
        }
        return false;
    }

    /** @param list<RequestedDay> $days */
    private function total(array $days): int
    {
        return array_sum(array_map(static fn (RequestedDay $day): int => $day->hours, $days));
    }

    /** @param array{date: string, name: string, active: bool} $change
     * @return list<RequestedDay>
     */
    private function revisedDays(RequestRevision $revision, array $change): array
    {
        $days = [];
        foreach ($revision->getDays() as $day) {
            $days[$day->getDate()->format('Y-m-d')] = new RequestedDay($day->getAssignment(), $day->getDate(), $day->getHours());
        }
        if ($change['date'] < $revision->getStartDate()->format('Y-m-d') || $change['date'] > $revision->getEndDate()->format('Y-m-d')) {
            return array_values($days);
        }
        $date = new \DateTimeImmutable($change['date'], new \DateTimeZone('Europe/Warsaw'));
        if (0 === $this->calendar->hoursOn($date, $change)) {
            unset($days[$change['date']]);
        } elseif (!isset($days[$change['date']])) {
            $assignment = $this->capturedAssignment($revision, $date);
            $hours = RequestMode::HOURLY === $revision->getMode() ? $revision->getTotalHours() : WorkingCalendar::HOURS_PER_DAY;
            $days[$change['date']] = new RequestedDay($assignment, $date, $hours);
        }
        ksort($days);
        return array_values($days);
    }

    private function capturedAssignment(RequestRevision $revision, \DateTimeImmutable $date): StaffAssignment
    {
        $candidates = [];
        foreach ($revision->getAssignmentContexts() as $context) {
            if ($context->covers($date)) {
                $candidates[$context->getAssignment()->getId() ?? spl_object_id($context->getAssignment())] = $context->getAssignment();
            }
        }
        if ([] === $revision->getAssignmentContexts()) {
            foreach ($revision->getDays() as $day) {
                $assignment = $day->getAssignment();
                if ($assignment->getValidFrom() <= $date && (null === $assignment->getValidUntil() || $date < $assignment->getValidUntil())) {
                    $candidates[$assignment->getId() ?? spl_object_id($assignment)] = $assignment;
                }
            }
        }
        if (1 !== count($candidates)) {
            throw new CredentialsException('CALENDAR_LEGACY_CONTEXT_MISSING', 'Wniosek '.$revision->getRequest()->getReference().' nie ma jednoznacznie zapisanych zasad dla dodawanego dnia. Najpierw wymaga indywidualnego uporządkowania.', 409);
        }
        $assignment = array_values($candidates)[0];
        if (!$revision->getPolicy()->allowsEmploymentType($assignment->getEmploymentType())) {
            throw new CredentialsException('CALENDAR_POLICY_CONFLICT', 'Rodzaj nieobecności nie jest dostępny dla formy zatrudnienia zapisanej dla dodawanego dnia.', 409);
        }
        return $assignment;
    }

    /** @param array{date: string, name: string, active: bool} $change */
    private function correctRequest(LeaveRequest $request, array $change, AuditEvent $event): void
    {
        $effective = $request->getEffectiveRevision();
        $pending = $request->getPendingRevision();
        $effectiveDays = null === $effective ? [] : $this->revisedDays($effective, $change);
        $pendingDays = null === $pending ? [] : $this->revisedDays($pending, $change);
        if (null !== $pending) {
            $this->em->persist(new RequestDecision($pending, [] === $pendingDays ? DecisionOutcome::WITHDRAWN : DecisionOutcome::SUPERSEDED, $event));
        }
        if (null !== $effective && $this->total($effectiveDays) !== $effective->getTotalHours()) {
            $kind = [] === $effectiveDays ? RequestChangeKind::CANCELLATION : RequestChangeKind::CHANGE;
            $days = [] === $effectiveDays ? array_map(static fn (RequestDay $day): RequestedDay => new RequestedDay($day->getAssignment(), $day->getDate(), $day->getHours()), $effective->getDays()) : $effectiveDays;
            $corrected = new RequestRevision($request, $kind, $effective->getPolicy(), $effective->getMode(), $effective->getStartDate(), $effective->getEndDate(), $days, $event, $effective, $effective);
            $this->em->persist($corrected);
            $this->em->persist(new RequestDecision($corrected, DecisionOutcome::APPROVED, $event));
        }
        if (null !== $pending && [] !== $pendingDays) {
            $base = $request->getEffectiveRevision();
            if (RequestChangeKind::CANCELLATION === $pending->getKind()) {
                if (null === $base) {
                    return;
                }
                $pendingDays = array_map(static fn (RequestDay $day): RequestedDay => new RequestedDay($day->getAssignment(), $day->getDate(), $day->getHours()), $base->getDays());
                $source = $base;
                $kind = RequestChangeKind::CANCELLATION;
            } else {
                $source = $pending;
                $kind = null === $base ? RequestChangeKind::INITIAL : RequestChangeKind::CHANGE;
            }
            $replacement = new RequestRevision($request, $kind, $source->getPolicy(), $source->getMode(), $source->getStartDate(), $source->getEndDate(), $pendingDays, $event, $base, $source);
            $this->em->persist($replacement);
        }
    }

    /** @return array<string, mixed> */
    private function requestSnapshot(LeaveRequest $request): array
    {
        return ['effectiveRevision' => $request->getEffectiveRevision()?->getRevision(), 'effectiveHours' => $request->getEffectiveRevision()?->getTotalHours(), 'pendingRevision' => $request->getPendingRevision()?->getRevision(), 'pendingHours' => $request->getPendingRevision()?->getTotalHours()];
    }
}
