<?php

namespace App\Tests\Functional;

use App\Entity\AbsenceType;
use App\Entity\EmploymentType;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Security\SecurityIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Real sessions, HTTP kernel, workflow and migrated MySQL; previews must roll back everything. */
final class RequestApplicationPreviewTest extends WebTestCase
{
    private const PASSWORD = 'Synthetic preview test phrase 816!';
    private KernelBrowser $client;
    private ClockInterface $previousClock;
    private string $prefix;
    private ?string $loggedIn = null;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->previousClock = Clock::get();
        Clock::set(new MockClock('2026-11-09 12:00:00 Europe/Warsaw'));
        $this->prefix = 'preview_'.bin2hex(random_bytes(6));
        $this->client = self::createClient([], ['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254))]);
        $em = $this->manager();
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', (string) $em->getConnection()->getDatabase());
        $factory = $this->client->getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);
        $hash = $factory->getPasswordHasher(SecurityIdentity::class)->hash(self::PASSWORD);
        $team = new Team($this->prefix.'_team', 'Synthetic preview team');
        $employment = new EmploymentType($this->prefix.'_uop', 'Synthetic UoP', true);
        $em->persist($team);
        $em->persist($employment);
        $objects = [];
        foreach (['admin' => TeamRole::EMPLOYEE, 'employee' => TeamRole::EMPLOYEE, 'manager' => TeamRole::MANAGER, 'other' => TeamRole::EMPLOYEE] as $name => $role) {
            $user = new User('Synthetic', ucfirst($name), $this->prefix.'_'.$name.'@example.test', 'admin' === $name);
            $user->replacePasswordHash($hash, false, null);
            new StaffAssignment($user, $team, $employment, $role, new \DateTimeImmutable('2025-01-01 Europe/Warsaw'));
            $em->persist($user);
            $objects[$name] = $user;
        }
        foreach (['paid' => true, 'unpaid' => false] as $name => $deducts) {
            $type = new AbsenceType($this->prefix.'_'.$name);
            $policy = $type->createVersion('Synthetic '.$name, $deducts, [$employment]);
            $em->persist($type);
            $em->persist($policy);
            $objects[$name] = $type;
        }
        $em->flush();
        foreach ($objects as $name => $object) {
            self::assertNotNull($object->getId());
            $this->ids[$name] = $object->getId();
        }
    }

    protected function tearDown(): void
    {
        try { parent::tearDown(); } finally { Clock::set($this->previousClock); }
    }

    public function testNewFullDayAndThreeHourPreviewsAreRepeatableAndLeaveNoDatabaseChanges(): void
    {
        $this->opening();
        $this->login('employee');
        $before = $this->snapshot();
        foreach ([null, 3, 3] as $hours) {
            $this->send('/api/requests/preview', $this->application('2026-11-10', hours: $hours));
            $preview = $this->success()['preview'];
            $expected = $hours ?? 8;
            self::assertSame(['totalHours', 'days', 'deductsBalance', 'years', 'timezone'], array_keys($preview));
            self::assertSame($expected, $preview['totalHours']);
            self::assertSame([['date' => '2026-11-10', 'hours' => $expected]], $preview['days']);
            self::assertTrue($preview['deductsBalance']);
            self::assertSame('Europe/Warsaw', $preview['timezone']);
            self::assertSame([['year' => 2026, 'beforeAvailableHours' => 80, 'afterAvailableHours' => 80 - $expected, 'additionalReservedHours' => $expected]], $preview['years']);
            self::assertSame($before, $this->snapshot());
        }
        $this->send('/api/requests', $this->application('2026-11-10', hours: 3) + ['operationKey' => $this->key()]);
        $this->success(201);
        self::assertSame(77, $this->balance()['availableHours']);
    }

