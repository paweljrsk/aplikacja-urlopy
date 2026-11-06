<?php

namespace App\DataFixtures;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\DailyOccupancy;
use App\Entity\EmploymentType;
use App\Entity\Holiday;
use App\Entity\HourPool;
use App\Entity\LeaveAccount;
use App\Entity\LeaveRequest;
use App\Entity\LedgerEntry;
use App\Entity\OpeningBalance;
use App\Entity\OpeningCarry;
use App\Entity\RequestDay;
use App\Entity\RequestDecision;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DecisionOutcome;
use App\Enum\LedgerEntryKind;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Enum\TeamRole;
use App\Model\RequestedDay;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Synthetic, explicit snapshots for development; this is not a schema installer or a workflow service. */
final class DemoData
{
    public const MARKER = 'demo.seed.v1';
    public const UOP_REQUEST = '00000000000000000000000000000001';
    public const B2B_REQUEST = '00000000000000000000000000000002';

    /** @var array<class-string, int> */
    private const EXPECTED_COUNTS = [
        Team::class => 2,
        EmploymentType::class => 2,
        User::class => 5,
        StaffAssignment::class => 5,
        AbsenceType::class => 3,
        AbsenceTypeVersion::class => 3,
        Holiday::class => 1,
        LeaveAccount::class => 2,
        HourPool::class => 3,
        OpeningBalance::class => 2,
        OpeningCarry::class => 1,
        AuditEvent::class => 6,
        LeaveRequest::class => 2,
        RequestRevision::class => 2,
        RequestDay::class => 2,
        RequestDecision::class => 1,
        LedgerEntry::class => 8,
        DailyOccupancy::class => 2,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /** @return array{created: bool, users: int, teams: int, requests: int} */
    public function load(bool $confirmed): array
    {
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            throw new \DomainException('Demo data can be loaded only in dev or test, never in production.');
        }
        if (!$confirmed) {
            throw new \DomainException('Synthetic demo data requires the explicit --confirm-demo option.');
        }

        return $this->em->wrapInTransaction(function (): array {
            if (null !== $this->em->getRepository(AuditEvent::class)->findOneBy(['operationKey' => self::MARKER])) {
                $this->verifyExistingFixture();

                return ['created' => false, 'users' => 5, 'teams' => 2, 'requests' => 2];
            }
            foreach (array_keys(self::EXPECTED_COUNTS) as $entityClass) {
                if (0 !== $this->em->getRepository($entityClass)->count([])) {
                    throw new \DomainException('Demo loading requires an empty application database. Existing data was not changed.');
                }
            }

            $this->createFixture();

            return ['created' => true, 'users' => 5, 'teams' => 2, 'requests' => 2];
        });
    }

    private function verifyExistingFixture(): void
    {
        foreach (self::EXPECTED_COUNTS as $entityClass => $expected) {
            if ($expected !== $this->em->getRepository($entityClass)->count([])) {
                throw new \DomainException('The demo fixture is incomplete or has been changed. No data was overwritten or added.');
            }
        }
        foreach (['admin', 'manager', 'deputy', 'employee', 'contractor'] as $role) {
            if (null === $this->em->getRepository(User::class)->findOneBy(['email' => 'demo.'.$role.'@example.test'])) {
                throw new \DomainException('A synthetic demo identity is missing. No data was changed.');
            }
        }
        foreach (['demo_alpha', 'demo_beta'] as $code) {
            if (null === $this->em->getRepository(Team::class)->findOneBy(['code' => $code])) {
                throw new \DomainException('A synthetic demo team is missing. No data was changed.');
            }
        }
        foreach ([self::UOP_REQUEST, self::B2B_REQUEST] as $reference) {
            if (null === $this->em->getRepository(LeaveRequest::class)->findOneBy(['reference' => $reference])) {
                throw new \DomainException('A synthetic demo request is missing. No data was changed.');
            }
        }
    }

