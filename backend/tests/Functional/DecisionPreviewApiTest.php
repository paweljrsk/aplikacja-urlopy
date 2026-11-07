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
final class DecisionPreviewApiTest extends WebTestCase
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
        $this->prefix = 'decisionpreview_'.bin2hex(random_bytes(6));
        $this->client = self::createClient([], ['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254))]);
        $em = $this->manager();
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', (string) $em->getConnection()->getDatabase());
        $factory = $this->client->getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);
        $hash = $factory->getPasswordHasher(SecurityIdentity::class)->hash(self::PASSWORD);
        $team = new Team($this->prefix.'_team', 'Synthetic preview team');
        $employment = new EmploymentType($this->prefix.'_uop', 'Synthetic UoP', true);
        $otherTeam = new Team($this->prefix.'_other', 'Synthetic other team');
        $em->persist($team);
        $em->persist($otherTeam);
        $em->persist($employment);
        $objects = ['team' => $team, 'otherTeam' => $otherTeam, 'employment' => $employment];
        foreach (['admin' => TeamRole::EMPLOYEE, 'employee' => TeamRole::EMPLOYEE, 'manager' => TeamRole::MANAGER, 'deputy' => TeamRole::DEPUTY, 'other' => TeamRole::EMPLOYEE, 'otherManager' => TeamRole::MANAGER] as $name => $role) {
            $user = new User('Synthetic', ucfirst($name), $this->prefix.'_'.$name.'@example.test', 'admin' === $name);
            $user->replacePasswordHash($hash, false, null);
            new StaffAssignment($user, \in_array($name, ['other', 'otherManager'], true) ? $otherTeam : $team, $employment, $role, new \DateTimeImmutable('2025-01-01 Europe/Warsaw'));
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

    public function testInitialDecisionPreviewRollsBackAndMatchesSavedDecisionWithoutDoubleCharge(): void
    {
        $this->opening();
        $this->login('employee');
        $request = $this->create('2026-11-10');
        $this->login('manager');
        $before = $this->snapshot();
        $preview = $this->preview($request, 'approve');
        self::assertSame(['id', 'version', 'action', 'statusBefore', 'statusAfter', 'years'], array_keys($preview));
        self::assertSame('pending', $preview['statusBefore']);
        self::assertSame('approved', $preview['statusAfter']);
        self::assertSame($request['version'], $preview['version']);
        self::assertSame($this->year(72, 72, 8, 0, 0, 8), $preview['years']);
        self::assertSame($before, $this->snapshot());
        $rejected = $this->preview($request, 'reject');
        self::assertSame('rejected', $rejected['statusAfter']);
        self::assertSame($this->year(72, 80, 8, 0, 0, 0), $rejected['years']);
        self::assertSame($before, $this->snapshot());
        $body = ['expectedVersion' => $request['version'], 'operationKey' => $this->key()];
        $this->send('/api/requests/'.$request['id'].'/approve', $body);
        $receipt = $this->success()['operation'];
        $stored = $this->snapshot();
        $this->send('/api/requests/'.$request['id'].'/approve', $body);
        $repeat = $this->success()['operation'];
        ksort($receipt); ksort($repeat);
        self::assertSame($receipt, $repeat);
        self::assertSame($stored, $this->snapshot());
        self::assertSame($preview['years'][0]['afterAvailableHours'], $this->balance()['availableHours']);
        $this->send('/api/requests/'.$request['id'].'/reject', ['expectedVersion' => $request['version'], 'operationKey' => $this->key(), 'reason' => 'Synthetic competing decision']);
        $this->assertError(409, 'CONFLICT');
        self::assertSame($stored, $this->snapshot());
    }

    #[DataProvider('proposalDecisions')]
    public function testChangeAndCancellationPreviewShowsActualReservationAndChargeImpact(string $proposal, string $action, int $availableBefore, int $availableAfter, int $reservedBefore, int $approvedAfter): void
    {
        $this->opening();
        $this->login('employee');
        $request = $this->approve($this->create('2026-11-12', '2026-11-13'));
        $this->login('employee');
        if ('cancel' === $proposal) {
            $this->send('/api/requests/'.$request['id'].'/cancel', ['expectedVersion' => $request['version'], 'operationKey' => $this->key()]);
        } else {
            $end = 'increase' === $proposal ? '2026-11-16' : '2026-11-12';
            $this->send('/api/requests/'.$request['id'].'/change', $this->application('2026-11-12', $end) + ['expectedVersion' => $request['version'], 'operationKey' => $this->key()]);
        }
        $request = $this->success()['operation'];
        $this->login('deputy');
        $before = $this->snapshot();
        $preview = $this->preview($request, $action);
        self::assertSame($this->year($availableBefore, $availableAfter, $reservedBefore, 0, 16, $approvedAfter), $preview['years']);
        self::assertSame($before, $this->snapshot());
        $body = ['expectedVersion' => $request['version'], 'operationKey' => $this->key()];
        if ('reject' === $action) { $body['reason'] = 'Synthetic preview rejection'; }
        $this->send('/api/requests/'.$request['id'].'/'.$action, $body);
        self::assertSame($preview['statusAfter'], $this->success()['operation']['status']);
        self::assertSame($availableAfter, $this->balance()['availableHours']);
    }

    /** @return iterable<string, array{string, string, int, int, int, int}> */
    public static function proposalDecisions(): iterable
    {
        yield 'increase accepted' => ['increase', 'approve', 56, 56, 8, 24];
        yield 'increase rejected' => ['increase', 'reject', 56, 64, 8, 16];
        yield 'reduction accepted' => ['reduce', 'approve', 64, 72, 0, 8];
        yield 'reduction rejected' => ['reduce', 'reject', 64, 64, 0, 16];
        yield 'cancellation accepted' => ['cancel', 'approve', 64, 80, 0, 0];
        yield 'cancellation rejected' => ['cancel', 'reject', 64, 64, 0, 16];
    }

    public function testYearBoundaryPreviewPreservesSeparateYearsAndSharedPoolSemantics(): void
    {
        $this->opening();
        $next = $this->balance(2027);
        $this->send('/api/balances/users/'.$this->ids['employee'].'/2027/limit', ['operationKey' => $this->key(), 'expectedVersion' => $next['version'], 'annualHours' => 24, 'reason' => 'Synthetic next year']);
        $this->success();
        $this->login('employee');
        $request = $this->approve($this->create('2026-12-31'));
        $this->login('employee');
        $this->send('/api/requests/'.$request['id'].'/change', $this->application('2027-01-04') + ['expectedVersion' => $request['version'], 'operationKey' => $this->key()]);
        $request = $this->success()['operation'];
        $this->login('manager');
        $before = $this->snapshot();
        $preview = $this->preview($request, 'approve');
        $newYear = $this->year(16, 16, 8, 0, 0, 8)[0];
        $newYear['year'] = 2027;
        self::assertSame([$this->year(72, 80, 0, 0, 8, 0)[0], $newYear], $preview['years']);
        self::assertSame($before, $this->snapshot());
        $this->send('/api/requests/'.$request['id'].'/approve', ['expectedVersion' => $request['version'], 'operationKey' => $this->key()]);
        $this->success();
        self::assertSame(80, $this->balance()['availableHours']);
        self::assertSame(16, $this->balance(2027)['availableHours']);
    }

    public function testAuthorizationMatchesDecisionIncludingOwnManagerAndDeniedOwnDeputy(): void
    {
        foreach (['employee', 'manager', 'deputy', 'other'] as $subject) {
            $this->opening(subject: $subject);
            $this->login($subject);
            $requests[$subject] = $this->create('2026-11-10');
        }
        $this->login('manager');
        $this->preview($requests['manager'], 'approve');
        $this->login('deputy');
        $this->preview($requests['employee'], 'approve');
        $this->send('/api/requests/'.$requests['deputy']['id'].'/decision-preview', ['action' => 'approve', 'expectedVersion' => $requests['deputy']['version']]);
        $this->assertError(403, 'FORBIDDEN');
        foreach (['employee', 'otherManager'] as $actor) {
            $this->login($actor);
            $this->send('/api/requests/'.$requests['employee']['id'].'/decision-preview', ['action' => 'approve', 'expectedVersion' => $requests['employee']['version']]);
            $this->assertError(403, 'FORBIDDEN');
            self::assertStringNotContainsString('AvailableHours', $this->content());
        }
        $this->login('admin');
        $this->preview($requests['other'], 'approve');
    }

    public function testHistoricalSupervisorCanPreviewWithoutReadingCurrentBalanceAfterTransfer(): void
    {
        $this->opening();
        $this->login('employee');
        $request = $this->create('2026-11-10');
        $this->send('/api/auth/logout', []); $this->success(); $this->loggedIn = null;
        $em = $this->manager();
        $employee = $em->find(User::class, $this->ids['employee']);
        self::assertInstanceOf(User::class, $employee);
        $employee->getAssignments()[0]->closeAt(new \DateTimeImmutable('2026-11-15 Europe/Warsaw'));
        $team = $em->find(Team::class, $this->ids['otherTeam']);
        $employment = $em->find(EmploymentType::class, $this->ids['employment']);
        self::assertInstanceOf(Team::class, $team);
        self::assertInstanceOf(EmploymentType::class, $employment);
        new StaffAssignment($employee, $team, $employment, TeamRole::EMPLOYEE, new \DateTimeImmutable('2026-11-15 Europe/Warsaw'));
        $em->flush();
        Clock::set(new MockClock('2026-11-20 12:00:00 Europe/Warsaw'));
        $this->login('manager');
        $before = $this->snapshot();
        $preview = $this->preview($request, 'approve');
        self::assertNull($preview['years']);
        self::assertSame('approved', $preview['statusAfter']);
        self::assertStringNotContainsString('AvailableHours', $this->content());
        self::assertSame($before, $this->snapshot());
        $this->client->request('GET', '/api/balances/users/'.$this->ids['employee'].'/2026');
        $this->assertError(403, 'FORBIDDEN');
    }

    public function testPreviewRejectsCsrfStaleVersionInvalidInputAndMissingRejectionReason(): void
    {
        $this->opening();
        $this->login('employee');
        $request = $this->create('2026-11-10');
        $this->login('manager');
        $before = $this->snapshot();
        $valid = ['expectedVersion' => $request['version'], 'action' => 'approve'];
        $this->send('/api/requests/'.$request['id'].'/decision-preview', $valid, false);
        $this->assertError(403, 'CSRF_INVALID');
        foreach ([[$valid + ['operationKey' => 'must-not-write'], 'VALIDATION_FAILED'],
            [array_replace($valid, ['expectedVersion' => $request['version'] + 1]), 'CONFLICT'],
            [array_replace($valid, ['action' => 'reject']), 'REASON_REQUIRED'],
            [array_replace($valid, ['action' => 'withdraw']), 'VALIDATION_FAILED'],
            [$valid + ['reason' => 'Unexpected approve reason'], 'VALIDATION_FAILED']] as [$body, $code]) {
            $this->send('/api/requests/'.$request['id'].'/decision-preview', $body);
            $this->assertError('CONFLICT' === $code ? 409 : 422, $code);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testAwaitingQueueCombinesInitialChangeCancellationAndPaginatesWithoutIncludingApproved(): void
    {
        $this->opening(160);
        $this->login('employee');
        $initial = $this->create('2026-11-10');
        $change = $this->approve($this->create('2026-11-12'));
        $this->login('employee');
        $this->send('/api/requests/'.$change['id'].'/change', $this->application('2026-11-13') + ['expectedVersion' => $change['version'], 'operationKey' => $this->key()]);
        $change = $this->success()['operation'];
        $cancel = $this->approve($this->create('2026-11-16'));
        $this->login('employee');
        $this->send('/api/requests/'.$cancel['id'].'/cancel', ['expectedVersion' => $cancel['version'], 'operationKey' => $this->key()]);
        $cancel = $this->success()['operation'];
        $approved = $this->approve($this->createAsEmployee('2026-11-17'));
        $this->login('manager');
        $found = [];
        $cursor = null;
        do {
            $this->client->request('GET', '/api/requests?scope=managed&status=awaiting&limit=2'.(null === $cursor ? '' : '&beforeId='.$cursor));
            $page = $this->success();
            foreach ($page['requests'] as $row) { $found[$row['id']] = $row['status']; }
            $cursor = $page['nextBeforeId'];
        } while (null !== $cursor);
        self::assertSame([$cancel['id'] => 'cancellation_pending', $change['id'] => 'change_pending', $initial['id'] => 'pending'], $found);
        self::assertArrayNotHasKey($approved['id'], $found);
        $this->login('employee');
        $this->client->request('GET', '/api/requests?scope=managed&status=awaiting');
        $this->assertError(403, 'FORBIDDEN');
    }

    /** @return array<string, mixed> */
    private function createAsEmployee(string $date): array { $this->login('employee'); return $this->create($date); }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function preview(array $request, string $action): array
    {
        $body = ['expectedVersion' => $request['version'], 'action' => $action];
        if ('reject' === $action) { $body['reason'] = 'Synthetic preview rejection'; }
        $this->send('/api/requests/'.$request['id'].'/decision-preview', $body);
        return $this->success()['preview'];
    }

    /** @return list<array<string, int>> */
    private function year(int $beforeAvailable, int $afterAvailable, int $beforeReserved, int $afterReserved, int $beforeApproved, int $afterApproved): array
    {
        return [['year' => 2026, 'beforeAvailableHours' => $beforeAvailable, 'afterAvailableHours' => $afterAvailable,
            'beforeReservedHours' => $beforeReserved, 'afterReservedHours' => $afterReserved,
            'beforeApprovedHours' => $beforeApproved, 'afterApprovedHours' => $afterApproved]];
    }

    /** @param list<array{originYear: int, remainingHours: int}> $carries */
    private function opening(int $annual = 80, array $carries = [], string $subject = 'employee'): void
    {
        $this->login('admin');
        $balance = $this->balance(subject: $subject);
        $this->send('/api/balances/users/'.$this->ids[$subject].'/opening', ['operationKey' => $this->key(), 'expectedVersion' => $balance['version'],
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
    private function balance(int $year = 2026, string $subject = 'employee'): array
    {
        $this->client->request('GET', '/api/balances/users/'.$this->ids[$subject].'/'.$year);
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