    public function testEditingEightHoursToThreeShowsReleaseWithoutChangingStoredVersionOrOccupancy(): void
    {
        $this->opening();
        $this->login('employee');
        $receipt = $this->create('2026-11-10');
        $before = $this->snapshot();
        $payload = $this->application('2026-11-10', hours: 3) + ['expectedVersion' => $receipt['version']];
        $this->send('/api/requests/'.$receipt['id'].'/edit-preview', $payload);
        $preview = $this->success()['preview'];
        self::assertSame(3, $preview['totalHours']);
        self::assertSame([['year' => 2026, 'beforeAvailableHours' => 72, 'afterAvailableHours' => 77, 'additionalReservedHours' => 0]], $preview['years']);
        self::assertSame($before, $this->snapshot());
        $this->send('/api/requests/'.$receipt['id'].'/edit', $payload + ['operationKey' => $this->key()]);
        $this->success();
        self::assertSame(77, $this->balance()['availableHours']);
    }

    public function testShiftedChangePreviewReservesOnlyDifferenceAndDoesNotAlterApprovedLeave(): void
    {
        $this->opening();
        $this->login('employee');
        $receipt = $this->approve($this->create('2026-11-09', '2026-11-10'));
        $this->login('employee');
        $before = $this->snapshot();
        $payload = $this->application('2026-11-16', '2026-11-18') + ['expectedVersion' => $receipt['version']];
        $this->send('/api/requests/'.$receipt['id'].'/change-preview', $payload);
        $preview = $this->success()['preview'];
        self::assertSame(24, $preview['totalHours']);
        self::assertSame([['year' => 2026, 'beforeAvailableHours' => 64, 'afterAvailableHours' => 56, 'additionalReservedHours' => 8]], $preview['years']);
        self::assertSame($before, $this->snapshot());
        $this->send('/api/requests/'.$receipt['id'].'/change', $payload + ['operationKey' => $this->key()]);
        self::assertSame('change_pending', $this->success()['operation']['status']);
        self::assertSame(56, $this->balance()['availableHours']);
    }

    public function testShorteningApprovedLeaveShowsNoRefundBeforeDecision(): void
    {
        $this->opening();
        $this->login('employee');
        $receipt = $this->approve($this->create('2026-11-09', '2026-11-10'));
        $this->login('employee');
        $before = $this->snapshot();
        $this->send('/api/requests/'.$receipt['id'].'/change-preview', $this->application('2026-11-09') + ['expectedVersion' => $receipt['version']]);
        self::assertSame([['year' => 2026, 'beforeAvailableHours' => 64, 'afterAvailableHours' => 64, 'additionalReservedHours' => 0]], $this->success()['preview']['years']);
        self::assertSame($before, $this->snapshot());
    }

    public function testYearBoundaryUsesActualWorkdaysAndSeparateYearFundingWithoutPredictedCarry(): void
    {
        $this->opening(80, [['originYear' => 2025, 'remainingHours' => 16]]);
        $balance = $this->balance(2027);
        $this->send('/api/balances/users/'.$this->ids['employee'].'/2027/limit', ['operationKey' => $this->key(), 'expectedVersion' => $balance['version'], 'annualHours' => 24, 'reason' => 'Synthetic next-year limit']);
        $this->success();
        $this->login('employee');
        $before = $this->snapshot();
        $this->send('/api/requests/preview', $this->application('2026-12-31', '2027-01-04'));
        $preview = $this->success()['preview'];
        self::assertSame(16, $preview['totalHours']);
        self::assertSame([['date' => '2026-12-31', 'hours' => 8], ['date' => '2027-01-04', 'hours' => 8]], $preview['days']);
        self::assertSame([
            ['year' => 2026, 'beforeAvailableHours' => 96, 'afterAvailableHours' => 88, 'additionalReservedHours' => 8],
            ['year' => 2027, 'beforeAvailableHours' => 24, 'afterAvailableHours' => 16, 'additionalReservedHours' => 8],
        ], $preview['years']);
        self::assertSame($before, $this->snapshot());
    }