    private function createFixture(): void
    {
        $alpha = new Team('demo_alpha', 'Zespół Alfa — DEMO');
        $beta = new Team('demo_beta', 'Zespół Beta — DEMO');
        $uop = new EmploymentType('demo_uop', 'Umowa o pracę — DEMO', true);
        $b2b = new EmploymentType('demo_b2b', 'B2B — DEMO', false);
        $admin = new User('Demo', 'Administrator', 'demo.admin@example.test', true);
        $manager = new User('Demo', 'Kierownik', 'demo.manager@example.test');
        $deputy = new User('Demo', 'Zastępca', 'demo.deputy@example.test');
        $employee = new User('Demo', 'Pracownik', 'demo.employee@example.test');
        $contractor = new User('Demo', 'Kontraktor', 'demo.contractor@example.test');
        $start = self::date('2026-01-01');
        new StaffAssignment($admin, $alpha, $uop, TeamRole::EMPLOYEE, $start);
        new StaffAssignment($manager, $alpha, $uop, TeamRole::MANAGER, $start);
        new StaffAssignment($deputy, $alpha, $uop, TeamRole::DEPUTY, $start);
        $employeeAssignment = new StaffAssignment($employee, $alpha, $uop, TeamRole::EMPLOYEE, $start);
        $contractorAssignment = new StaffAssignment($contractor, $beta, $b2b, TeamRole::EMPLOYEE, $start);
        $paidType = new AbsenceType('demo_paid');
        $unpaidType = new AbsenceType('demo_unpaid');
        $otherType = new AbsenceType('demo_other');
        $paid = $paidType->createVersion('Płatne dni wolne — DEMO', true, [$uop, $b2b]);
        $unpaid = $unpaidType->createVersion('Bezpłatne dni wolne — DEMO', false, [$uop, $b2b]);
        $other = $otherType->createVersion('Inna nieobecność — DEMO', false, [$uop, $b2b]);
        $holiday = new Holiday(self::date('2026-11-23'), 'Przykładowy firmowy dzień wolny — DEMO');

        foreach ([$alpha, $beta, $uop, $b2b, $admin, $manager, $deputy, $employee, $contractor, $paidType, $unpaidType, $otherType, $paid, $unpaid, $other, $holiday] as $entity) {
            $this->em->persist($entity);
        }

        $employeeAccount = new LeaveAccount($employee, 2026);
        $contractorAccount = new LeaveAccount($contractor, 2026);
        $employeePool = new HourPool($employee, 2026);
        $employeeCarryPool = new HourPool($employee, 2025);
        $contractorPool = new HourPool($contractor, 2026);
        $openingTime = new \DateTimeImmutable('2026-11-01T08:00:00+00:00');
        $employeeOpeningEvent = new AuditEvent('demo.opening.uop.v1', 'opening.recorded', $admin, $employee, $openingTime, [], ['annual_hours' => 208, 'current_pool_used_hours' => 40, 'carry_2025_remaining_hours' => 16]);
        $contractorOpeningEvent = new AuditEvent('demo.opening.b2b.v1', 'opening.recorded', $admin, $contractor, $openingTime, [], ['annual_hours' => 160, 'current_pool_used_hours' => 24]);
        $employeeOpening = new OpeningBalance($employee, self::date('2026-11-01'), 208, 40, $employeeOpeningEvent);
        new OpeningCarry($employeeOpening, 2025, 16);
        $contractorOpening = new OpeningBalance($contractor, self::date('2026-11-01'), 160, 24, $contractorOpeningEvent);

        foreach ([$employeeAccount, $contractorAccount, $employeePool, $employeeCarryPool, $contractorPool, $employeeOpeningEvent, $contractorOpeningEvent, $employeeOpening, $contractorOpening] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new LedgerEntry($employeeAccount, $employeePool, $employeeOpeningEvent, 1, LedgerEntryKind::GRANT, 208, 0, 0));
        $this->em->persist(new LedgerEntry($employeeAccount, $employeePool, $employeeOpeningEvent, 2, LedgerEntryKind::OPENING_USED, 0, 0, 40));
        $this->em->persist(new LedgerEntry($employeeAccount, $employeeCarryPool, $employeeOpeningEvent, 3, LedgerEntryKind::GRANT, 16, 0, 0));
        $this->em->persist(new LedgerEntry($contractorAccount, $contractorPool, $contractorOpeningEvent, 1, LedgerEntryKind::GRANT, 160, 0, 0));
        $this->em->persist(new LedgerEntry($contractorAccount, $contractorPool, $contractorOpeningEvent, 2, LedgerEntryKind::OPENING_USED, 0, 0, 24));

        $submittedAt = new \DateTimeImmutable('2026-11-02T08:00:00+00:00');
        $employeeSubmitted = new AuditEvent('demo.request.uop.submit.v1', 'request.submitted', $employee, $employee, $submittedAt, [], ['reference' => self::UOP_REQUEST, 'hours' => 8]);
        $employeeRequest = new LeaveRequest($employee, $submittedAt, self::UOP_REQUEST);
        $employeeDate = self::date('2026-12-15');
        $employeeRevision = new RequestRevision($employeeRequest, RequestChangeKind::INITIAL, $paid, RequestMode::FULL_DAYS, $employeeDate, $employeeDate, [new RequestedDay($employeeAssignment, $employeeDate, 8)], $employeeSubmitted);
        $employeeOccupancy = new DailyOccupancy($employee, $employeeDate);
        $employeeOccupancy->changeBy(8);
        $employeeReserved = new LedgerEntry($employeeAccount, $employeeCarryPool, $employeeSubmitted, 1, LedgerEntryKind::RESERVE, 0, 8, 0, $employeeRevision->getDays()[0]);

        $contractorSubmitted = new AuditEvent('demo.request.b2b.submit.v1', 'request.submitted', $contractor, $contractor, $submittedAt, [], ['reference' => self::B2B_REQUEST, 'hours' => 3]);
        $contractorRequest = new LeaveRequest($contractor, $submittedAt, self::B2B_REQUEST);
        $contractorDate = self::date('2026-12-16');
        $contractorRevision = new RequestRevision($contractorRequest, RequestChangeKind::INITIAL, $paid, RequestMode::HOURLY, $contractorDate, $contractorDate, [new RequestedDay($contractorAssignment, $contractorDate, 3)], $contractorSubmitted);
        $contractorOccupancy = new DailyOccupancy($contractor, $contractorDate);
        $contractorOccupancy->changeBy(3);
        $contractorReserved = new LedgerEntry($contractorAccount, $contractorPool, $contractorSubmitted, 1, LedgerEntryKind::RESERVE, 0, 3, 0, $contractorRevision->getDays()[0]);
        $contractorApproved = new AuditEvent('demo.request.b2b.approve.v1', 'request.approved', $admin, $contractor, new \DateTimeImmutable('2026-11-02T09:00:00+00:00'), ['state' => 'pending'], ['state' => 'approved']);
        new RequestDecision($contractorRevision, DecisionOutcome::APPROVED, $contractorApproved);
        $contractorCharged = new LedgerEntry($contractorAccount, $contractorPool, $contractorApproved, 1, LedgerEntryKind::APPROVE, 0, -3, 3, $contractorRevision->getDays()[0]);

        foreach ([$employeeSubmitted, $employeeRequest, $employeeOccupancy, $employeeReserved, $contractorSubmitted, $contractorRequest, $contractorOccupancy, $contractorReserved, $contractorApproved, $contractorCharged] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new AuditEvent(self::MARKER, 'demo.loaded', $admin, $admin, $openingTime, [], ['fixture_version' => 1, 'users' => 5, 'teams' => 2, 'requests' => 2]));
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'));
    }
}
