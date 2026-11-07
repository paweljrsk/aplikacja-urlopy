<?php

namespace App\Service\Admin;

use App\Entity\AuditEvent;
use App\Service\CredentialsException;
use Doctrine\ORM\EntityManagerInterface;

/** Explicit audit projection: unknown actions and fields never become public by accident. */
final class AdminHistory
{
    private const ACTIONS = ['admin.user_created', 'admin.user_updated', 'admin.assignment_changed', 'admin.team_saved', 'admin.employment_type_saved', 'admin.absence_type_version_created', 'admin.catalog_initialized', 'security.password_changed', 'security.password_reset', 'security.admin_bootstrapped', 'security.admin_recovered'];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function page(array $query): array
    {
        AdminInput::only($query, ['limit', 'beforeId']);
        $limit = $this->positive($query['limit'] ?? '20', 100);
        $before = isset($query['beforeId']) ? $this->positive($query['beforeId']) : null;
        $builder = $this->em->createQueryBuilder()->select('event', 'actor', 'subject')->from(AuditEvent::class, 'event')
            ->leftJoin('event.actor', 'actor')->leftJoin('event.subject', 'subject')
            ->where('event.action IN (:actions)')->setParameter('actions', self::ACTIONS)
            ->orderBy('event.id', 'DESC')->setMaxResults($limit + 1);
        if (null !== $before) { $builder->andWhere('event.id < :before')->setParameter('before', $before); }
        /** @var list<AuditEvent> $events */
        $events = $builder->getQuery()->getResult();
        $hasMore = count($events) > $limit;
        $events = array_slice($events, 0, $limit);

        return ['events' => array_map($this->present(...), $events), 'nextBeforeId' => $hasMore ? $events[array_key_last($events)]->getId() : null];
    }

    /** @return array<string, mixed> */
    private function present(AuditEvent $event): array
    {
        $actor = $event->getActor();
        $subject = $event->getSubject();
        $action = $event->getAction();
        $fields = match ($action) {
            'admin.user_created', 'admin.user_updated', 'admin.assignment_changed' => ['firstName' => 'Imię', 'lastName' => 'Nazwisko', 'email' => 'E-mail', 'active' => 'Aktywne konto', 'isAdministrator' => 'Administrator', 'assignments' => 'Przypisania'],
            'admin.team_saved' => ['code' => 'Kod', 'name' => 'Nazwa', 'active' => 'Aktywność'],
            'admin.employment_type_saved' => ['code' => 'Kod', 'name' => 'Nazwa', 'active' => 'Aktywność', 'carriesUnusedHours' => 'Przenoszenie godzin'],
            'admin.absence_type_version_created' => ['code' => 'Kod', 'name' => 'Nazwa', 'active' => 'Aktywność', 'revision' => 'Wersja zasad', 'deductsBalance' => 'Pomniejsza saldo', 'employmentTypeIds' => 'Formy zatrudnienia (ID)', 'namesByEmploymentType' => 'Nazwy dla form zatrudnienia'],
            'admin.catalog_initialized' => ['created' => 'Dodane pozycje'],
            default => ['mustChangePassword' => 'Wymagana zmiana hasła'],
        };
        $before = $event->getBeforeData();
        $after = $event->getAfterData();
        $changes = [];
        foreach ($fields as $field => $label) {
            $old = $this->value($field, $before[$field] ?? null);
            $new = $this->value($field, $after[$field] ?? null);
            if ($old !== $new) { $changes[] = ['field' => $field, 'label' => $label, 'before' => $old, 'after' => $new]; }
        }

        return ['id' => $event->getId(), 'action' => $action, 'occurredAt' => $event->getOccurredAt()->format(\DATE_ATOM),
            'actorName' => null === $actor ? null : $actor->getFirstName().' '.$actor->getLastName(),
            'subject' => null === $subject ? null : ['id' => $subject->getId(), 'firstName' => $subject->getFirstName(), 'lastName' => $subject->getLastName()],
            'targetType' => $event->getTargetType(),
            // User audit references formerly included email. The stable identifier is sufficient here.
            'targetReference' => null !== $subject ? (string) $subject->getId() : $event->getTargetReference(),
            'changes' => $changes];
    }

    private function value(string $field, mixed $value): ?string
    {
        if (null === $value) { return null; }
        if ('assignments' === $field) { return $this->assignments($value); }
        if ('employmentTypeIds' === $field && \is_array($value)) {
            return implode(', ', array_map(static fn (int $id): string => (string) $id, array_filter($value, static fn (mixed $id): bool => \is_int($id) && $id > 0)));
        }
        if ('namesByEmploymentType' === $field && \is_array($value)) {
            $names = [];
            foreach ($value as $id => $name) {
                if (ctype_digit((string) $id) && \is_string($name)) { $names[] = 'ID '.$id.': '.$name; }
            }
            return implode('; ', $names);
        }
        if ('created' === $field && \is_array($value)) {
            return implode(', ', array_filter($value, static fn (mixed $item): bool => \is_string($item) && 1 === preg_match('/^(employment_type|absence_type):[a-z0-9_]+$/D', $item)));
        }
        if (\is_bool($value)) { return $value ? 'Tak' : 'Nie'; }
        return \is_string($value) || \is_int($value) ? (string) $value : null;
    }

    private function assignments(mixed $value): ?string
    {
        if (!\is_array($value)) { return null; }
        $rows = [];
        foreach ($value as $assignment) {
            if (!\is_array($assignment)) { continue; }
            $team = $assignment['team'] ?? null;
            $employment = $assignment['employmentType'] ?? null;
            $from = $assignment['validFrom'] ?? null;
            $until = $assignment['validUntil'] ?? null;
            $role = $assignment['role'] ?? null;
            if (!\is_array($team) || !\is_array($employment) || !\is_string($team['name'] ?? null) || !\is_string($employment['name'] ?? null) || !\is_string($from) || (null !== $until && !\is_string($until)) || !\is_string($role)) { continue; }
            $roleLabel = match ($role) { 'employee' => 'Pracownik', 'manager' => 'Kierownik', 'deputy' => 'Zastępca', default => null };
            if (null === $roleLabel) { continue; }
            $rows[] = $from.' – '.($until ?? 'bez końca').' · '.$team['name'].' · '.$employment['name'].' · '.$roleLabel;
        }
        return implode("\n", $rows);
    }

    private function positive(mixed $value, int $max = PHP_INT_MAX): int
    {
        if (!\is_string($value) || 1 !== preg_match('/^[1-9][0-9]{0,17}$/D', $value) || (int) $value > $max) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowy parametr strony historii.');
        }
        return (int) $value;
    }
}
