<?php

namespace App\Tests\Functional;

use App\Entity\AbsenceType;
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
use App\Kernel;
use App\Model\RequestedDay;
use App\Security\SecurityIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Exercises the real JSON API and persisted history on the migrated MySQL test database. */
final class AdminTest extends WebTestCase
{
    private const PASSWORD = 'Synthetic administration passphrase 58!';
    private const NEW_PASSWORD = 'Synthetic new employee passphrase 96!';

    private KernelBrowser $client;
    private string $prefix;
    private string $ip;
    private string $hash;

    /** @var array<string, array{id: int, email: string}> */
    private array $users = [];

    /** @var array<string, int> */
    private array $teams = [];

    /** @var array<string, int> */
    private array $employment = [];

    /** @var list<KernelBrowser> */
    private array $otherBrowsers = [];

    protected function setUp(): void
    {
        $this->prefix = 'adm_'.bin2hex(random_bytes(6));
        $this->ip = sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));
        $this->client = self::createClient([], ['REMOTE_ADDR' => $this->ip, 'HTTP_HOST' => 'localhost']);
        $em = $this->manager();
        $database = $em->getConnection()->getDatabase();
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', $database, 'Refusing to mutate a non-test database.');
        self::assertStringContainsString('mysql', $em->getConnection()->getParams()['driver'] ?? '');
        $factory = $this->client->getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);
        $this->hash = $factory->getPasswordHasher(SecurityIdentity::class)->hash(self::PASSWORD);

        $alpha = new Team($this->prefix.'_a', 'Synthetic Alpha');
        $beta = new Team($this->prefix.'_b', 'Synthetic Beta');
        $uop = new EmploymentType($this->prefix.'_uop', 'Synthetic UoP', true);
        $b2b = new EmploymentType($this->prefix.'_b2b', 'Synthetic B2B', false);
        foreach ([$alpha, $beta, $uop, $b2b] as $entity) {
            $em->persist($entity);
        }
        $objects = [];
        foreach (['admin' => TeamRole::EMPLOYEE, 'employee' => TeamRole::EMPLOYEE, 'manager' => TeamRole::MANAGER, 'deputy' => TeamRole::DEPUTY, 'other' => TeamRole::EMPLOYEE] as $key => $role) {
            $user = new User('Synthetic', ucfirst($key), $this->prefix.'_'.$key.'@example.test', 'admin' === $key);
            $user->replacePasswordHash($this->hash, false, null);
            // Bootstrap administrators have no assignment until the administration screen assigns one.
            if ('admin' !== $key) {
                new StaffAssignment($user, 'other' === $key ? $beta : $alpha, $uop, $role, self::date('2020-01-01'));
            }
            $em->persist($user);
            $objects[$key] = $user;
        }
        $em->flush();
        foreach ($objects as $key => $user) {
            self::assertNotNull($user->getId());
            $this->users[$key] = ['id' => $user->getId(), 'email' => $user->getEmail()];
        }
        self::assertNotNull($alpha->getId());
        self::assertNotNull($beta->getId());
        self::assertNotNull($uop->getId());
        self::assertNotNull($b2b->getId());
        $this->teams = ['alpha' => $alpha->getId(), 'beta' => $beta->getId()];
        $this->employment = ['uop' => $uop->getId(), 'b2b' => $b2b->getId()];
    }

    protected function tearDown(): void
    {
        foreach ($this->otherBrowsers as $browser) {
            $browser->getKernel()->shutdown();
        }
        $this->otherBrowsers = [];
        parent::tearDown();
    }

    public function testAdminReadsAndWritesRequireAuthentication(): void
    {
        foreach (['/api/admin/users', '/api/admin/catalog', '/api/admin/history', '/api/admin/users/'.$this->users['employee']['id']] as $path) {
            $this->client->request('GET', $path);
            $this->assertError($this->client, 401, 'AUTH_REQUIRED');
        }
        $this->send($this->client, 'POST', '/api/admin/users', $this->newUserBody());
        $this->assertError($this->client, 401, 'AUTH_REQUIRED');
    }

    /** @return iterable<string, array{string}> */
    public static function nonAdministratorRoles(): iterable
    {
        yield 'employee' => ['employee'];
        yield 'manager' => ['manager'];
        yield 'deputy' => ['deputy'];
    }

    #[DataProvider('nonAdministratorRoles')]
    public function testEveryAdministrationEndpointRejectsNonAdministrators(string $role): void
    {
        $this->login($this->client, $role);
        $user = $this->users['employee']['id'];
        $team = $this->teams['alpha'];
        $employment = $this->employment['uop'];
        $routes = [
            ['GET', '/api/admin/catalog'],
            ['GET', '/api/admin/history'],
            ['GET', '/api/admin/users'],
            ['GET', '/api/admin/users/'.$user],
            ['POST', '/api/admin/catalog/initialize'],
            ['POST', '/api/admin/users'],
            ['PATCH', '/api/admin/users/'.$user],
            ['POST', '/api/admin/users/'.$user.'/assignments'],
            ['POST', '/api/admin/teams'],
            ['PATCH', '/api/admin/teams/'.$team],
            ['POST', '/api/admin/employment-types'],
            ['PATCH', '/api/admin/employment-types/'.$employment],
            ['POST', '/api/admin/absence-types'],
            ['PATCH', '/api/admin/absence-types/2147483647'],
        ];
        $before = $this->counts();
        foreach ($routes as [$method, $path]) {
            if ('GET' === $method) {
                $this->client->request($method, $path);
            } else {
                $this->send($this->client, $method, $path, []);
            }
            $this->assertError($this->client, 403, 'FORBIDDEN');
        }
        self::assertSame($before, $this->counts());
    }

    public function testHistoryProjectsKnownFieldsAndPaginatesWithoutExposingSecrets(): void
    {
        $this->login($this->client, 'admin');
        $em = $this->manager();
        $actor = $em->find(User::class, $this->users['admin']['id']);
        $subject = $em->find(User::class, $this->users['employee']['id']);
        self::assertInstanceOf(User::class, $actor);
        self::assertInstanceOf(User::class, $subject);
        $secret = 'NEVER_EXPOSE_'.$this->prefix;
        $events = [];
        foreach (['admin.user_updated', 'security.password_reset', 'admin.assignment_changed'] as $index => $action) {
            $event = new AuditEvent($this->prefix.'_history_'.$index, $action, $actor, $subject, new \DateTimeImmutable('now'),
                ['firstName' => 'Before', 'passwordHash' => $secret, 'credentialVersion' => 4],
                ['firstName' => 'After', 'mustChangePassword' => true, 'credentialVersion' => 5, 'temporaryPassword' => $secret, 'session' => ['token' => $secret], 'assignments' => [['team' => ['name' => 'Alpha', 'secret' => $secret], 'employmentType' => ['name' => 'UoP'], 'role' => 'employee', 'validFrom' => '2026-01-01', 'validUntil' => null, 'password' => $secret]]]);
            $em->persist($event);
            $events[] = $event;
        }
        $em->persist(new AuditEvent($this->prefix.'_unknown', 'admin.unknown_future_action', $actor, $subject, new \DateTimeImmutable('now'), [], ['secret' => $secret]));
        $em->persist(new AuditEvent($this->prefix.'_financial', 'balance.limit_set', $actor, $subject, new \DateTimeImmutable('now'), [], ['reason' => $secret]));
        $em->flush();
        $this->client->request('GET', '/api/admin/history?limit=2');
        $first = $this->success();
        self::assertCount(2, $first['events']);
        self::assertSame([$events[2]->getId(), $events[1]->getId()], array_column($first['events'], 'id'));
        self::assertSame($events[1]->getId(), $first['nextBeforeId']);
        self::assertSame('Synthetic Admin', $first['events'][0]['actorName']);
        self::assertSame(['id' => $subject->getId(), 'firstName' => 'Synthetic', 'lastName' => 'Employee'], $first['events'][0]['subject']);
        self::assertSame((string) $subject->getId(), $first['events'][0]['targetReference']);
        self::assertStringContainsString('Alpha', json_encode($first, JSON_THROW_ON_ERROR));
        foreach ([$secret, 'credentialVersion', 'temporaryPassword', 'passwordHash', 'operationKey', 'payloadHash'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->content($this->client));
        }
        self::assertSame([['field' => 'mustChangePassword', 'label' => 'Wymagana zmiana hasła', 'before' => null, 'after' => 'Tak']], $first['events'][1]['changes']);
        $this->client->request('GET', '/api/admin/history?limit=1&beforeId='.$first['nextBeforeId']);
        $second = $this->success();
        self::assertSame($events[0]->getId(), $second['events'][0]['id']);
        self::assertSame('Before', $second['events'][0]['changes'][0]['before']);
        self::assertSame('After', $second['events'][0]['changes'][0]['after']);
        foreach (['limit=0', 'limit=101', 'beforeId=-1', 'beforeId=abc', 'limit[]=1', 'unknown=1'] as $query) {
            $this->client->request('GET', '/api/admin/history?'.$query);
            $this->assertError($this->client, 422, 'VALIDATION_FAILED');
        }
    }

    public function testCreateAccountRequiresCsrfAndPasswordThenProducesUsableOneTimeCredentials(): void
    {
        $this->login($this->client, 'admin');
        $body = $this->newUserBody();
        $before = $this->counts();
        $this->send($this->client, 'POST', '/api/admin/users', $body, false);
        $this->assertError($this->client, 403, 'CSRF_INVALID');
        $this->send($this->client, 'POST', '/api/admin/users', array_replace($body, ['adminPassword' => 'Wrong synthetic password']));
        $this->assertError($this->client, 422, 'INVALID_CURRENT_PASSWORD');
        self::assertSame($before, $this->counts());

        $this->send($this->client, 'POST', '/api/admin/users', $body);
        $result = $this->success(201);
        self::assertIsArray($result['user']);
        self::assertTrue($result['user']['mustChangePassword']);
        self::assertSame($this->teams['alpha'], $result['user']['team']['id']);
        self::assertSame('employee', $result['user']['role']);
        self::assertCount(1, $result['user']['assignments']);
        self::assertIsString($result['temporaryPassword']);
        self::assertGreaterThanOrEqual(24, strlen($result['temporaryPassword']));
        $expires = new \DateTimeImmutable($result['expiresAt']);
        self::assertGreaterThan(time() + 23 * 3600, $expires->getTimestamp());
        self::assertLessThanOrEqual(time() + 24 * 3600 + 60, $expires->getTimestamp());
        $id = $result['user']['id'];
        $secret = $result['temporaryPassword'];

        $this->client->request('GET', '/api/admin/users/'.$id);
        $detail = $this->success();
        self::assertArrayNotHasKey('temporaryPassword', $detail);
        self::assertStringNotContainsString($secret, $this->content($this->client));
        $this->assertAuditWithoutSecrets($id, [self::PASSWORD, $secret]);

        $employee = $this->newBrowser();
        $this->send($employee, 'POST', '/api/auth/login', ['email' => $body['email'], 'password' => $secret]);
        self::assertSame(200, $employee->getResponse()->getStatusCode(), $this->content($employee));
        self::assertTrue($this->data($employee)['user']['mustChangePassword']);
        $employee->request('GET', '/api/users/'.$id.'/profile');
        $this->assertError($employee, 403, 'PASSWORD_CHANGE_REQUIRED');
        $this->send($employee, 'POST', '/api/auth/change-password', ['currentPassword' => $secret, 'newPassword' => self::NEW_PASSWORD]);
        self::assertSame(200, $employee->getResponse()->getStatusCode(), $this->content($employee));
        $this->send($employee, 'POST', '/api/auth/login', ['email' => $body['email'], 'password' => self::NEW_PASSWORD]);
        self::assertSame(200, $employee->getResponse()->getStatusCode(), $this->content($employee));
        self::assertFalse($this->data($employee)['user']['mustChangePassword']);
        $employee->request('GET', '/api/admin/users');
        $this->assertError($employee, 403, 'FORBIDDEN');
    }

    public function testInvalidAndDuplicateAccountWritesDoNotLeavePartialAssignmentsOrAudit(): void
    {
        $this->login($this->client, 'admin');
        $before = $this->counts();
        $this->send($this->client, 'POST', '/api/admin/users', array_replace($this->newUserBody(), ['email' => 'not-an-email']));
        $this->assertError($this->client, 422, 'VALIDATION_FAILED');
        self::assertSame($before, $this->counts());
        $this->send($this->client, 'POST', '/api/admin/users', array_replace($this->newUserBody(), ['email' => strtoupper($this->users['employee']['email'])]));
        $this->assertError($this->client, 409, 'DUPLICATE_VALUE');
        self::assertSame($before, $this->counts());

        $employee = $this->detail('employee');
        $this->send($this->client, 'PATCH', '/api/admin/users/'.$employee['id'], array_replace($this->editBody($employee), ['email' => $this->users['other']['email'], 'firstName' => 'Must not persist']));
        $this->assertError($this->client, 409, 'DUPLICATE_VALUE');
        self::assertSame($employee, $this->detail('employee'));
        self::assertSame($before, $this->counts());
    }

    public function testAccountPayloadRejectsUnknownSecurityFieldsAndIncorrectTypes(): void
    {
        $this->login($this->client, 'admin');
        $before = $this->counts();
        foreach ([
            ['passwordHash' => $this->hash],
            ['isAdministrator' => 'false'],
            ['role' => 'superuser'],
            ['validFrom' => '2026-02-30'],
        ] as $invalid) {
            $this->send($this->client, 'POST', '/api/admin/users', array_replace($this->newUserBody(), $invalid));
            $this->assertError($this->client, 422, 'VALIDATION_FAILED');
            self::assertSame($before, $this->counts());
        }
    }

    public function testEditingAndDeactivationRejectStaleFormsAndPermanentlyRevokeSessions(): void
    {
        $this->login($this->client, 'admin');
        $employeeBrowser = $this->newBrowser();
        $this->login($employeeBrowser, 'employee');
        $old = $this->detail('employee');
        $path = '/api/admin/users/'.$old['id'];
        $this->send($this->client, 'PATCH', $path, array_replace($this->editBody($old), ['firstName' => 'Changed name']));
        $changed = $this->success()['user'];
        self::assertSame('Changed name', $changed['firstName']);
        self::assertGreaterThan($old['version'], $changed['version']);

        $this->send($this->client, 'PATCH', $path, array_replace($this->editBody($old), ['lastName' => 'Stale edit']));
        $this->assertError($this->client, 409, 'CONFLICT');
        self::assertSame($changed, $this->detail('employee'));

        $this->send($this->client, 'PATCH', $path, array_replace($this->editBody($changed), ['active' => false]));
        $inactive = $this->success()['user'];
        self::assertFalse($inactive['active']);
        $this->send($this->client, 'PATCH', $path, array_replace($this->editBody($inactive), ['active' => true]));
        self::assertTrue($this->success()['user']['active']);
        // Reactivation before the old browser next connects must not revive its old session.
        $employeeBrowser->request('GET', '/api/auth/me');
        $this->assertError($employeeBrowser, 401, 'AUTH_REQUIRED');
        $this->login($employeeBrowser, 'employee');
        $this->assertAuditWithoutSecrets($old['id'], [self::PASSWORD, $this->hash]);
    }

    public function testBootstrapAdministratorCanReceiveFirstAssignmentWithoutAnotherAdministrator(): void
    {
        $this->login($this->client, 'admin');
        $admin = $this->detail('admin');
        self::assertSame([], $admin['assignments']);
        self::assertNull($admin['team']);
        $this->send($this->client, 'POST', '/api/admin/users/'.$admin['id'].'/assignments', $this->assignmentBody($admin['version'], 'alpha', 'uop', self::today(), 'manager'));
        $assigned = $this->success()['user'];
        self::assertCount(1, $assigned['assignments']);
        self::assertSame($this->teams['alpha'], $assigned['team']['id']);
        self::assertSame('manager', $assigned['role']);
        // A changed role/assignment invalidates the previous session, including self-edit.
        $this->client->request('GET', '/api/auth/me');
        $this->assertError($this->client, 401, 'AUTH_REQUIRED');
        $this->login($this->client, 'admin');
        self::assertTrue($this->detail('admin')['isAdministrator']);
    }

    public function testGrantingAndRevokingAdministratorInvalidatesTargetSessionsInBothDirections(): void
    {
        $this->login($this->client, 'admin');
        $target = $this->newBrowser();
        $this->login($target, 'employee');
        $employee = $this->detail('employee');
        $path = '/api/admin/users/'.$employee['id'];
        $this->send($this->client, 'PATCH', $path, array_replace($this->editBody($employee), ['isAdministrator' => true]));
        $promoted = $this->success()['user'];
        self::assertTrue($promoted['isAdministrator']);
        $target->request('GET', '/api/admin/catalog');
        $this->assertError($target, 401, 'AUTH_REQUIRED');
        $this->login($target, 'employee');
        $target->request('GET', '/api/admin/catalog');
        self::assertSame(200, $target->getResponse()->getStatusCode(), $this->content($target));

        $this->send($this->client, 'PATCH', $path, array_replace($this->editBody($promoted), ['isAdministrator' => false]));
        self::assertFalse($this->success()['user']['isAdministrator']);
        $target->request('GET', '/api/admin/catalog');
        $this->assertError($target, 401, 'AUTH_REQUIRED');
        $this->login($target, 'employee');
        $target->request('GET', '/api/admin/catalog');
        $this->assertError($target, 403, 'FORBIDDEN');
    }

    public function testTransferPreservesApprovedRequestAndEmploymentSnapshotsAndRejectsOverlappingBackfill(): void
    {
        $request = $this->seedApprovedRequest();
        $this->login($this->client, 'admin');
        $employee = $this->detail('employee');
        $transferDate = self::today()->modify('+1 day');
        $this->send($this->client, 'POST', '/api/admin/users/'.$employee['id'].'/assignments', $this->assignmentBody($employee['version'], 'beta', 'b2b', $transferDate));
        $changed = $this->success()['user'];
        self::assertCount(2, $changed['assignments']);
        self::assertSame($transferDate->format('Y-m-d'), $changed['assignments'][0]['validUntil']);
        self::assertSame($this->teams['alpha'], $changed['team']['id'], 'A future transfer must not change today\'s profile prematurely.');
        self::assertSame($this->teams['beta'], $changed['assignments'][1]['team']['id']);
        self::assertSame($this->employment['b2b'], $changed['assignments'][1]['employmentType']['id']);

        $after = $this->manager()->getConnection()->fetchAssociative('SELECT rd.assignment_id, rr.policy_id, rd.hours, sa.employment_type_id, d.outcome FROM request_day rd JOIN request_revision rr ON rr.id = rd.revision_id JOIN staff_assignment sa ON sa.id = rd.assignment_id JOIN request_decision d ON d.revision_id = rr.id WHERE rr.id = ?', [$request['revisionId']]);
        self::assertSame($request['snapshot'], $after, 'Transfer must not rewrite an approved request, its captured employment or its captured absence policy.');
        $this->send($this->client, 'POST', '/api/admin/users/'.$employee['id'].'/assignments', $this->assignmentBody($changed['version'], 'alpha', 'uop', $transferDate->modify('-1 day')));
        $this->assertError($this->client, 409, 'ASSIGNMENT_CONFLICT');
        self::assertSame($changed, $this->detail('employee'));
    }

    public function testDictionaryChangesAreVersionedAndInactiveValuesCannotReceiveNewAssignments(): void
    {
        $this->login($this->client, 'admin');
        $this->send($this->client, 'POST', '/api/admin/teams', ['code' => $this->prefix.'_new', 'name' => 'New team', 'active' => true]);
        $team = $this->success(201)['item'];
        $this->send($this->client, 'PATCH', '/api/admin/teams/'.$team['id'], ['version' => $team['version'], 'name' => 'Renamed team', 'active' => false]);
        $inactive = $this->success()['item'];
        self::assertFalse($inactive['active']);
        self::assertSame('Renamed team', $inactive['name']);
        $this->send($this->client, 'PATCH', '/api/admin/teams/'.$team['id'], ['version' => $team['version'], 'name' => 'Stale team', 'active' => true]);
        $this->assertError($this->client, 409, 'CONFLICT');
        $employee = $this->detail('employee');
        $body = $this->assignmentBody($employee['version'], 'alpha', 'b2b', self::today()->modify('+1 day'));
        $body['teamId'] = $team['id'];
        $this->send($this->client, 'POST', '/api/admin/users/'.$employee['id'].'/assignments', $body);
        $this->assertError($this->client, 422, 'INACTIVE_REFERENCE');
        self::assertSame($employee, $this->detail('employee'));

        $this->send($this->client, 'PATCH', '/api/admin/teams/'.$team['id'], ['version' => $inactive['version'], 'name' => $inactive['name'], 'active' => true]);
        self::assertTrue($this->success()['item']['active']);
        $this->send($this->client, 'POST', '/api/admin/employment-types', ['code' => $this->prefix.'_contract', 'name' => 'Synthetic contract', 'active' => true, 'carriesUnusedHours' => false]);
        $employment = $this->success(201)['item'];
        self::assertFalse($employment['carriesUnusedHours']);
        $this->send($this->client, 'PATCH', '/api/admin/employment-types/'.$employment['id'], ['version' => $employment['version'], 'name' => $employment['name'], 'active' => true, 'carriesUnusedHours' => true]);
        $this->assertError($this->client, 422, 'VALIDATION_FAILED');
        $this->send($this->client, 'PATCH', '/api/admin/employment-types/'.$employment['id'], ['version' => $employment['version'], 'name' => 'Closed synthetic contract', 'active' => false]);
        $employment = $this->success()['item'];
        self::assertFalse($employment['active']);
        $body['employmentTypeId'] = $employment['id'];
        $this->send($this->client, 'POST', '/api/admin/users/'.$employee['id'].'/assignments', $body);
        $this->assertError($this->client, 422, 'INACTIVE_REFERENCE');
    }

    public function testAbsenceTypeEditCreatesNewPolicyWithoutChangingExistingRequest(): void
    {
        $request = $this->seedApprovedRequest();
        $this->login($this->client, 'admin');
        $this->client->request('GET', '/api/admin/catalog');
        $catalog = $this->success();
        $types = array_values(array_filter($catalog['absenceTypes'], static fn (array $item): bool => $item['id'] === $request['typeId']));
        self::assertCount(1, $types);
        $type = $types[0];
        $this->send($this->client, 'PATCH', '/api/admin/absence-types/'.$type['id'], [
            'version' => $type['version'], 'active' => true, 'name' => 'New non-deducting policy',
            'deductsBalance' => false, 'employmentTypeIds' => [$this->employment['b2b']],
            'namesByEmploymentType' => (object) [(string) $this->employment['b2b'] => 'New contract label'],
        ]);
        $new = $this->success()['item'];
        self::assertSame($type['revision'] + 1, $new['revision']);
        self::assertFalse($new['deductsBalance']);
        self::assertSame([$this->employment['b2b']], $new['employmentTypeIds']);
        $stored = $this->manager()->getConnection()->fetchAssociative('SELECT p.name, p.deducts_balance, r.policy_id, d.outcome FROM request_revision r JOIN absence_type_version p ON p.id = r.policy_id JOIN request_decision d ON d.revision_id = r.id WHERE r.id = ?', [$request['revisionId']]);
        self::assertIsArray($stored);
        self::assertSame('Original paid policy', $stored['name']);
        self::assertSame(1, (int) $stored['deducts_balance']);
        self::assertSame((int) $request['snapshot']['policy_id'], (int) $stored['policy_id']);
        self::assertSame('approved', $stored['outcome']);
        $this->send($this->client, 'PATCH', '/api/admin/absence-types/'.$type['id'], [
            'version' => $type['version'], 'active' => false, 'name' => 'Stale policy',
            'deductsBalance' => true, 'employmentTypeIds' => [$this->employment['uop']], 'namesByEmploymentType' => new \stdClass(),
        ]);
        $this->assertError($this->client, 409, 'CONFLICT');
    }

    public function testCatalogInitializationIsIdempotentAndPreservesAdministratorEdits(): void
    {
        $this->login($this->client, 'admin');
        $this->send($this->client, 'POST', '/api/admin/catalog/initialize', []);
        $catalog = $this->success();
        foreach (['uop', 'b2b'] as $code) {
            self::assertCount(1, array_filter($catalog['employmentTypes'], static fn (array $item): bool => $item['code'] === $code));
        }
        foreach (['paid', 'unpaid', 'other'] as $code) {
            self::assertCount(1, array_filter($catalog['absenceTypes'], static fn (array $item): bool => $item['code'] === $code));
        }
        $paid = array_values(array_filter($catalog['absenceTypes'], static fn (array $item): bool => 'paid' === $item['code']))[0];
        $this->send($this->client, 'PATCH', '/api/admin/absence-types/'.$paid['id'], [
            'version' => $paid['version'], 'active' => $paid['active'], 'name' => 'Administrator-customized paid leave',
            'deductsBalance' => $paid['deductsBalance'], 'employmentTypeIds' => $paid['employmentTypeIds'],
            'namesByEmploymentType' => (object) $paid['namesByEmploymentType'],
        ]);
        $this->success();
        $this->client->request('GET', '/api/admin/catalog');
        $customized = $this->success();
        $before = $this->counts();
        $this->send($this->client, 'POST', '/api/admin/catalog/initialize', []);
        self::assertSame($customized, $this->success());
        self::assertSame($before, $this->counts(), 'Repeated initialization must not add duplicates or phantom audit changes.');
    }

    public function testLastUsableAdministratorCannotBeDisabledOrDemotedEvenWithTemporaryAdminPresent(): void
    {
        $em = $this->manager();
        $connection = $em->getConnection();
        $adminId = $this->users['admin']['id'];
        $others = $connection->fetchAllAssociative('SELECT id, is_administrator FROM app_user WHERE is_administrator = 1 AND id <> ?', [$adminId]);
        // Tests deliberately isolate the invariant, then restore every unrelated synthetic account.
        $connection->executeStatement('UPDATE app_user SET is_administrator = 0 WHERE id <> ?', [$adminId]);
        try {
            $this->login($this->client, 'admin');
            $body = array_replace($this->newUserBody(), ['isAdministrator' => true]);
            $this->send($this->client, 'POST', '/api/admin/users', $body);
            $temporary = $this->success(201)['user'];
            self::assertTrue($temporary['mustChangePassword']);
            $admin = $this->detail('admin');
            foreach ([['active' => false], ['isAdministrator' => false]] as $change) {
                $this->send($this->client, 'PATCH', '/api/admin/users/'.$adminId, array_replace($this->editBody($admin), $change));
                $this->assertError($this->client, 409, 'LAST_ADMINISTRATOR');
                self::assertSame($admin, $this->detail('admin'));
            }
        } finally {
            // The HTTP kernel may have reset its EntityManager after a failed transaction.
            $connection = $this->manager()->getConnection();
            foreach ($others as $row) {
                $connection->update('app_user', ['is_administrator' => $row['is_administrator']], ['id' => $row['id']]);
            }
        }
    }

    /** @return array{revisionId: int, typeId: int, snapshot: array<string, mixed>} */
    private function seedApprovedRequest(): array
    {
        $em = $this->manager();
        $employee = $em->find(User::class, $this->users['employee']['id']);
        $admin = $em->find(User::class, $this->users['admin']['id']);
        $uop = $em->find(EmploymentType::class, $this->employment['uop']);
        $b2b = $em->find(EmploymentType::class, $this->employment['b2b']);
        self::assertInstanceOf(User::class, $employee);
        self::assertInstanceOf(User::class, $admin);
        self::assertInstanceOf(EmploymentType::class, $uop);
        self::assertInstanceOf(EmploymentType::class, $b2b);
        $type = new AbsenceType($this->prefix.'_paid');
        $policy = $type->createVersion('Original paid policy', true, [$uop, $b2b]);
        $time = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));
        $submitted = new AuditEvent($this->prefix.'.submitted', 'request.submitted', $employee, $employee, $time);
        $approved = new AuditEvent($this->prefix.'.approved', 'request.approved', $admin, $employee, $time->modify('+1 minute'));
        $request = new LeaveRequest($employee, $time, bin2hex(random_bytes(16)));
        $day = self::today()->modify('+10 days');
        $revision = new RequestRevision($request, RequestChangeKind::INITIAL, $policy, RequestMode::FULL_DAYS, $day, $day, [new RequestedDay($employee->getAssignments()[0], $day, 8)], $submitted);
        new RequestDecision($revision, DecisionOutcome::APPROVED, $approved);
        foreach ([$type, $policy, $submitted, $approved, $request] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        self::assertNotNull($revision->getId());
        self::assertNotNull($type->getId());
        $snapshot = $em->getConnection()->fetchAssociative('SELECT rd.assignment_id, rr.policy_id, rd.hours, sa.employment_type_id, d.outcome FROM request_day rd JOIN request_revision rr ON rr.id = rd.revision_id JOIN staff_assignment sa ON sa.id = rd.assignment_id JOIN request_decision d ON d.revision_id = rr.id WHERE rr.id = ?', [$revision->getId()]);
        self::assertIsArray($snapshot);

        return ['revisionId' => $revision->getId(), 'typeId' => $type->getId(), 'snapshot' => $snapshot];
    }

    /** @return array<string, mixed> */
    private function newUserBody(): array
    {
        return ['firstName' => 'New', 'lastName' => 'Employee', 'email' => $this->prefix.'_new@example.test', 'isAdministrator' => false, 'teamId' => $this->teams['alpha'], 'employmentTypeId' => $this->employment['uop'], 'role' => 'employee', 'validFrom' => self::today()->format('Y-m-d'), 'adminPassword' => self::PASSWORD];
    }

    /** @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function editBody(array $user): array
    {
        return ['version' => $user['version'], 'firstName' => $user['firstName'], 'lastName' => $user['lastName'], 'email' => $user['email'], 'active' => $user['active'], 'isAdministrator' => $user['isAdministrator'], 'adminPassword' => self::PASSWORD];
    }

    /** @return array<string, mixed> */
    private function assignmentBody(int $version, string $team, string $employment, \DateTimeImmutable $date, string $role = 'employee'): array
    {
        return ['version' => $version, 'teamId' => $this->teams[$team], 'employmentTypeId' => $this->employment[$employment], 'role' => $role, 'validFrom' => $date->format('Y-m-d'), 'adminPassword' => self::PASSWORD];
    }

    /** @return array<string, mixed> */
    private function detail(string $key): array
    {
        $this->client->request('GET', '/api/admin/users/'.$this->users[$key]['id']);
        $data = $this->success();
        self::assertIsArray($data['user']);
        self::assertStringNotContainsString(self::PASSWORD, $this->content($this->client));
        self::assertStringNotContainsString($this->hash, $this->content($this->client));

        return $data['user'];
    }

    private function manager(): EntityManagerInterface
    {
        $registry = $this->client->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $em = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function newBrowser(): KernelBrowser
    {
        $browser = new KernelBrowser(new Kernel('test', true), ['REMOTE_ADDR' => $this->ip, 'HTTP_HOST' => 'localhost']);
        $this->otherBrowsers[] = $browser;

        return $browser;
    }

    private function login(KernelBrowser $browser, string $key): void
    {
        $this->send($browser, 'POST', '/api/auth/login', ['email' => $this->users[$key]['email'], 'password' => self::PASSWORD]);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), $this->content($browser));
        self::assertSame($this->users[$key]['id'], $this->data($browser)['user']['id']);
    }

    /** @param array<string, mixed> $body */
    private function send(KernelBrowser $browser, string $method, string $path, array $body, bool $csrf = true): void
    {
        $headers = [];
        if ($csrf) {
            $browser->request('GET', '/api/auth/csrf');
            self::assertSame(200, $browser->getResponse()->getStatusCode(), $this->content($browser));
            $headers['HTTP_X_CSRF_TOKEN'] = $this->data($browser)['csrfToken'];
        }
        $browser->request($method, $path, [], [], $headers + ['CONTENT_TYPE' => 'application/json'], json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function success(int $status = 200): array
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $this->content($this->client));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');

        return $this->data($this->client);
    }

    private function assertError(KernelBrowser $browser, int $status, string $code): void
    {
        self::assertSame($status, $browser->getResponse()->getStatusCode(), $this->content($browser));
        self::assertSame($code, $this->data($browser)['error']['code'] ?? null);
    }

    /** @return array<string, mixed> */
    private function data(KernelBrowser $browser): array
    {
        $data = json_decode($this->content($browser), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function content(KernelBrowser $browser): string
    {
        $content = $browser->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $connection = $this->manager()->getConnection();
        $counts = [];
        foreach (['app_user', 'staff_assignment', 'audit_event', 'team', 'employment_type', 'absence_type', 'absence_type_version'] as $table) {
            $counts[$table] = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $counts;
    }

    /** @param list<string> $secrets */
    private function assertAuditWithoutSecrets(int $userId, array $secrets): void
    {
        $rows = $this->manager()->getConnection()->fetchAllAssociative('SELECT before_data, after_data FROM audit_event WHERE subject_id = ?', [$userId]);
        self::assertNotEmpty($rows);
        $json = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Warsaw'));
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'));
    }
}