    public function testMovingApprovedLeaveToAnotherYearNeedsNewReservationEvenForSameHours(): void
    {
        $this->opening();
        $balance = $this->balance(2027);
        $this->send('/api/balances/users/'.$this->ids['employee'].'/2027/limit', ['operationKey' => $this->key(), 'expectedVersion' => $balance['version'], 'annualHours' => 24, 'reason' => 'Synthetic next-year limit']);
        $this->success();
        $this->login('employee');
        $receipt = $this->approve($this->create('2026-12-31'));
        $this->login('employee');
        $before = $this->snapshot();
        $this->send('/api/requests/'.$receipt['id'].'/change-preview', $this->application('2027-01-04') + ['expectedVersion' => $receipt['version']]);
        self::assertSame([
            ['year' => 2026, 'beforeAvailableHours' => 72, 'afterAvailableHours' => 72, 'additionalReservedHours' => 0],
            ['year' => 2027, 'beforeAvailableHours' => 24, 'afterAvailableHours' => 16, 'additionalReservedHours' => 8],
        ], $this->success()['preview']['years']);
        self::assertSame($before, $this->snapshot());
    }

    public function testNonDeductingPreviewDoesNotRequireAConfiguredFutureLimit(): void
    {
        $this->opening(0);
        $this->login('employee');
        $before = $this->snapshot();
        $this->send('/api/requests/preview', $this->application('2027-01-04', hours: 3, type: 'unpaid'));
        $preview = $this->success()['preview'];
        self::assertFalse($preview['deductsBalance']);
        self::assertSame(3, $preview['totalHours']);
        self::assertSame([['year' => 2027, 'beforeAvailableHours' => 0, 'afterAvailableHours' => 0, 'additionalReservedHours' => 0]], $preview['years']);
        self::assertSame($before, $this->snapshot());
    }

    public function testInsufficientFundsAndOccupiedDayFailWithoutChanges(): void
    {
        $this->opening(8);
        $this->login('employee');
        $before = $this->snapshot();
        $this->send('/api/requests/preview', $this->application('2026-11-09', '2026-11-10'));
        $this->assertError(422, 'INSUFFICIENT_BALANCE');
        self::assertSame($before, $this->snapshot());
        $this->create('2026-11-09', hours: 3);
        $before = $this->snapshot();
        $this->send('/api/requests/preview', $this->application('2026-11-09', hours: 6, type: 'unpaid'));
        $this->assertError(409, 'DAILY_CAPACITY_EXCEEDED');
        self::assertSame($before, $this->snapshot());
    }

