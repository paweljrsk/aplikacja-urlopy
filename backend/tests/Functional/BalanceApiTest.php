<?php

namespace App\Tests\Functional;

use App\Entity\EmploymentType;
use App\Entity\AuditEvent;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Security\SecurityIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Real session, CSRF, controller, permissions and ledger writes on isolated test data. */
final class BalanceApiTest extends WebTestCase
{
    private const PASSWORD = 'Synthetic balance administration phrase 87!';

    private KernelBrowser $client;
    private string $prefix;
    private int $year;
    /** @var array<string, array{id: int, email: string}> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->prefix = 'bal_'.bin2hex(random_bytes(6));
        $this->year = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y');
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
        foreach (['admin' => TeamRole::EMPLOYEE, 'employee' => TeamRole::EMPLOYEE, 'manager' => TeamRole::MANAGER, 'deputy' => TeamRole::DEPUTY, 'other' => TeamRole::EMPLOYEE] as $key => $role) {
            $user = new User('Synthetic', ucfirst($key), $this->prefix.'_'.$key.'@example.test', 'admin' === $key);
            $user->replacePasswordHash($hash, false, null);
            if ('admin' !== $key) {
                new StaffAssignment($user, 'other' === $key ? $beta : $alpha, $employment, $role, new \DateTimeImmutable('2025-01-01', new \DateTimeZone('Europe/Warsaw')));
            }
            $em->persist($user);
            $objects[$key] = $user;
        }
        $em->flush();
        foreach ($objects as $key => $user) {
            self::assertNotNull($user->getId());
            $this->users[$key] = ['id' => $user->getId(), 'email' => $user->getEmail()];
        }
    }

    public function testAnonymousCannotReadBalancesPeopleOrCalendar(): void
    {
        foreach (['/api/balances/people', $this->path('employee'), '/api/calendar/'.$this->year] as $path) {
            $this->client->request('GET', $path);
            $this->assertError(401, 'AUTH_REQUIRED');
        }
    }

    public function testEmployeeOnlySeesOwnBalanceAndCannotChangeIt(): void
    {
        $this->login('employee');
        $this->client->request('GET', '/api/balances/people');
        $people = $this->success()['people'];
        self::assertCount(1, $people);
        self::assertSame($this->users['employee']['id'], $people[0]['id']);
        self::assertFalse($people[0]['canManage']);
        self::assertArrayNotHasKey('email', $people[0]);
        $own = $this->balance('employee');
        self::assertFalse($own['configured']);
        self::assertNull($own['annualHours']);
        $this->client->request('GET', $this->path('manager'));
        $this->assertError(403, 'FORBIDDEN');
        $this->send('POST', $this->path('employee').'/limit', ['annualHours' => 80]);
        $this->assertError(403, 'FORBIDDEN');
        $this->client->request('GET', '/api/calendar/'.$this->year);
        self::assertSame('Europe/Warsaw', $this->success()['timezone']);
    }

    public function testManagerSeesOwnTeamAndManagesEmployeeButNotSelfOrOtherTeam(): void
    {
        $this->login('manager');
        $this->client->request('GET', '/api/balances/people');
        $people = $this->success()['people'];
        self::assertCount(3, $people);
        $own = array_values(array_filter($people, fn (array $person): bool => $person['id'] === $this->users['manager']['id']));
        self::assertFalse($own[0]['canManage']);
        $opening = $this->createOpening('employee', 208, 40);
        self::assertTrue($opening['configured']);
        self::assertSame(208, $opening['annualHours']);
        self::assertSame(168, $opening['availableHours']);
        foreach (['manager', 'other'] as $key) {
            $this->send('POST', $this->path($key).'/limit', ['annualHours' => 400]);
            $this->assertError(403, 'FORBIDDEN');
            $this->send('POST', '/api/balances/users/'.$this->users[$key]['id'].'/opening/corrections', ['currentYearUsedHours' => 0]);
            $this->assertError(403, 'FORBIDDEN');
        }
        $this->client->request('GET', $this->path('other'));
        $this->assertError(403, 'FORBIDDEN');
    }

    public function testDeputyCanReadTeamButCannotPerformAnyBalanceWritesOrCalendarPreview(): void
    {
        $this->login('deputy');
        $this->balance('employee');
        $user = $this->users['employee']['id'];
        foreach (['/api/balances/users/'.$user.'/opening', '/api/balances/users/'.$user.'/opening/corrections', $this->path('employee').'/limit', $this->path('employee').'/settle', '/api/calendar/preview', '/api/calendar/apply'] as $path) {
            $this->send('POST', $path, []);
            $this->assertError(403, 'FORBIDDEN');
        }
    }

    public function testCsrfIsRequiredAndZeroAllowanceIsDifferentFromMissing(): void
    {
        $this->login('admin');
        $before = $this->balance('employee');
        $body = $this->openingBody($before, 0, 0);
        $path = '/api/balances/users/'.$this->users['employee']['id'].'/opening';
        $this->send('POST', $path, $body, false);
        $this->assertError(403, 'CSRF_INVALID');
        self::assertSame($before['version'], $this->balance('employee')['version']);
        $this->send('POST', $path, $body);
        $after = $this->success()['balance'];
        self::assertTrue($after['configured']);
        self::assertSame(0, $after['annualHours']);
        self::assertSame(0, $after['availableHours']);
    }

    public function testCorrectionsExposeDeficitAndPreserveOpeningHistoryWithoutDuplicateWrites(): void
    {
        $this->login('admin');
        $initial = $this->createOpening('employee', 80, 72);
        $body = ['operationKey' => $this->key(), 'expectedVersion' => $initial['version'], 'annualHours' => 64, 'reason' => 'Correct synthetic annual allowance'];
        $this->send('POST', $this->path('employee').'/limit', $body);
        $corrected = $this->success()['balance'];
        self::assertSame(-8, $corrected['availableHours']);
        self::assertSame(8, $corrected['deficitHours']);
        $count = $this->ledgerCount();
        $this->send('POST', $this->path('employee').'/limit', $body);
        $repeated = $this->success()['balance'];
        self::assertSame(-8, $repeated['availableHours']);
        self::assertSame($count, $this->ledgerCount());

        $this->send('POST', $this->path('employee').'/limit', array_replace($body, ['annualHours' => 80]));
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), $this->content());
        $this->send('POST', $this->path('employee').'/limit', array_replace($body, ['operationKey' => $this->key(), 'annualHours' => 80]));
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), $this->content());

        $this->send('POST', '/api/balances/users/'.$this->users['employee']['id'].'/opening/corrections', [
            'operationKey' => $this->key(), 'expectedVersion' => $corrected['version'], 'currentYearUsedHours' => 48,
            'carries' => [], 'reason' => 'Correct synthetic usage before opening',
        ]);
        $fixed = $this->success()['balance'];
        self::assertSame(16, $fixed['availableHours']);
        self::assertNotEmpty($fixed['history']);
        $stored = $this->manager()->getConnection()->fetchAssociative('SELECT annual_hours, current_year_used_hours FROM opening_balance WHERE user_id = ?', [$this->users['employee']['id']]);
        self::assertIsArray($stored);
        self::assertSame(80, (int) $stored['annual_hours']);
        self::assertSame(72, (int) $stored['current_year_used_hours']);
    }

    public function testFractionalAndStringHoursAreRejectedAndDoNotCreateOpening(): void
    {
        $this->login('admin');
        $before = $this->balance('employee');
        foreach ([1.5, '8', -1, true] as $invalid) {
            $body = $this->openingBody($before, 80, 0);
            $body['annualHours'] = $invalid;
            $this->send('POST', '/api/balances/users/'.$this->users['employee']['id'].'/opening', $body);
            self::assertSame(422, $this->client->getResponse()->getStatusCode(), $this->content());
            self::assertSame($before['version'], $this->balance('employee')['version']);
        }
    }

    public function testSupervisorResponsesDoNotExposeRequestAuditDetailsThroughBalanceHistory(): void
    {
        $this->login('manager');
        $this->createOpening('employee', 80, 0);
        $em = $this->manager();
        $subject = $em->find(User::class, $this->users['employee']['id']);
        $actor = $em->find(User::class, $this->users['admin']['id']);
        self::assertInstanceOf(User::class, $subject);
        self::assertInstanceOf(User::class, $actor);
        $marker = 'SYNTHETIC_OLD_TEAM_REQUEST_DETAIL';
        $em->persist(new AuditEvent($this->key(), 'calendar.request_corrected', $actor, $subject, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), ['privateRequestDetail' => $marker], ['reason' => $marker], 'request', 'synthetic-old-team-reference'));
        $em->flush();
        $balance = $this->balance('employee');
        self::assertStringNotContainsString($marker, $this->content());
        self::assertNotEmpty($balance['history'], 'Financial administration events remain visible.');
        $this->send('POST', $this->path('employee').'/limit', [
            'operationKey' => $this->key(), 'expectedVersion' => $balance['version'], 'annualHours' => 96, 'reason' => 'Synthetic correction',
        ]);
        self::assertSame(96, $this->success()['balance']['annualHours']);
        self::assertStringNotContainsString($marker, $this->content(), 'Write responses must not bypass read projection.');
        $this->send('POST', '/api/auth/logout', []);
        $this->success();
        $this->login('employee');
        $this->balance('employee');
        self::assertStringContainsString($marker, $this->content(), 'Owner retains full access to their request history.');
    }

    /** @return array<string, mixed> */
    private function createOpening(string $key, int $annual, int $used): array
    {
        $balance = $this->balance($key);
        $this->send('POST', '/api/balances/users/'.$this->users[$key]['id'].'/opening', $this->openingBody($balance, $annual, $used));

        return $this->success()['balance'];
    }

