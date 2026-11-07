<?php

namespace App\Tests\Functional;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\EmploymentType;
use App\Entity\LeaveRequest;
use App\Entity\RequestDecision;
use App\Entity\RequestRevision;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DecisionOutcome;
use App\Enum\RequestChangeKind;
use App\Enum\RequestMode;
use App\Enum\TeamRole;
use App\Model\RequestedDay;
use App\Security\BalanceAccessPolicy;
use App\Security\CurrentAssignmentResolver;
use App\Security\RequestAccessPolicy;
use App\Security\SecurityIdentity;
use App\Service\Accounting\AccountingService;
use App\Service\Calendar\WorkingCalendar;
use App\Service\CredentialsException;
use App\Service\Requests\RequestQueryService;
use App\Service\Requests\RequestWorkflowService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Real HTTP boundary, sessions and database; every fixture is synthetic and isolated. */
final class RequestApiTest extends WebTestCase
{
    private const PASSWORD = 'Synthetic request API test phrase 51!';

    private KernelBrowser $client;
    private MockClock $clock;
    private ClockInterface $previousClock;
    private string $prefix;
    private ?string $loggedIn = null;

    /** @var array<string, array{id: int, email: string}> */
    private array $users = [];
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->previousClock = Clock::get();
        $this->clock = new MockClock('2026-11-09 12:00:00 Europe/Warsaw');
        Clock::set($this->clock);
        $this->prefix = 'reqapi_'.bin2hex(random_bytes(6));
        $this->client = self::createClient([], ['REMOTE_ADDR' => sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254)), 'HTTP_HOST' => 'localhost']);
        $em = $this->manager();
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', (string) $em->getConnection()->getDatabase());
        self::assertStringContainsString('mysql', $em->getConnection()->getParams()['driver'] ?? '');
        $factory = $this->client->getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);
        $hash = $factory->getPasswordHasher(SecurityIdentity::class)->hash(self::PASSWORD);
        $alpha = new Team($this->prefix.'_a', 'Synthetic Alpha');
        $beta = new Team($this->prefix.'_b', 'Synthetic Beta');
        $employment = new EmploymentType($this->prefix.'_uop', 'Synthetic UoP', true);
        foreach ([$alpha, $beta, $employment] as $entity) {
            $em->persist($entity);
        }
        $objects = [];
        foreach (['admin' => TeamRole::EMPLOYEE, 'employee' => TeamRole::EMPLOYEE, 'manager' => TeamRole::MANAGER, 'deputy' => TeamRole::DEPUTY, 'other' => TeamRole::EMPLOYEE, 'managerBeta' => TeamRole::MANAGER] as $key => $role) {
            $user = new User('Synthetic', ucfirst($key), $this->prefix.'_'.$key.'@example.test', 'admin' === $key);
            $user->replacePasswordHash($hash, false, null);
            new StaffAssignment($user, \in_array($key, ['other', 'managerBeta'], true) ? $beta : $alpha, $employment, $role, self::date('2025-01-01'));
            $em->persist($user);
            $objects[$key] = $user;
        }
        foreach (['paid' => true, 'unpaid' => false, 'inactive' => false] as $key => $deducts) {
            $type = new AbsenceType($this->prefix.'_'.$key);
            $policy = $type->createVersion('Synthetic '.$key, $deducts, [$employment]);
            if ('inactive' === $key) {
                $type->deactivate();
            }
            $em->persist($type);
            $em->persist($policy);
            $objects[$key] = $type;
            $objects[$key.'_policy'] = $policy;
        }
        $em->flush();
        foreach ($objects as $key => $entity) {
            self::assertNotNull($entity->getId());
            $this->ids[$key] = $entity->getId();
            if ($entity instanceof User) {
                $this->users[$key] = ['id' => $entity->getId(), 'email' => $entity->getEmail()];
            }
        }
        self::assertNotNull($beta->getId());
        self::assertNotNull($employment->getId());
        $this->ids['beta'] = $beta->getId();
        $this->ids['employment'] = $employment->getId();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Clock::set($this->previousClock);
        }
    }

    public function testAnonymousReadsAndMissingCsrfAreRejectedWithoutMutation(): void
    {
        foreach (['/api/requests', '/api/requests/options', '/api/requests/1'] as $path) {
            $this->client->request('GET', $path);
            $this->assertError(401, 'AUTH_REQUIRED');
        }
        $this->login('employee');
        $before = $this->requestCount();
        $this->send('/api/requests', $this->body('2026-11-10'), false);
        $this->assertError(403, 'CSRF_INVALID');
        self::assertSame($before, $this->requestCount());
    }

    public function testEmployeeOnlyReadsOwnRequestsAndCannotImpersonateOrDecide(): void
    {
        $this->opening('employee');
        $this->opening('other');
        $this->login('other');
        $foreign = $this->create('2026-11-10');
        $this->login('employee');
        $own = $this->create('2026-11-10');
        $this->client->request('GET', '/api/requests');
        self::assertSame([$own['id']], array_column($this->success()['requests'], 'id'));
        $this->client->request('GET', '/api/requests/'.$foreign['id']);
        $this->assertError(403, 'FORBIDDEN');
        $this->send('/api/requests/'.$foreign['id'].'/edit', $this->body('2026-11-12') + ['expectedVersion' => $foreign['version']]);
        $this->assertError(403, 'FORBIDDEN');
        $this->send('/api/requests/'.$own['id'].'/approve', $this->transition($own));
        $this->assertError(403, 'FORBIDDEN');
        $this->send('/api/requests', $this->body('2026-11-12') + ['userId' => $this->ids['other']]);
        $this->assertError(422);
        $this->client->request('GET', '/api/requests/options');
        $options = $this->success();
        $serialized = json_encode($options, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->users['other']['email'], $serialized);
        self::assertStringNotContainsString('availableHours', $serialized);
        self::assertStringNotContainsString('Synthetic inactive', $serialized);
        self::assertStringContainsString('Synthetic paid', $serialized);
    }

    public function testSubmissionApprovalAndElapsedDatesDeductOnlyOnce(): void
    {
        $this->opening('employee', 80);
        $this->login('employee');
        $body = $this->body('2026-11-12', '2026-11-13');
        $this->send('/api/requests', $body);
        $created = $this->success(201)['operation'];
        self::assertSame('pending', $created['status']);
        self::assertSame(64, $this->balance('employee')['availableHours']);
        $ledger = $this->ledgerCount();
        $this->send('/api/requests', $body);
        $this->assertSameReceipt($created, $this->success(201)['operation']);
        self::assertSame($ledger, $this->ledgerCount());
        $this->send('/api/requests', array_replace($body, ['endDate' => '2026-11-16']));
        $this->assertError(409);
        $this->login('manager');
        $approval = $this->transition($created);
        $this->send('/api/requests/'.$created['id'].'/approve', $approval);
        $approved = $this->success()['operation'];
        self::assertSame('approved', $approved['status']);
        self::assertArrayNotHasKey('balance', $approved);
        self::assertSame(64, $this->balance('employee')['availableHours']);
        $ledger = $this->ledgerCount();
        $this->send('/api/requests/'.$created['id'].'/approve', $approval);
        $this->assertSameReceipt($approved, $this->success()['operation']);
        self::assertSame($ledger, $this->ledgerCount());
        $this->send('/api/requests/'.$created['id'].'/approve', $this->transition($approved));
        $this->assertError(409);
        $this->logout();
        $this->clock->modify('2026-11-16 12:00:00 Europe/Warsaw');
        $this->login('employee');
        $balance = $this->balance('employee');
        self::assertSame(64, $balance['availableHours']);
        self::assertSame(16, $balance['usedHours']);
        self::assertSame(0, $balance['reservedHours']);
    }

    public function testTodayCanBeEditedAndWithdrawnWithVersionAndReplayProtection(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $created = $this->create('2026-11-09', null, 3);
        $editBody = $this->body('2026-11-09', null, 5) + ['expectedVersion' => $created['version']];
        $this->send('/api/requests/'.$created['id'].'/edit', $editBody);
        $edited = $this->success()['operation'];
        self::assertSame($created['id'], $edited['id']);
        self::assertGreaterThan($created['version'], $edited['version']);
        self::assertSame(75, $this->balance('employee')['availableHours']);
        $this->send('/api/requests/'.$created['id'].'/edit', $editBody);
        $this->assertSameReceipt($edited, $this->success()['operation']);
        $this->send('/api/requests/'.$created['id'].'/edit', array_replace($editBody, ['operationKey' => $this->key(), 'hours' => 4]));
        $this->assertError(409);
        $details = $this->detail($created['id']);
        self::assertCount(2, $details['history']);
        self::assertSame(5, $details['current']['totalHours']);
        self::assertTrue($details['actions']['withdraw']);
        $withdraw = $this->transition($edited);
        $this->send('/api/requests/'.$created['id'].'/withdraw', $withdraw);
        $withdrawn = $this->success()['operation'];
        self::assertSame('withdrawn', $withdrawn['status']);
        self::assertSame(80, $this->balance('employee')['availableHours']);
        $this->send('/api/requests/'.$created['id'].'/withdraw', $withdraw);
        $this->assertSameReceipt($withdrawn, $this->success()['operation']);
        self::assertSame(80, $this->balance('employee')['availableHours']);
    }

    public function testRejectionRequiresReasonAndKeepsItsVisibilityScoped(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $created = $this->create('2026-11-10');
        $this->login('manager');
        foreach ([null, '', '   ', str_repeat('x', 1001)] as $invalid) {
            $body = $this->transition($created);
            if (null !== $invalid) {
                $body['reason'] = $invalid;
            }
            $this->send('/api/requests/'.$created['id'].'/reject', $body);
            $this->assertError(422);
        }
        $reason = 'SYNTHETIC_REJECTION_PRIVATE_REASON';
        $this->send('/api/requests/'.$created['id'].'/reject', $this->transition($created) + ['reason' => $reason]);
        self::assertSame('rejected', $this->success()['operation']['status']);
        self::assertSame($reason, $this->detail($created['id'])['current']['decision']['reason']);
        $supervisorBalance = $this->balance('employee');
        self::assertStringNotContainsString($reason, json_encode($supervisorBalance, JSON_THROW_ON_ERROR));
        foreach ($supervisorBalance['history'] as $event) {
            self::assertTrue(str_starts_with($event['action'], 'balance.') || str_starts_with($event['action'], 'opening.'));
        }
        $this->login('employee');
        self::assertSame($reason, $this->detail($created['id'])['current']['decision']['reason']);
        $ownBalance = $this->balance('employee');
        self::assertSame(80, $ownBalance['availableHours']);
        self::assertContains('request.created', array_column($ownBalance['history'], 'action'));
        self::assertContains('request.rejected', array_column($ownBalance['history'], 'action'));
        self::assertNotContains('request.operation_completed', array_column($ownBalance['history'], 'action'));
        self::assertStringContainsString($reason, json_encode($ownBalance['history'], JSON_THROW_ON_ERROR));
        $this->login('other');
        $this->client->request('GET', '/api/requests/'.$created['id']);
        $this->assertError(403, 'FORBIDDEN');
        self::assertStringNotContainsString($reason, $this->content());
    }

    public function testManagerOwnDeputyOtherAndAdministratorCrossTeamDecisionMatrix(): void
    {
        foreach (['manager', 'deputy', 'employee', 'other'] as $user) {
            $this->opening($user);
        }
        $this->login('manager');
        $manager = $this->create('2026-11-10');
        $this->send('/api/requests/'.$manager['id'].'/approve', $this->transition($manager));
        self::assertSame('approved', $this->success()['operation']['status']);
        $this->login('deputy');
        $deputy = $this->create('2026-11-10');
        $this->send('/api/requests/'.$deputy['id'].'/approve', $this->transition($deputy));
        $this->assertError(403, 'FORBIDDEN');
        $this->login('employee');
        $employee = $this->create('2026-11-10');
        $this->login('deputy');
        $this->send('/api/requests/'.$employee['id'].'/approve', $this->transition($employee));
        self::assertSame('approved', $this->success()['operation']['status']);
        $this->login('other');
        $other = $this->create('2026-11-10');
        $this->login('manager');
        $this->send('/api/requests/'.$other['id'].'/reject', $this->transition($other) + ['reason' => 'Synthetic cross-team attempt']);
        $this->assertError(403, 'FORBIDDEN');
        $this->login('admin');
        $this->send('/api/requests/'.$other['id'].'/approve', $this->transition($other));
        self::assertSame('approved', $this->success()['operation']['status']);
    }

    public function testHourlyCapacityIncludesUnpaidAbsenceAndAllowsThreePlusFive(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $this->create('2026-11-10', null, 3);
        $this->send('/api/requests', $this->body('2026-11-10', null, 5, 'unpaid'));
        $this->success(201);
        self::assertSame(77, $this->balance('employee')['availableHours']);
        $before = $this->requestCount();
        $this->send('/api/requests', $this->body('2026-11-10', null, 1, 'unpaid'));
        $this->assertError(409);
        self::assertSame($before, $this->requestCount());
        self::assertSame(77, $this->balance('employee')['availableHours']);
    }

    public function testInputBoundaryRejectsMalformedJsonUnsupportedFieldsAndNonIntegerHours(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $before = $this->requestCount();
        foreach (['[]', '{', '{"padding":"'.str_repeat('a', 65536).'"}'] as $raw) {
            $this->sendRaw($raw);
            $this->assertError(400);
        }
        foreach ([0, -1, 8, 1.5, '3', true] as $hours) {
            $this->send('/api/requests', array_replace($this->body('2026-11-10', null, 3), ['hours' => $hours]));
            $this->assertError(422);
        }
        foreach ([['unexpected' => 1], ['absenceTypeId' => (string) $this->ids['paid']], ['mode' => 'half_day'], ['startDate' => '2026-02-30']] as $changes) {
            $this->send('/api/requests', array_replace($this->body('2026-11-10'), $changes));
            $this->assertError(422);
        }
        self::assertSame($before, $this->requestCount());
    }

    public function testFullDayRangeSkipsHolidayAndWeekendAndCannotStartInPast(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $body = $this->body('2026-11-09', '2026-11-15');
        unset($body['mode']);
        $this->send('/api/requests', $body);
        $created = $this->success(201)['operation'];
        $current = $this->detail($created['id'])['current'];
        self::assertSame('full_days', $current['mode']);
        self::assertSame(32, $current['totalHours']);
        self::assertSame(['2026-11-09', '2026-11-10', '2026-11-12', '2026-11-13'], array_column($current['days'], 'date'));
        self::assertSame(48, $this->balance('employee')['availableHours']);
        foreach ([['2026-11-07', '2026-11-07'], ['2026-11-14', '2026-11-15'], ['2026-11-11', '2026-11-11']] as [$start, $end]) {
            $this->send('/api/requests', $this->body($start, $end));
            $this->assertError(422);
        }
        $this->logout();
        $this->clock->modify('2026-11-10 00:00:00 Europe/Warsaw');
        $this->login('employee');
        $this->send('/api/requests/'.$created['id'].'/withdraw', $this->transition($created));
        $this->assertError(422);
        $this->send('/api/requests/'.$created['id'].'/edit', $this->body('2026-11-12') + ['expectedVersion' => $created['version']]);
        $this->assertError(422);
        self::assertSame(48, $this->balance('employee')['availableHours']);
    }

    public function testPaginationAndManagedScopeNeverIncludeAnotherTeam(): void
    {
        $this->opening('employee');
        $this->opening('other');
        $this->login('employee');
        $first = $this->create('2026-11-10');
        $second = $this->create('2026-11-12');
        $this->login('other');
        $foreign = $this->create('2026-11-13');
        $this->login('employee');
        $this->client->request('GET', '/api/requests?limit=1');
        $page = $this->success();
        self::assertSame([$second['id']], array_column($page['requests'], 'id'));
        self::assertSame($second['id'], $page['nextBeforeId']);
        $this->client->request('GET', '/api/requests?limit=1&beforeId='.$page['nextBeforeId']);
        $next = $this->success();
        self::assertSame([$first['id']], array_column($next['requests'], 'id'));
        self::assertNull($next['nextBeforeId']);
        foreach (['limit=101', 'limit=0', 'beforeId=-1', 'scope=all', 'limit[]=1'] as $query) {
            $this->client->request('GET', '/api/requests?'.$query);
            self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);
        }
        $this->login('manager');
        $this->client->request('GET', '/api/requests?scope=managed');
        $managed = array_column($this->success()['requests'], 'id');
        self::assertContains($first['id'], $managed);
        self::assertContains($second['id'], $managed);
        self::assertNotContains($foreign['id'], $managed);
    }

    public function testHistoricalVisibilityIsCheckedForEachRevisionAfterTransfer(): void
    {
        $id = $this->historicalTransferredRequest(false);
        $this->login('manager');
        $old = $this->detail($id);
        self::assertFalse($old['currentVisible']);
        self::assertNull($old['current']);
        self::assertNull($old['version']);
        self::assertNull($old['status']);
        self::assertSame([1], array_column($old['history'], 'revision'));
        self::assertSame([false], array_values(array_unique($old['actions'])));
        self::assertStringNotContainsString('NEW_TEAM_REASON', $this->content());
        self::assertStringNotContainsString('RAW_AUDIT_SECRET', $this->content());
        $this->login('managerBeta');
        $new = $this->detail($id);
        self::assertTrue($new['currentVisible']);
        self::assertSame([2], array_column($new['history'], 'revision'));
        self::assertSame('NEW_TEAM_REASON', $new['current']['decision']['reason']);
        self::assertStringNotContainsString('OLD_TEAM_REASON', $this->content());
        self::assertStringNotContainsString('RAW_AUDIT_SECRET', $this->content());
        $this->login('employee');
        self::assertCount(2, $this->detail($id)['history']);
        $this->login('other');
        $this->client->request('GET', '/api/requests/'.$id);
        $this->assertError(403, 'FORBIDDEN');
    }

    public function testMixedTeamRequestDetailsAreRestrictedToOwnerAndAdministrator(): void
    {
        $id = $this->historicalTransferredRequest(true);
        foreach (['manager', 'managerBeta', 'other'] as $key) {
            $this->login($key);
            $this->client->request('GET', '/api/requests/'.$id);
            $this->assertError(403, 'FORBIDDEN');
        }
        $this->login('employee');
        self::assertCount(4, $this->detail($id)['current']['days']);
        $this->login('admin');
        $details = $this->detail($id);
        self::assertTrue($details['actions']['approve']);
        self::assertFalse($details['actions']['edit']);
    }

    public function testReadBoundaryDiscardsStaleDecisionAndRevokedActorSnapshots(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $created = $this->create('2026-11-10');
        $em = $this->manager();
        $actor = $em->find(User::class, $this->ids['manager']);
        $staleRequest = $em->find(LeaveRequest::class, $created['id']);
        self::assertInstanceOf(User::class, $actor);
        self::assertInstanceOf(LeaveRequest::class, $staleRequest);
        self::assertNotNull($staleRequest->getPendingRevision());
        self::assertNull($staleRequest->getPendingRevision()->getDecision());
        $resolver = new CurrentAssignmentResolver();
        $access = new RequestAccessPolicy($resolver);
        $query = new RequestQueryService($em, $access, $resolver, $this->clock);
        $connection = DriverManager::getConnection($em->getConnection()->getParams());
        $other = new EntityManager($connection, $em->getConfiguration());
        try {
            $otherActor = $other->find(User::class, $this->ids['manager']);
            self::assertInstanceOf(User::class, $otherActor);
            $accounting = new AccountingService($other, $this->clock, $resolver, new BalanceAccessPolicy($resolver), $access);
            $workflow = new RequestWorkflowService($other, $this->clock, $resolver, $access, new WorkingCalendar($other), $accounting);
            $workflow->approve($otherActor, $created['id'], $this->transition($created));
            self::assertNull($staleRequest->getPendingRevision()->getDecision(), 'The first EntityManager still holds the old graph.');
            $fresh = $query->detail($actor, $created['id']);
            self::assertSame('approved', $fresh['status']);
            self::assertSame('approved', $fresh['current']['decision']['outcome']);

            $staleActor = $em->find(User::class, $this->ids['manager']);
            self::assertInstanceOf(User::class, $staleActor);
            self::assertTrue($staleActor->isActive());
            $otherActor = $other->find(User::class, $this->ids['manager']);
            self::assertInstanceOf(User::class, $otherActor);
            $otherActor->deactivate();
            $other->flush();
            try {
                $query->detail($staleActor, $created['id']);
                self::fail('A previously loaded active actor bypassed revocation at the read boundary.');
            } catch (CredentialsException $exception) {
                self::assertSame(401, $exception->httpStatus);
            }
        } finally {
            $other->close();
            $connection->close();
        }
    }

    private function historicalTransferredRequest(bool $mixed): int
    {
        $em = $this->manager();
        $subject = $em->find(User::class, $this->ids['employee']);
        $admin = $em->find(User::class, $this->ids['admin']);
        $beta = $em->find(Team::class, $this->ids['beta']);
        $employment = $em->find(EmploymentType::class, $this->ids['employment']);
        $policy = $em->find(AbsenceTypeVersion::class, $this->ids['paid_policy']);
        self::assertInstanceOf(User::class, $subject);
        self::assertInstanceOf(User::class, $admin);
        self::assertInstanceOf(Team::class, $beta);
        self::assertInstanceOf(EmploymentType::class, $employment);
        self::assertInstanceOf(AbsenceTypeVersion::class, $policy);
        $oldAssignment = $subject->getAssignments()[0];
        $oldAssignment->closeAt(self::date('2026-11-06'));
        $newAssignment = new StaffAssignment($subject, $beta, $employment, TeamRole::EMPLOYEE, self::date('2026-11-06'));
        $em->persist($newAssignment);
        $request = new LeaveRequest($subject, self::date('2026-11-01'));
        $source = new AuditEvent($this->key(), 'request.created', $subject, $subject, self::date('2026-11-01'), ['secret' => 'RAW_AUDIT_SECRET'], ['secret' => 'RAW_AUDIT_SECRET'], 'request', $request->getReference());
        $em->persist($source);
        if ($mixed) {
            new RequestRevision($request, RequestChangeKind::INITIAL, $policy, RequestMode::FULL_DAYS, self::date('2026-11-05'), self::date('2026-11-10'), [
                new RequestedDay($oldAssignment, self::date('2026-11-05'), 8),
                new RequestedDay($newAssignment, self::date('2026-11-06'), 8),
                new RequestedDay($newAssignment, self::date('2026-11-09'), 8),
                new RequestedDay($newAssignment, self::date('2026-11-10'), 8),
            ], $source);
        } else {
            $old = new RequestRevision($request, RequestChangeKind::INITIAL, $policy, RequestMode::FULL_DAYS, self::date('2026-11-05'), self::date('2026-11-05'), [new RequestedDay($oldAssignment, self::date('2026-11-05'), 8)], $source);
            $superseded = new AuditEvent($this->key(), 'request.edited', $subject, $subject, self::date('2026-11-02'), [], ['reason' => 'OLD_TEAM_REASON', 'secret' => 'RAW_AUDIT_SECRET'], 'request', $request->getReference());
            $em->persist($superseded);
            $em->persist(new RequestDecision($old, DecisionOutcome::SUPERSEDED, $superseded));
            $current = new RequestRevision($request, RequestChangeKind::INITIAL, $policy, RequestMode::FULL_DAYS, self::date('2026-11-10'), self::date('2026-11-10'), [new RequestedDay($newAssignment, self::date('2026-11-10'), 8)], $superseded);
            $rejection = new AuditEvent($this->key(), 'request.rejected', $admin, $subject, self::date('2026-11-03'), ['secret' => 'RAW_AUDIT_SECRET'], ['reason' => 'NEW_TEAM_REASON'], 'request', $request->getReference());
            $em->persist($rejection);
            $em->persist(new RequestDecision($current, DecisionOutcome::REJECTED, $rejection));
        }
        $em->persist($request);
        $em->flush();
        self::assertNotNull($request->getId());

        return $request->getId();
    }

    private function opening(string $user, int $annual = 80): void
    {
        $this->login('admin');
        $balance = $this->balance($user);
        $this->send('/api/balances/users/'.$this->ids[$user].'/opening', [
            'operationKey' => $this->key(), 'expectedVersion' => $balance['version'], 'effectiveDate' => '2026-01-01',
            'annualHours' => $annual, 'currentYearUsedHours' => 0, 'carries' => [],
        ]);
        self::assertSame($annual, $this->success()['balance']['availableHours']);
    }

    /** @return array<string, mixed> */
    private function body(string $start, ?string $end = null, ?int $hours = null, string $type = 'paid'): array
    {
        $body = ['operationKey' => $this->key(), 'absenceTypeId' => $this->ids[$type], 'startDate' => $start, 'endDate' => $end ?? $start, 'mode' => null === $hours ? 'full_days' : 'hourly'];
        if (null !== $hours) {
            $body['hours'] = $hours;
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function create(string $start, ?string $end = null, ?int $hours = null): array
    {
        $this->send('/api/requests', $this->body($start, $end, $hours));

        return $this->success(201)['operation'];
    }

    /** @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function transition(array $receipt): array
    {
        return ['operationKey' => $this->key(), 'expectedVersion' => $receipt['version']];
    }

    /** @return array<string, mixed> */
    private function detail(int $id): array
    {
        $this->client->request('GET', '/api/requests/'.$id);

        return $this->success()['request'];
    }

    /** @return array<string, mixed> */
    private function balance(string $user): array
    {
        $this->client->request('GET', '/api/balances/users/'.$this->ids[$user].'/2026');

        return $this->success()['balance'];
    }

    private function login(string $user): void
    {
        if ($this->loggedIn === $user) {
            return;
        }
        $this->logout();
        $this->send('/api/auth/login', ['email' => $this->users[$user]['email'], 'password' => self::PASSWORD]);
        self::assertSame($this->ids[$user], $this->success()['user']['id']);
        $this->loggedIn = $user;
    }

    private function logout(): void
    {
        if (null === $this->loggedIn) {
            return;
        }
        $this->send('/api/auth/logout', []);
        $this->success();
        $this->loggedIn = null;
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

    private function sendRaw(string $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $csrf = $this->success()['csrfToken'];
        $this->client->request('POST', '/api/requests', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], $body);
    }

    /** @return array<string, mixed> */
    private function success(int $status = 200): array
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $this->content());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');

        return $this->data();
    }

    private function assertError(int $status, ?string $code = null): void
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $this->content());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');
        if (null !== $code) {
            self::assertSame($code, $this->data()['error']['code'] ?? null);
        }
    }

    /** @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertSameReceipt(array $expected, array $actual): void
    {
        // JSON objects have no field order; MySQL normalizes their storage order.
        // Retain strict comparison of every field value and scalar type.
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
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

    private function requestCount(): int
    {
        return (int) $this->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM leave_request');
    }

    private function ledgerCount(): int
    {
        return (int) $this->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ledger_entry');
    }

    private function key(): string
    {
        return $this->prefix.'_'.bin2hex(random_bytes(10));
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'));
    }
}