    public function testPreviewCannotReadAnotherEmployeesBalanceEvenForTheirManagerOrAdministrator(): void
    {
        $this->opening();
        $this->login('employee');
        $receipt = $this->create('2026-11-10');
        $payload = $this->application('2026-11-10', hours: 3) + ['expectedVersion' => $receipt['version']];
        foreach (['other', 'manager', 'admin'] as $actor) {
            $this->login($actor);
            $before = $this->snapshot();
            $this->send('/api/requests/'.$receipt['id'].'/edit-preview', $payload);
            $this->assertError(403, 'FORBIDDEN');
            self::assertStringNotContainsString('AvailableHours', $this->content());
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testStaleVersionAndRevokedSessionAreRejected(): void
    {
        $this->opening();
        $this->login('employee');
        $receipt = $this->create('2026-11-10');
        $payload = $this->application('2026-11-10', hours: 3) + ['expectedVersion' => $receipt['version']];
        $this->send('/api/requests/'.$receipt['id'].'/edit', $payload + ['operationKey' => $this->key()]);
        $this->success();
        $before = $this->snapshot();
        $this->send('/api/requests/'.$receipt['id'].'/edit-preview', $payload);
        $this->assertError(409, 'CONFLICT');
        self::assertSame($before, $this->snapshot());
        $em = $this->manager();
        $user = $em->find(User::class, $this->ids['employee']);
        self::assertInstanceOf(User::class, $user);
        $user->deactivate();
        $em->flush();
        $this->send('/api/requests/preview', $this->application('2026-11-16'), false);
        $this->assertError(401, 'AUTH_REQUIRED');
    }

    public function testEveryPreviewEndpointRequiresSessionAndCsrf(): void
    {
        $this->send('/api/requests/preview', $this->application('2026-11-10'), false);
        $this->assertError(401, 'AUTH_REQUIRED');
        $this->opening();
        $this->login('employee');
        $receipt = $this->create('2026-11-10');
        foreach (['/api/requests/preview', '/api/requests/'.$receipt['id'].'/edit-preview', '/api/requests/'.$receipt['id'].'/change-preview'] as $path) {
            $this->send($path, $this->application('2026-11-16'), false);
            $this->assertError(403, 'CSRF_INVALID');
        }
    }

    public function testSuccessfulPreviewDoesNotBypassChangedBalanceOnSubmission(): void
    {
        $this->opening(8);
        $this->login('employee');
        $payload = $this->application('2026-11-10');
        $this->send('/api/requests/preview', $payload);
        self::assertSame(0, $this->success()['preview']['years'][0]['afterAvailableHours']);
        $this->login('admin');
        $balance = $this->balance();
        $this->send('/api/balances/users/'.$this->ids['employee'].'/2026/limit', ['operationKey' => $this->key(), 'expectedVersion' => $balance['version'], 'annualHours' => 0, 'reason' => 'Synthetic changed limit after preview']);
        $this->success();
        $this->login('employee');
        $before = $this->snapshot();
        $this->send('/api/requests', $payload + ['operationKey' => $this->key()]);
        $this->assertError(422, 'INSUFFICIENT_BALANCE');
        self::assertSame($before, $this->snapshot());
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidInputs')]
    public function testInvalidOrUnexpectedInputIsRejectedWithoutWrites(array $changes, string $code): void
    {
        $this->opening();
        $this->login('employee');
        $before = $this->snapshot();
        $this->send('/api/requests/preview', array_replace($this->application('2026-11-10'), $changes));
        $this->assertError(422, $code);
        self::assertSame($before, $this->snapshot());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'idempotency key is forbidden in preview' => [['operationKey' => 'not-a-write'], 'VALIDATION_FAILED'];
        yield 'subject override is forbidden' => [['userId' => 1], 'VALIDATION_FAILED'];
        yield 'new preview has no version' => [['expectedVersion' => 1], 'VALIDATION_FAILED'];
        yield 'past cannot be self-served' => [['startDate' => '2026-11-06', 'endDate' => '2026-11-06'], 'PAST_REQUEST'];
        yield 'holiday has no hours' => [['startDate' => '2026-11-11', 'endDate' => '2026-11-11'], 'NO_WORKING_DAYS'];
        yield 'fractional hours forbidden' => [['mode' => 'hourly', 'hours' => 1.5], 'VALIDATION_FAILED'];
        yield 'hourly range forbidden' => [['mode' => 'hourly', 'hours' => 3, 'endDate' => '2026-11-12'], 'VALIDATION_FAILED'];
    }

    /** @param list<array{originYear: int, remainingHours: int}> $carries */
    private function opening(int $annual = 80, array $carries = []): void
    {
        $this->login('admin');
        $balance = $this->balance();
        $this->send('/api/balances/users/'.$this->ids['employee'].'/opening', ['operationKey' => $this->key(), 'expectedVersion' => $balance['version'],
            'effectiveDate' => '2026-01-01', 'annualHours' => $annual, 'currentYearUsedHours' => 0, 'carries' => $carries]);
        $this->success();
    }

    /** @return array<string, mixed> */
    private function application(string $start, ?string $end = null, ?int $hours = null, string $type = 'paid'): array
    {
        $data = ['absenceTypeId' => $this->ids[$type], 'startDate' => $start, 'endDate' => $end ?? $start, 'mode' => null === $hours ? 'full_days' : 'hourly'];
        if (null !== $hours) { $data['hours'] = $hours; }
        return $data;
    }

    /** @return array<string, mixed> */
    private function create(string $start, ?string $end = null, ?int $hours = null): array
    {
        $this->send('/api/requests', $this->application($start, $end, $hours) + ['operationKey' => $this->key()]);
        return $this->success(201)['operation'];
    }

    /** @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function approve(array $receipt): array
    {
        $this->login('manager');
        $this->send('/api/requests/'.$receipt['id'].'/approve', ['operationKey' => $this->key(), 'expectedVersion' => $receipt['version']]);
        return $this->success()['operation'];
    }

    /** Snapshot actual content and row versions, not merely counts. AUTO_INCREMENT gaps are allowed.
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $db = $this->manager()->getConnection();
        $snapshot = [];
        $tables = [
            'leave_request' => 'user_id', 'audit_event' => 'subject_id', 'leave_account' => 'user_id',
            'hour_pool' => 'user_id', 'daily_occupancy' => 'user_id',
        ];
        foreach ($tables as $table => $column) {
            $snapshot[$table] = $db->fetchAllAssociative('SELECT * FROM '.$table.' WHERE '.$column.' = ? ORDER BY id', [$this->ids['employee']]);
        }
        $snapshot['request_revision'] = $db->fetchAllAssociative('SELECT r.* FROM request_revision r JOIN leave_request q ON q.id=r.request_id WHERE q.user_id=? ORDER BY r.id', [$this->ids['employee']]);
        foreach (['request_decision', 'request_day', 'request_assignment_context'] as $table) {
            $snapshot[$table] = $db->fetchAllAssociative('SELECT d.* FROM '.$table.' d JOIN request_revision r ON r.id=d.revision_id JOIN leave_request q ON q.id=r.request_id WHERE q.user_id=? ORDER BY d.id', [$this->ids['employee']]);
        }
        $snapshot['request_accounting_state'] = $db->fetchAllAssociative('SELECT s.* FROM request_accounting_state s JOIN leave_request q ON q.id=s.request_id WHERE q.user_id=? ORDER BY s.id', [$this->ids['employee']]);
        $snapshot['ledger_entry'] = $db->fetchAllAssociative('SELECT e.* FROM ledger_entry e JOIN hour_pool p ON p.id=e.pool_id WHERE p.user_id=? ORDER BY e.id', [$this->ids['employee']]);
        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function balance(int $year = 2026): array
    {
        $this->client->request('GET', '/api/balances/users/'.$this->ids['employee'].'/'.$year);
        return $this->success()['balance'];
    }

    private function login(string $user): void
    {
        if ($this->loggedIn === $user) { return; }
        if (null !== $this->loggedIn) { $this->send('/api/auth/logout', []); $this->success(); }
        $this->send('/api/auth/login', ['email' => $this->prefix.'_'.$user.'@example.test', 'password' => self::PASSWORD]);
        self::assertSame($this->ids[$user], $this->success()['user']['id']);
        $this->loggedIn = $user;
    }

    /** @param array<string, mixed> $body */
    private function send(string $path, array $body, bool $csrf = true): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf) {
            $this->client->request('GET', '/api/auth/csrf');
            $headers['HTTP_X_CSRF_TOKEN'] = $this->success()['csrfToken'];
        }
        $this->client->request('POST', $path, [], [], $headers, json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function success(int $status = 200): array
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $this->content());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');
        return json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertError(int $status, string $code): void
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $this->content());
        self::assertSame($code, json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR)['error']['code']);
    }

    private function content(): string
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        return $content;
    }

    private function manager(): EntityManagerInterface
    {
        $registry = $this->client->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $em = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        return $em;
    }

    private function key(): string { return $this->prefix.'_'.bin2hex(random_bytes(10)); }
}