    /** @param array<string, mixed> $balance
     * @return array<string, mixed>
     */
    private function openingBody(array $balance, int $annual, int $used): array
    {
        return ['operationKey' => $this->key(), 'expectedVersion' => $balance['version'], 'effectiveDate' => $this->year.'-01-01', 'annualHours' => $annual, 'currentYearUsedHours' => $used, 'carries' => []];
    }

    /** @return array<string, mixed> */
    private function balance(string $key): array
    {
        $this->client->request('GET', $this->path($key));

        return $this->success()['balance'];
    }

    private function path(string $key): string
    {
        return '/api/balances/users/'.$this->users[$key]['id'].'/'.$this->year;
    }

    private function key(): string
    {
        return $this->prefix.'_'.bin2hex(random_bytes(10));
    }

    private function ledgerCount(): int
    {
        return (int) $this->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ledger_entry');
    }

    private function login(string $key): void
    {
        $this->send('POST', '/api/auth/login', ['email' => $this->users[$key]['email'], 'password' => self::PASSWORD]);
        self::assertSame($this->users[$key]['id'], $this->success()['user']['id']);
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $path, array $body, bool $csrf = true): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf) {
            $this->client->request('GET', '/api/auth/csrf');
            $headers['HTTP_X_CSRF_TOKEN'] = $this->success()['csrfToken'];
        }
        $this->client->request($method, $path, [], [], $headers, json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function success(): array
    {
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->content());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');

        return $this->data();
    }

    private function assertError(int $status, string $code): void
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $this->content());
        self::assertSame($code, $this->data()['error']['code'] ?? null);
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
}
