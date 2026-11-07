<?php

namespace App\Service\Admin;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\EmploymentType;
use App\Entity\RequestDay;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Service\CredentialsException;
use App\Service\CredentialsService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Every administrative write is an authorized, serialized and audited transaction. */
final class AdministrationService
{
    public function __construct(private EntityManagerInterface $em, private CredentialsService $credentials, private AdminPresenter $presenter, private ClockInterface $clock)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{user: User, temporaryPassword: string, expiresAt: \DateTimeImmutable}
     */
    public function createUser(User $actor, array $data): array
    {
        AdminInput::only($data, ['firstName', 'lastName', 'email', 'isAdministrator', 'teamId', 'employmentTypeId', 'role', 'validFrom', 'adminPassword']);
        return $this->em->wrapInTransaction(function () use ($actor, $data): array {
            $this->start($actor);
            $this->credentials->confirmAdministrator($actor, AdminInput::string($data, 'adminPassword', 1024));
            $user = new User(AdminInput::string($data, 'firstName', 100), AdminInput::string($data, 'lastName', 100), AdminInput::string($data, 'email', 254), AdminInput::bool($data, 'isAdministrator', false));
            $this->uniqueEmail($user->getEmail());
            $this->appendAssignment($user, $data);
            $password = $this->credentials->initializeAccountPassword($user);
            $this->em->persist($user);
            $this->audit($actor, $user, 'admin.user_created', [], $this->userSnapshot($user), 'user', $user->getEmail());
            return ['user' => $user] + $password;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateUser(User $actor, User $user, array $data): User
    {
        AdminInput::only($data, ['version', 'firstName', 'lastName', 'email', 'active', 'isAdministrator', 'adminPassword']);
        return $this->em->wrapInTransaction(function () use ($actor, $user, $data): User {
            $this->start($actor, $user);
            $this->credentials->confirmAdministrator($actor, AdminInput::string($data, 'adminPassword', 1024));
            $this->version($user->getVersion(), $data);
            $before = $this->userSnapshot($user);
            $email = strtolower(trim(AdminInput::string($data, 'email', 254)));
            $this->uniqueEmail($email, $user);
            $active = AdminInput::bool($data, 'active');
            $administrator = AdminInput::bool($data, 'isAdministrator');
            if ($this->isUsableAdministrator($user) && (!$active || !$administrator)) {
                $otherAdmins = $this->em->getRepository(User::class)->findBy(['active' => true, 'isAdministrator' => true, 'mustChangePassword' => false]);
                $hasOther = false;
                foreach ($otherAdmins as $other) {
                    if ($other->getId() !== $user->getId() && $this->isUsableAdministrator($other)) {
                        $hasOther = true;
                    }
                }
                if (!$hasOther) {
                    throw new CredentialsException('LAST_ADMINISTRATOR', 'Nie można wyłączyć ostatniego administratora z działającym, zmienionym hasłem.', 409);
                }
            }
            $user->updateProfile(AdminInput::string($data, 'firstName', 100), AdminInput::string($data, 'lastName', 100), $email, $active, $administrator);
            $this->audit($actor, $user, 'admin.user_updated', $before, $this->userSnapshot($user), 'user', (string) $user->getId());
            return $user;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function assignUser(User $actor, User $user, array $data): User
    {
        AdminInput::only($data, ['version', 'teamId', 'employmentTypeId', 'role', 'validFrom', 'adminPassword']);
        return $this->em->wrapInTransaction(function () use ($actor, $user, $data): User {
            $this->start($actor, $user);
            $this->credentials->confirmAdministrator($actor, AdminInput::string($data, 'adminPassword', 1024));
            $this->version($user->getVersion(), $data);
            $before = $this->userSnapshot($user);
            $this->appendAssignment($user, $data);
            $this->audit($actor, $user, 'admin.assignment_changed', $before, $this->userSnapshot($user), 'user', (string) $user->getId());
            return $user;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveTeam(User $actor, ?Team $item, array $data): Team
    {
        AdminInput::only($data, null === $item ? ['code', 'name', 'active'] : ['version', 'name', 'active']);
        return $this->em->wrapInTransaction(function () use ($actor, $item, $data): Team {
            $this->start($actor);
            $before = [];
            if (null === $item) {
                $item = new Team(AdminInput::string($data, 'code', 64), AdminInput::string($data, 'name'));
                $this->uniqueCode(Team::class, $item->getCode());
                $this->em->persist($item);
            } else {
                $this->refresh($item);
                $this->version($item->getVersion(), $data);
                $before = $this->presenter->team($item);
            }
            $item->update(AdminInput::string($data, 'name'), AdminInput::bool($data, 'active', $item->isActive()));
            $this->audit($actor, null, 'admin.team_saved', $before, $this->presenter->team($item), 'team', $item->getCode());
            return $item;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveEmploymentType(User $actor, ?EmploymentType $item, array $data): EmploymentType
    {
        AdminInput::only($data, null === $item ? ['code', 'name', 'active', 'carriesUnusedHours'] : ['version', 'name', 'active', 'carriesUnusedHours']);
        return $this->em->wrapInTransaction(function () use ($actor, $item, $data): EmploymentType {
            $this->start($actor);
            $before = [];
            if (null === $item) {
                $code = AdminInput::string($data, 'code', 64);
                $carries = AdminInput::bool($data, 'carriesUnusedHours', 'uop' === $code);
                if ($carries !== ('uop' === $code)) {
                    throw new CredentialsException('VALIDATION_FAILED', 'Przenoszenie niewykorzystanych godzin dotyczy wyłącznie formy uop.');
                }
                $item = new EmploymentType($code, AdminInput::string($data, 'name'), $carries);
                $this->uniqueCode(EmploymentType::class, $code);
                $this->em->persist($item);
            } else {
                $this->refresh($item);
                $this->version($item->getVersion(), $data);
                $before = $this->presenter->employmentType($item);
                if (array_key_exists('carriesUnusedHours', $data) && AdminInput::bool($data, 'carriesUnusedHours') !== $item->carriesUnusedHours()) {
                    throw new CredentialsException('VALIDATION_FAILED', 'Reguła przenoszenia godzin istniejącej formy jest niezmienna.');
                }
            }
            $item->update(AdminInput::string($data, 'name'), AdminInput::bool($data, 'active', $item->isActive()));
            $this->audit($actor, null, 'admin.employment_type_saved', $before, $this->presenter->employmentType($item), 'employment_type', $item->getCode());
            return $item;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveAbsenceType(User $actor, ?AbsenceType $item, array $data): AbsenceType
    {
        AdminInput::only($data, null === $item ? ['code', 'name', 'active', 'deductsBalance', 'employmentTypeIds', 'namesByEmploymentType'] : ['version', 'name', 'active', 'deductsBalance', 'employmentTypeIds', 'namesByEmploymentType']);
        return $this->em->wrapInTransaction(function () use ($actor, $item, $data): AbsenceType {
            $this->start($actor);
            $before = [];
            $previousEmployment = [];
            if (null === $item) {
                $item = new AbsenceType(AdminInput::string($data, 'code', 64));
                $this->uniqueCode(AbsenceType::class, $item->getCode());
                $this->em->persist($item);
            } else {
                $this->refresh($item);
                $this->version($item->getVersion(), $data);
                $before = $this->presenter->absenceType($item);
                $previous = $this->em->getRepository(AbsenceTypeVersion::class)->findOneBy(['type' => $item, 'revision' => $item->getLatestRevision()]);
                $previousEmployment = array_map(static fn (EmploymentType $employment): ?int => $employment->getId(), $previous?->getEmploymentTypes() ?? []);
            }
            $employmentTypes = [];
            foreach (AdminInput::ids($data, 'employmentTypeIds') as $id) {
                $employment = $this->em->find(EmploymentType::class, $id);
                if (null === $employment) {
                    throw new CredentialsException('VALIDATION_FAILED', 'Nie znaleziono formy zatrudnienia.');
                }
                if (!$employment->isActive() && !\in_array($id, $previousEmployment, true)) {
                    throw new CredentialsException('INACTIVE_REFERENCE', 'Nie można dodać nieaktywnej formy zatrudnienia.');
                }
                $employmentTypes[] = $employment;
            }
            $version = $item->createVersion(AdminInput::string($data, 'name'), AdminInput::bool($data, 'deductsBalance'), $employmentTypes, AdminInput::names($data));
            $item->setActive(AdminInput::bool($data, 'active', $item->isActive()));
            $this->em->persist($version);
            $after = ['code' => $item->getCode(), 'active' => $item->isActive(), 'revision' => $version->getRevision(), 'name' => $version->getName(), 'deductsBalance' => $version->deductsBalance(), 'employmentTypeIds' => array_map(static fn (EmploymentType $employment): ?int => $employment->getId(), $employmentTypes), 'namesByEmploymentType' => $version->getNamesByEmploymentType()];
            $this->audit($actor, null, 'admin.absence_type_version_created', $before, $after, 'absence_type', $item->getCode());
            return $item;
        });
    }

    public function initializeCatalog(User $actor): void
    {
        $this->em->wrapInTransaction(function () use ($actor): void {
            $this->start($actor);
            $created = [];
            $forms = [];
            foreach (['uop' => ['Umowa o pracę', true], 'b2b' => ['B2B', false]] as $code => [$name, $carries]) {
                $form = $this->em->getRepository(EmploymentType::class)->findOneBy(['code' => $code]);
                if (null === $form) {
                    $form = new EmploymentType($code, $name, $carries);
                    $this->em->persist($form);
                    $created[] = 'employment_type:'.$code;
                }
                $forms[] = $form;
            }
            // IDs are required for name overrides. The encompassing transaction is still atomic.
            $this->em->flush();
            foreach (['paid' => ['Urlop wypoczynkowy / płatne dni wolne', true], 'unpaid' => ['Urlop bezpłatny / bezpłatne dni wolne', false], 'other' => ['Inna nieobecność', false]] as $code => [$name, $deducts]) {
                if (null !== $this->em->getRepository(AbsenceType::class)->findOneBy(['code' => $code])) {
                    continue;
                }
                $type = new AbsenceType($code);
                $overrides = match ($code) {
                    'paid' => [(string) $forms[0]->getId() => 'Urlop wypoczynkowy', (string) $forms[1]->getId() => 'Płatne dni wolne'],
                    'unpaid' => [(string) $forms[0]->getId() => 'Urlop bezpłatny', (string) $forms[1]->getId() => 'Bezpłatne dni wolne'],
                    default => [],
                };
                $version = $type->createVersion($name, $deducts, $forms, $overrides);
                $this->em->persist($type);
                $this->em->persist($version);
                $created[] = 'absence_type:'.$code;
            }
            if ([] !== $created) {
                $this->audit($actor, null, 'admin.catalog_initialized', [], ['created' => $created], 'catalog', 'default');
            }
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function appendAssignment(User $user, array $data): void
    {
        $team = $this->em->find(Team::class, AdminInput::int($data, 'teamId'));
        $employment = $this->em->find(EmploymentType::class, AdminInput::int($data, 'employmentTypeId'));
        if (null === $team || null === $employment) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nie znaleziono zespołu lub formy zatrudnienia.');
        }
        if (!$team->isActive() || !$employment->isActive()) {
            throw new CredentialsException('INACTIVE_REFERENCE', 'Nowe przypisanie wymaga aktywnego zespołu i formy zatrudnienia.');
        }
        $role = TeamRole::tryFrom(AdminInput::string($data, 'role', 16));
        if (null === $role) {
            throw new CredentialsException('VALIDATION_FAILED', 'Nieprawidłowa rola zespołowa.');
        }
        $date = AdminInput::date($data, 'validFrom');
        $assignments = $user->getAssignments();
        usort($assignments, static fn (StaffAssignment $left, StaffAssignment $right): int => $left->getValidFrom() <=> $right->getValidFrom());
        $last = [] === $assignments ? null : $assignments[array_key_last($assignments)];
        if (null !== $last) {
            $comparison = strcmp($date->format('Y-m-d'), $last->getValidFrom()->format('Y-m-d'));
            if ($comparison < 0 || null !== $last->getValidUntil()) {
                throw new CredentialsException('ASSIGNMENT_CONFLICT', 'Dodaj zmianę po ostatnim przypisaniu; wcześniejszych okresów nie nadpisujemy.', 409);
            }
            if (0 === $comparison) {
                if (0 !== $this->em->getRepository(RequestDay::class)->count(['assignment' => $last])) {
                    throw new CredentialsException('ASSIGNMENT_CONFLICT', 'Przypisanie jest używane we wniosku. Wskaż późniejszą datę zmiany.', 409);
                }
                $last->correctUnreferenced($team, $employment, $role);
                return;
            }
            $last->closeAt($date);
        }
        $this->em->persist(new StaffAssignment($user, $team, $employment, $role, $date));
    }

    private function start(User $actor, ?User $target = null): void
    {
        // This stable row serializes last-admin checks and all dictionary / assignment writes.
        $lock = $this->em->getConnection()->fetchOne('SELECT id FROM administration_lock WHERE id = 1 FOR UPDATE');
        if (false === $lock) {
            throw new \LogicException('Administration lock must be installed by Doctrine migration.');
        }
        $users = [];
        foreach ([$actor, $target] as $user) {
            if (null !== $user) {
                $users[$user->getId() ?? throw new \LogicException('Persisted actor/target required.')] = $user;
            }
        }
        ksort($users);
        foreach ($users as $user) {
            $this->refresh($user);
        }
        if (!$actor->isActive() || !$actor->isAdministrator() || $actor->mustChangePassword() || null === $actor->getPasswordHash()) {
            throw new CredentialsException('FORBIDDEN', 'Brak uprawnień administratora.', 403);
        }
    }

    private function refresh(object $entity): void
    {
        $this->em->lock($entity, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($entity);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function version(int $actual, array $data): void
    {
        if ($actual !== AdminInput::int($data, 'version')) {
            throw new CredentialsException('CONFLICT', 'Dane zostały zmienione. Odśwież formularz przed ponownym zapisem.', 409);
        }
    }

    private function uniqueEmail(string $email, ?User $except = null): void
    {
        $found = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $found && $found->getId() !== $except?->getId()) {
            throw new CredentialsException('DUPLICATE_VALUE', 'Ten adres e-mail jest już używany.', 409);
        }
    }

    /**
     * @param class-string<Team>|class-string<EmploymentType>|class-string<AbsenceType> $class
     */
    private function uniqueCode(string $class, string $code): void
    {
        if (null !== $this->em->getRepository($class)->findOneBy(['code' => $code])) {
            throw new CredentialsException('DUPLICATE_VALUE', 'Ten kod jest już używany.', 409);
        }
    }

    private function isUsableAdministrator(User $user): bool
    {
        return $user->isActive() && $user->isAdministrator() && null !== $user->getPasswordHash() && !$user->mustChangePassword();
    }

    /**
     * @return array<string, mixed>
     */
    private function userSnapshot(User $user): array
    {
        return ['firstName' => $user->getFirstName(), 'lastName' => $user->getLastName(), 'email' => $user->getEmail(), 'active' => $user->isActive(), 'isAdministrator' => $user->isAdministrator(), 'assignments' => array_map($this->presenter->assignment(...), $user->getAssignments())];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function audit(User $actor, ?User $subject, string $action, array $before, array $after, string $targetType, string $targetReference): void
    {
        $this->em->persist(new AuditEvent('admin.'.bin2hex(random_bytes(16)), $action, $actor, $subject, $this->clock->now(), $before, $after, $targetType, $targetReference));
    }
}
