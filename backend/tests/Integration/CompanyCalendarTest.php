<?php

namespace App\Tests\Integration;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\DailyOccupancy;
use App\Entity\EmploymentType;
use App\Entity\Holiday;
use App\Entity\LeaveRequest;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Enum\TeamRole;
use App\Model\RequestedDay;
use App\Security\BalanceAccessPolicy;
use App\Security\CurrentAssignmentResolver;
use App\Security\RequestAccessPolicy;
use App\Security\SecurityIdentity;
use App\Service\Accounting\AccountingService;
use App\Service\Admin\AdministrationService;
use App\Service\Calendar\CompanyCalendar;
use App\Service\Calendar\WorkingCalendar;
use App\Service\CredentialsException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Real MySQL; each scenario rolls back its own synthetic transaction. */
final class CompanyCalendarTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MockClock $clock;
    private CompanyCalendar $calendar;
    private AccountingService $accounting;
    private User $admin;
    private User $employee;
    private StaffAssignment $assignment;
    private AbsenceTypeVersion $paid;
    private AbsenceTypeVersion $unpaid;
    private string $prefix;
    private const PASSWORD = 'Synthetic calendar administrator password 23!';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $this->connection = $this->em->getConnection();
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', (string) $this->connection->getDatabase());
        $this->connection->beginTransaction();
        $this->clock = new MockClock('2090-01-02 12:00:00 Europe/Warsaw');
        $this->prefix = 'calendar_'.bin2hex(random_bytes(5));
        $resolver = new CurrentAssignmentResolver();
        $this->accounting = new AccountingService($this->em, $this->clock, $resolver, new BalanceAccessPolicy($resolver), new RequestAccessPolicy($resolver));
        $this->calendar = new CompanyCalendar($this->em, new WorkingCalendar($this->em), $this->accounting, $this->clock);
        $team = new Team($this->prefix, 'Synthetic calendar team');
        $employment = new EmploymentType($this->prefix, 'Synthetic UoP', true);
        $this->admin = new User('Synthetic', 'Administrator', $this->prefix.'_admin@example.test', true);
        $hashers = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        $this->admin->replacePasswordHash($hashers->getPasswordHasher(SecurityIdentity::class)->hash(self::PASSWORD), false, null);
        $this->employee = new User('Synthetic', 'Employee', $this->prefix.'_employee@example.test');
        $this->assignment = new StaffAssignment($this->employee, $team, $employment, TeamRole::EMPLOYEE, $this->date('2090-01-01'));
        $paidType = new AbsenceType($this->prefix.'_paid');
        $this->paid = $paidType->createVersion('Synthetic paid leave', true, [$employment]);
        $unpaidType = new AbsenceType($this->prefix.'_unpaid');
        $this->unpaid = $unpaidType->createVersion('Synthetic unpaid leave', false, [$employment]);
        foreach ([$team, $employment, $this->admin, $this->employee, $paidType, $unpaidType, $this->paid, $this->unpaid] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        $this->accounting->initializeOpening($this->admin, $this->employee, ['operationKey' => $this->key(), 'expectedVersion' => $this->accounting->getBalance($this->employee, 2090)['version'], 'effectiveDate' => '2090-01-01', 'annualHours' => 208, 'currentYearUsedHours' => 0, 'carries' => []]);
    }

    protected function tearDown(): void
    {
        while ($this->connection->isTransactionActive()) { $this->connection->rollBack(); }
        parent::tearDown();
    }

    public function testAddingFreeDayReconcilesApprovedAndPendingChangeWithoutDoubleRefund(): void
    {
        $original = $this->request(['2090-02-13', '2090-02-14'], true);
        $request = $original->getRequest();
        $event = $this->event('proposal');
        $pending = new RequestRevision($request, RequestChangeKind::CHANGE, $this->paid, RequestMode::FULL_DAYS, $this->date('2090-02-14'), $this->date('2090-02-15'), [new RequestedDay($this->assignment, $this->date('2090-02-14'), 8), new RequestedDay($this->assignment, $this->date('2090-02-15'), 8)], $event, $original);
        $this->em->persist($pending);
        $this->em->flush();
        $this->accounting->reserveRevision($this->employee, $pending, $this->key());
        $before = $this->accounting->getBalance($this->employee, 2090);
        self::assertSame(184, $before['availableHours']);
        $preview = $this->calendar->preview($this->admin, $this->change('2090-02-14'));
        self::assertSame(1, $preview['changedRequests']);
        self::assertSame(8, $preview['impacts'][0]['afterHours']);
        self::assertSame(8, $preview['impacts'][0]['afterPendingHours']);
        $this->calendar->apply($this->admin, $this->confirmation($preview));
        self::assertSame(8, $request->getEffectiveRevision()?->getTotalHours());
        self::assertSame(8, $request->getPendingRevision()?->getTotalHours());
        self::assertSame(16, $original->getTotalHours());
        self::assertSame(16, $pending->getTotalHours());
        $balance = $this->accounting->getBalance($this->employee, 2090);
        self::assertSame(192, $balance['availableHours']);
        self::assertSame(8, $balance['approvedFutureHours']);
        self::assertSame(8, $balance['changeReservedHours']);
        self::assertSame(0, $this->occupancy('2090-02-14'));
        self::assertSame(8, $this->occupancy('2090-02-13'));
        self::assertSame(8, $this->occupancy('2090-02-15'));
    }

    public function testZeroDayApprovedRequestIsExplicitlyCancelledAndRetryWorksAfterDatePasses(): void
    {
        $revision = $this->request(['2090-02-13'], true);
        $preview = $this->calendar->preview($this->admin, $this->change('2090-02-13'));
        self::assertSame('Anulowanie urlopu', $preview['impacts'][0]['action']);
        $payload = $this->confirmation($preview);
        $first = $this->calendar->apply($this->admin, $payload);
        self::assertNull($revision->getRequest()->getEffectiveRevision());
        self::assertNull($revision->getRequest()->getPendingRevision());
        self::assertSame(RequestChangeKind::CANCELLATION, $revision->getRequest()->getRevisions()[1]->getKind());
        self::assertSame(208, $this->accounting->getBalance($this->employee, 2090)['availableHours']);
        self::assertSame(0, $this->occupancy('2090-02-13'));
        $count = $this->em->getRepository(AuditEvent::class)->count([]);
        $this->clock->modify('+60 days');
        self::assertSame($first, $this->calendar->apply($this->admin, $payload));
        self::assertSame($count, $this->em->getRepository(AuditEvent::class)->count([]));
    }

    public function testPendingNonDeductingRequestIsWithdrawnAndOccupancyReleased(): void
    {
        $revision = $this->request(['2090-02-13'], false, $this->unpaid);
        self::assertSame(8, $this->occupancy('2090-02-13'));
        $preview = $this->calendar->preview($this->admin, $this->change('2090-02-13'));
        self::assertSame('Wycofanie wniosku', $preview['impacts'][0]['action']);
        $this->calendar->apply($this->admin, $this->confirmation($preview));
        self::assertNull($revision->getRequest()->getPendingRevision());
        self::assertSame(0, $this->occupancy('2090-02-13'));
        self::assertSame(208, $this->accounting->getBalance($this->employee, 2090)['availableHours']);
    }

    public function testRemovingHolidayAddsDayAndRecordsExplicitDeficit(): void
    {
        $this->em->persist(new Holiday($this->date('2090-02-14'), 'Synthetic day off'));
        $this->em->flush();
        $this->accounting->setAnnualLimit($this->admin, $this->employee, 2090, ['operationKey' => $this->key(), 'expectedVersion' => $this->accounting->getBalance($this->employee, 2090)['version'], 'annualHours' => 16, 'reason' => 'Synthetic exact limit']);
        $revision = $this->request(['2090-02-13', '2090-02-15'], true);
        $preview = $this->calendar->preview($this->admin, $this->change('2090-02-14', false));
        self::assertSame(24, $preview['impacts'][0]['afterHours']);
        $this->calendar->apply($this->admin, $this->confirmation($preview));
        self::assertSame(24, $revision->getRequest()->getEffectiveRevision()?->getTotalHours());
        self::assertSame(-8, $this->accounting->getBalance($this->employee, 2090)['availableHours']);
        self::assertSame(8, $this->occupancy('2090-02-14'));
    }

    public function testStalePreviewCannotApplyAfterBalanceChanged(): void
    {
        $this->request(['2090-02-13'], true);
        $preview = $this->calendar->preview($this->admin, $this->change('2090-02-13'));
        $this->accounting->setAnnualLimit($this->admin, $this->employee, 2090, ['operationKey' => $this->key(), 'expectedVersion' => $this->accounting->getBalance($this->employee, 2090)['version'], 'annualHours' => 200, 'reason' => 'Synthetic correction']);
        try {
            $this->calendar->apply($this->admin, $this->confirmation($preview));
            self::fail('Stale preview must be rejected.');
        } catch (CredentialsException $exception) {
            self::assertSame('CALENDAR_PREVIEW_STALE', $exception->errorCode);
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM holiday WHERE date = ?', ['2090-02-13']));
        }
    }

    public function testEmployeeCannotPreviewAdministrativeImpact(): void
    {
        $this->expectException(CredentialsException::class);
        try { $this->calendar->preview($this->employee, $this->change('2090-02-13')); }
        catch (CredentialsException $exception) { self::assertSame('FORBIDDEN', $exception->errorCode); throw $exception; }
    }

    public function testPastAndCurrentDayAreRejectedUsingWarsawDate(): void
    {
        $this->clock->modify('+42 days');
        $this->expectException(CredentialsException::class);
        try { $this->calendar->preview($this->admin, $this->change('2090-02-13')); }
        catch (CredentialsException $exception) { self::assertSame('CALENDAR_DATE_NOT_FUTURE', $exception->errorCode); throw $exception; }
    }

    public function testAssignmentUsedOnlyByExcludedDayContextCannotBeOverwritten(): void
    {
        $this->assignment->closeAt($this->date('2090-02-14'));
        $last = new StaffAssignment($this->employee, $this->assignment->getTeam(), $this->assignment->getEmploymentType(), TeamRole::EMPLOYEE, $this->date('2090-02-14'));
        $event = $this->event('context');
        $request = new LeaveRequest($this->employee, $this->clock->now());
        $revision = new RequestRevision($request, RequestChangeKind::INITIAL, $this->paid, RequestMode::FULL_DAYS, $this->date('2090-02-13'), $this->date('2090-02-14'), [new RequestedDay($this->assignment, $this->date('2090-02-13'), 8)], $event);
        $this->em->persist($last);
        $this->em->persist($request);
        $this->em->flush();
        self::assertCount(2, $revision->getAssignmentContexts());
        $administration = self::getContainer()->get(AdministrationService::class);
        try {
            $administration->assignUser($this->admin, $this->employee, ['version' => $this->employee->getVersion(), 'teamId' => $last->getTeam()->getId(), 'employmentTypeId' => $last->getEmploymentType()->getId(), 'role' => 'manager', 'validFrom' => '2090-02-14', 'adminPassword' => self::PASSWORD]);
            self::fail('A captured excluded-day assignment cannot be overwritten.');
        } catch (CredentialsException $exception) {
            self::assertSame('ASSIGNMENT_CONFLICT', $exception->errorCode);
        }
    }

    public function testPersistedFullRangeContextPreservesEmploymentWhenHolidayIsRemovedAfterTransfer(): void
    {
        $this->em->persist(new Holiday($this->date('2090-02-14'), 'Synthetic excluded day'));
        $this->em->flush();
        $revision = $this->request(['2090-02-13', '2090-02-15'], true);
        $requestId = $revision->getRequest()->getId();
        $originalAssignmentId = $this->assignment->getId();
        $this->transferAndReload();
        $request = $this->em->find(LeaveRequest::class, $requestId);
        self::assertNotNull($request);
        $preview = $this->calendar->preview($this->admin, $this->change('2090-02-14', false));
        $this->calendar->apply($this->admin, $this->confirmation($preview));
        $effective = $request->getEffectiveRevision();
        self::assertNotNull($effective);
        self::assertSame(24, $effective->getTotalHours());
        foreach ($effective->getDays() as $day) {
            self::assertSame($originalAssignmentId, $day->getAssignment()->getId());
            self::assertTrue($day->getAssignment()->getEmploymentType()->carriesUnusedHours());
        }
    }

    public function testAmbiguousLegacyExcludedDateIsRejectedWithoutAdoptingNewEmployment(): void
    {
        $this->em->persist(new Holiday($this->date('2090-02-14'), 'Synthetic legacy excluded day'));
        $this->em->flush();
        $revision = $this->request(['2090-02-13', '2090-02-15'], true);
        // Only this newly created synthetic fixture imitates a pre-stage-5 revision.
        $this->connection->executeStatement('DELETE FROM request_assignment_context WHERE revision_id = ?', [$revision->getId()]);
        $this->transferAndReload();
        try {
            $this->calendar->preview($this->admin, $this->change('2090-02-14', false));
            self::fail('Missing legacy rules must never adopt the current employment silently.');
        } catch (CredentialsException $exception) {
            self::assertSame('CALENDAR_LEGACY_CONTEXT_MISSING', $exception->errorCode);
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT active FROM holiday WHERE date = ?', ['2090-02-14']));
        }
    }

    private function transferAndReload(): void
    {
        $employeeId = $this->employee->getId();
        $adminId = $this->admin->getId();
        $b2b = new EmploymentType($this->prefix.'_b2b', 'Synthetic B2B', false);
        $this->em->persist($b2b);
        $this->assignment->closeAt($this->date('2090-02-01'));
        $replacement = new StaffAssignment($this->employee, $this->assignment->getTeam(), $b2b, TeamRole::EMPLOYEE, $this->date('2090-02-01'));
        $this->em->persist($replacement);
        $this->em->flush();
        $this->em->clear();
        $employee = $this->em->find(User::class, $employeeId);
        $admin = $this->em->find(User::class, $adminId);
        self::assertNotNull($employee);
        self::assertNotNull($admin);
        $this->employee = $employee;
        $this->admin = $admin;
    }

    /** @param list<string> $dates */
    private function request(array $dates, bool $approved, ?AbsenceTypeVersion $policy = null): RequestRevision
    {
        $event = $this->event('request');
        $request = new LeaveRequest($this->employee, $this->clock->now());
        $revision = new RequestRevision($request, RequestChangeKind::INITIAL, $policy ?? $this->paid, RequestMode::FULL_DAYS, $this->date($dates[0]), $this->date($dates[count($dates) - 1]), array_map(fn (string $date): RequestedDay => new RequestedDay($this->assignment, $this->date($date), 8), $dates), $event);
        $this->em->persist($request);
        $this->em->flush();
        $this->accounting->reserveRevision($this->employee, $revision, $this->key());
        if ($approved) { $this->accounting->approveRevision($this->admin, $revision, $this->key()); }
        return $revision;
    }

    private function event(string $action): AuditEvent
    {
        $event = new AuditEvent($this->key(), 'test.'.$action, $this->admin, $this->employee, $this->clock->now());
        $this->em->persist($event);
        return $event;
    }

    /** @return array{date: string, name: string, active: bool} */
    private function change(string $date, bool $active = true): array
    {
        return ['date' => $date, 'name' => 'Synthetic company free day', 'active' => $active];
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function confirmation(array $preview): array
    {
        return ['date' => $preview['date'], 'name' => $preview['name'], 'active' => $preview['active'], 'previewToken' => $preview['previewToken'], 'operationKey' => $this->key(), 'reason' => 'Synthetic confirmed calendar correction'];
    }

    private function occupancy(string $date): int
    {
        return $this->em->getRepository(DailyOccupancy::class)->findOneBy(['user' => $this->employee, 'date' => $this->date($date)])?->getHours() ?? 0;
    }

    private function key(): string { return $this->prefix.':'.bin2hex(random_bytes(6)); }
    private function date(string $date): \DateTimeImmutable { return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw')); }
}
