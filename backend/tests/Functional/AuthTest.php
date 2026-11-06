<?php

namespace App\Tests\Functional;

use App\Entity\EmploymentType;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Kernel;
use App\Security\SecurityIdentity;
use App\Security\SecuritySession;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Real HTTP authentication against migrated MySQL, without loginUser() shortcuts.
 * Synthetic setup is committed so separate browser kernels see the same records.
 */
final class AuthTest extends WebTestCase
{
    private const PASSWORD = 'Synthetic passphrase 47! for tests';
    private const NEW_PASSWORD = 'Another synthetic passphrase 93!';

    private KernelBrowser $client;
    private string $prefix;
    private string $ip;
    private string $passwordHash;

    /** @var array<string, array{id: int, email: string}> */
    private array $users = [];

    /** @var array<string, int> */
    private array $teamIds = [];

    /** @var list<KernelBrowser> */
    private array $extraBrowsers = [];

    protected function setUp(): void
    {
        $this->prefix = 'auth_'.bin2hex(random_bytes(6));
        $this->ip = sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));
        $this->client = self::createClient([], ['REMOTE_ADDR' => $this->ip, 'HTTP_HOST' => 'localhost']);
        $em = $this->manager();
        $database = $em->getConnection()->getDatabase();
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', $database, 'Refusing to write outside the dedicated test database.');
        self::assertStringContainsString('mysql', $em->getConnection()->getParams()['driver'] ?? '');
        $this->seedUsers();
    }

    protected function tearDown(): void
    {
        foreach ($this->extraBrowsers as $browser) {
            $browser->getKernel()->shutdown();
        }
        $this->extraBrowsers = [];
        parent::tearDown();
    }

    public function testProtectedApiRequiresASessionBeforeReturningAnyProfile(): void
    {
        foreach (['/api/auth/me', '/api/users/'.$this->users['employee']['id'].'/profile', '/api/admin/users'] as $path) {
            $this->client->request('GET', $path);
            $this->assertError($this->client, 401, 'AUTH_REQUIRED');
        }
    }

    public function testRealLoginRotatesSessionAndReturnsOnlyPublicIdentityFields(): void
    {
        $this->csrf($this->client);
        $session = $this->client->getSession();
        self::assertNotNull($session);
        $sessionName = $session->getName();
        $before = $session->getId();
        $session->save();
        $this->post($this->client, '/api/auth/login', ['email' => '  '.strtoupper($this->users['employee']['email']).'  ', 'password' => self::PASSWORD]);
        $this->assertUserResponse($this->client, $this->users['employee']['id']);
        $cookie = $this->client->getCookieJar()->get($sessionName);
        self::assertNotNull($cookie);
        self::assertNotSame($before, $cookie->getValue(), 'Authentication must replace the anonymous session ID.');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower($cookie->getSameSite() ?? ''));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');

        $this->client->request('GET', '/api/auth/me');
        $user = $this->assertUserResponse($this->client, $this->users['employee']['id']);
        self::assertFalse($user['mustChangePassword']);
        self::assertSame('employee', $user['role']);
        self::assertSame($this->users['employee']['email'], $user['email']);
        $session = $this->client->getSession();
        self::assertNotNull($session);
        $serialized = serialize($session->all());
        $session->save();
        self::assertStringNotContainsString(self::PASSWORD, $serialized);
        self::assertStringNotContainsString($this->passwordHash, $serialized, 'A session must not retain a reusable password hash.');
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedLoginCases(): iterable
    {
        yield 'wrong password' => ['wrong'];
        yield 'unknown account' => ['unknown'];
        yield 'inactive account' => ['inactive'];
        yield 'expired temporary password' => ['expired'];
    }

    #[DataProvider('rejectedLoginCases')]
    public function testRejectedCredentialsHaveOneGenericResponse(string $case): void
    {
        $email = $this->users['employee']['email'];
        $password = self::PASSWORD;
        if ('wrong' === $case) {
            $password = 'Incorrect synthetic passphrase';
        } elseif ('unknown' === $case) {
            $email = $this->prefix.'_missing@example.test';
        } elseif ('inactive' === $case) {
            $this->updateUser('employee', ['active' => 0]);
        } else {
            $this->setTemporary('employee', new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC')));
        }
        $this->post($this->client, '/api/auth/login', ['email' => $email, 'password' => $password]);
        $this->assertError($this->client, 401, 'INVALID_CREDENTIALS');
        self::assertSame('Nieprawidłowy e-mail lub hasło.', $this->data($this->client)['error']['message']);
        $this->client->request('GET', '/api/auth/me');
        $this->assertError($this->client, 401, 'AUTH_REQUIRED');
    }

    public function testLoginRejectsMissingInvalidAndAnotherBrowsersCsrfToken(): void
    {
        $body = ['email' => $this->users['employee']['email'], 'password' => self::PASSWORD];
        $this->post($this->client, '/api/auth/login', $body, false);
        $this->assertError($this->client, 403, 'CSRF_INVALID');
        $this->client->jsonRequest('POST', '/api/auth/login', $body, ['HTTP_X_CSRF_TOKEN' => 'incorrect-token']);
        $this->assertError($this->client, 403, 'CSRF_INVALID');
        $other = $this->newBrowser();
        $foreign = $this->csrf($other);
        $this->client->jsonRequest('POST', '/api/auth/login', $body, ['HTTP_X_CSRF_TOKEN' => $foreign]);
        $this->assertError($this->client, 403, 'CSRF_INVALID');
        $this->login($this->client, 'employee');
    }

    public function testMalformedLoginJsonIsRejectedWithoutDebugDetails(): void
    {
        $csrf = $this->csrf($this->client);
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], '{"password":"synthetic-unclosed-secret');
        $this->assertError($this->client, 400, 'INVALID_REQUEST');
        self::assertStringNotContainsString('synthetic-unclosed-secret', $this->content($this->client));
        self::assertStringNotContainsString('trace', $this->content($this->client));
    }

    public function testTemporaryPasswordCannotAccessBusinessApiUntilChanged(): void
    {
        $this->setTemporary('admin', new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')));
        $this->login($this->client, 'admin');
        self::assertTrue($this->data($this->client)['user']['mustChangePassword']);
        foreach (['/api/admin/users', '/api/users/'.$this->users['admin']['id'].'/profile'] as $path) {
            $this->client->request('GET', $path);
            $this->assertError($this->client, 403, 'PASSWORD_CHANGE_REQUIRED');
        }
        $this->client->request('GET', '/api/auth/me');
        $this->assertUserResponse($this->client, $this->users['admin']['id']);
        $this->post($this->client, '/api/admin/users/'.$this->users['employee']['id'].'/reset-password', ['adminPassword' => self::PASSWORD]);
        $this->assertError($this->client, 403, 'PASSWORD_CHANGE_REQUIRED');

        $this->post($this->client, '/api/auth/change-password', ['currentPassword' => self::PASSWORD, 'newPassword' => self::NEW_PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->content($this->client));
        self::assertSame('password_changed', $this->data($this->client)['status']);
        $this->client->request('GET', '/api/auth/me');
        $this->assertError($this->client, 401, 'AUTH_REQUIRED');
        $this->login($this->client, 'admin', self::NEW_PASSWORD);
        self::assertFalse($this->data($this->client)['user']['mustChangePassword']);
        $this->client->request('GET', '/api/admin/users');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->content($this->client));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidPasswordChanges(): iterable
    {
        yield 'incorrect current password' => ['wrong-current-password', self::NEW_PASSWORD, 'INVALID_CURRENT_PASSWORD'];
        yield 'too short' => [self::PASSWORD, 'Short password', 'VALIDATION_FAILED'];
        yield 'too long' => [self::PASSWORD, str_repeat('x', 129), 'VALIDATION_FAILED'];
        yield 'unchanged' => [self::PASSWORD, self::PASSWORD, 'VALIDATION_FAILED'];
    }

    #[DataProvider('invalidPasswordChanges')]
    public function testInvalidPasswordChangeDoesNotChangeCredentials(string $current, string $new, string $code): void
    {
        $this->login($this->client, 'employee');
        $before = $this->credentialRow('employee');
        $this->post($this->client, '/api/auth/change-password', ['currentPassword' => $current, 'newPassword' => $new]);
        $this->assertError($this->client, 422, $code);
        self::assertSame($before, $this->credentialRow('employee'));
        $this->client->request('GET', '/api/auth/me');
        $this->assertUserResponse($this->client, $this->users['employee']['id']);
    }

    public function testPasswordChangeRequiresCsrfAndRevokesEveryExistingSession(): void
    {
        $this->login($this->client, 'employee');
        $other = $this->newBrowser();
        $this->login($other, 'employee');
        $before = $this->credentialRow('employee');
        $body = ['currentPassword' => self::PASSWORD, 'newPassword' => self::NEW_PASSWORD];
        $this->post($this->client, '/api/auth/change-password', $body, false);
        $this->assertError($this->client, 403, 'CSRF_INVALID');
        self::assertSame($before, $this->credentialRow('employee'));
        $this->post($this->client, '/api/auth/change-password', $body);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->content($this->client));
        $after = $this->credentialRow('employee');
        self::assertNotSame($before['password_hash'], $after['password_hash']);
        self::assertSame((int) $before['credential_version'] + 1, (int) $after['credential_version']);
        foreach ([$this->client, $other] as $browser) {
            $browser->request('GET', '/api/auth/me');
            $this->assertError($browser, 401, 'AUTH_REQUIRED');
        }
        $this->post($this->client, '/api/auth/login', ['email' => $this->users['employee']['email'], 'password' => self::PASSWORD]);
        $this->assertError($this->client, 401, 'INVALID_CREDENTIALS');
        $this->login($this->client, 'employee', self::NEW_PASSWORD);
        $this->assertAuditContainsNoCredentials('employee', [self::PASSWORD, self::NEW_PASSWORD, $before['password_hash'], $after['password_hash']]);
    }

    public function testLogoutRequiresCsrfAndInvalidatesTheSession(): void
    {
        $this->login($this->client, 'employee');
        $this->post($this->client, '/api/auth/logout', [], false);
        $this->assertError($this->client, 403, 'CSRF_INVALID');
        $this->client->request('GET', '/api/auth/me');
        $this->assertUserResponse($this->client, $this->users['employee']['id']);
        $this->post($this->client, '/api/auth/logout', []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->content($this->client));
        self::assertSame('logged_out', $this->data($this->client)['status']);
        $this->client->request('GET', '/api/auth/me');
        $this->assertError($this->client, 401, 'AUTH_REQUIRED');
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function profileAccessCases(): iterable
    {
        yield 'employee own profile' => ['employee', 'employee', 200];
        yield 'manager own profile' => ['manager', 'manager', 200];
        yield 'deputy own profile' => ['deputy', 'deputy', 200];
        yield 'admin own profile' => ['admin', 'admin', 200];
        yield 'employee cannot inspect peer' => ['employee', 'peer', 403];
        yield 'employee cannot inspect other team' => ['employee', 'other', 403];
        yield 'manager can inspect own team' => ['manager', 'employee', 200];
        yield 'deputy can inspect own team' => ['deputy', 'employee', 200];
        yield 'manager cannot inspect other team' => ['manager', 'other', 403];
        yield 'deputy cannot inspect other team' => ['deputy', 'other', 403];
        yield 'administrator can inspect other team' => ['admin', 'other', 200];
    }

    #[DataProvider('profileAccessCases')]
    public function testProfileEndpointChecksObjectOwnershipAndCurrentTeam(string $actor, string $subject, int $status): void
    {
        $this->login($this->client, $actor);
        $this->client->request('GET', '/api/users/'.$this->users[$subject]['id'].'/profile');
        if (200 === $status) {
            $this->assertUserResponse($this->client, $this->users[$subject]['id']);
        } else {
            $this->assertError($this->client, 403, 'FORBIDDEN');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function nonAdministratorRoles(): iterable
    {
        yield 'employee' => ['employee'];
        yield 'manager' => ['manager'];
        yield 'deputy' => ['deputy'];
    }

    #[DataProvider('nonAdministratorRoles')]
    public function testNonAdministratorsCannotListAccountsOrResetOtherPasswords(string $role): void
    {
        $this->login($this->client, $role);
        $this->client->request('GET', '/api/admin/users');
        $this->assertError($this->client, 403, 'FORBIDDEN');
        $before = $this->credentialRow('peer');
        $this->post($this->client, '/api/admin/users/'.$this->users['peer']['id'].'/reset-password', ['adminPassword' => self::PASSWORD]);
        $this->assertError($this->client, 403, 'FORBIDDEN');
        self::assertSame($before, $this->credentialRow('peer'));
    }

    public function testAdminResetRequiresCsrfAndCurrentAdminPasswordAndRejectsSelfReset(): void
    {
        $this->login($this->client, 'admin');
        $path = '/api/admin/users/'.$this->users['employee']['id'].'/reset-password';
        $before = $this->credentialRow('employee');
        $this->post($this->client, $path, ['adminPassword' => self::PASSWORD], false);
        $this->assertError($this->client, 403, 'CSRF_INVALID');
        $this->post($this->client, $path, ['adminPassword' => 'Incorrect administrator password']);
        $this->assertError($this->client, 422, 'INVALID_CURRENT_PASSWORD');
        self::assertSame($before, $this->credentialRow('employee'));
        $this->post($this->client, '/api/admin/users/'.$this->users['admin']['id'].'/reset-password', ['adminPassword' => self::PASSWORD]);
        $this->assertError($this->client, 422, 'VALIDATION_FAILED');
    }

    public function testAdminResetShowsTemporarySecretOnceAndRevokesTargetSessions(): void
    {
        $this->login($this->client, 'admin');
        $target = $this->newBrowser();
        $this->login($target, 'employee');
        $before = $this->credentialRow('employee');
        $this->post($this->client, '/api/admin/users/'.$this->users['employee']['id'].'/reset-password', ['adminPassword' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->content($this->client));
        $result = $this->data($this->client);
        self::assertIsString($result['temporaryPassword']);
        self::assertGreaterThanOrEqual(24, strlen($result['temporaryPassword']));
        self::assertIsString($result['expiresAt']);
        $expiry = new \DateTimeImmutable($result['expiresAt']);
        self::assertGreaterThan(time() + 23 * 3600, $expiry->getTimestamp());
        self::assertLessThanOrEqual(time() + 24 * 3600 + 10, $expiry->getTimestamp());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');
        $after = $this->credentialRow('employee');
        self::assertSame((int) $before['credential_version'] + 1, (int) $after['credential_version']);
        self::assertSame(1, (int) $after['must_change_password']);
        $target->request('GET', '/api/auth/me');
        $this->assertError($target, 401, 'AUTH_REQUIRED');
        $this->client->request('GET', '/api/auth/me');
        $this->assertUserResponse($this->client, $this->users['admin']['id']);
        $this->post($target, '/api/auth/login', ['email' => $this->users['employee']['email'], 'password' => self::PASSWORD]);
        $this->assertError($target, 401, 'INVALID_CREDENTIALS');
        $this->login($target, 'employee', $result['temporaryPassword']);
        self::assertTrue($this->data($target)['user']['mustChangePassword']);
        $this->client->request('GET', '/api/admin/users');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->content($this->client));
        $content = $this->content($this->client);
        self::assertStringNotContainsString($result['temporaryPassword'], $content);
        self::assertStringNotContainsString($after['password_hash'], $content);
        self::assertStringNotContainsString('passwordHash', $content);
        $this->assertAuditContainsNoCredentials('employee', [self::PASSWORD, $result['temporaryPassword'], $before['password_hash'], $after['password_hash']]);
    }

    public function testConfirmationAttemptsAreLimitedAcrossResetTargetsAndPasswordChange(): void
    {
        $this->login($this->client, 'admin');
        foreach (['employee', 'peer', 'manager', 'deputy', 'other'] as $target) {
            $this->post($this->client, '/api/admin/users/'.$this->users[$target]['id'].'/reset-password', ['adminPassword' => 'Wrong administrator confirmation']);
            $this->assertError($this->client, 422, 'INVALID_CURRENT_PASSWORD');
        }
        $this->post($this->client, '/api/auth/change-password', ['currentPassword' => self::PASSWORD, 'newPassword' => self::NEW_PASSWORD]);
        $this->assertError($this->client, 429, 'CREDENTIAL_THROTTLED');
        $other = $this->newBrowser();
        $this->login($other, 'admin');
        $this->post($other, '/api/admin/users/'.$this->users['employee']['id'].'/reset-password', ['adminPassword' => self::PASSWORD]);
        $this->assertError($other, 429, 'CREDENTIAL_THROTTLED');
    }

    /** @return iterable<string, array{string}> */
    public static function revokedIdentityChanges(): iterable
    {
        yield 'deactivation' => ['inactive'];
        yield 'administrator privilege removed' => ['admin'];
        yield 'team role changed' => ['role'];
        yield 'team changed' => ['team'];
        yield 'credential revision changed' => ['credential'];
    }

    #[DataProvider('revokedIdentityChanges')]
    public function testChangedIdentityIsRevalidatedOnTheNextHttpRequest(string $change): void
    {
        $actor = 'admin' === $change ? 'admin' : 'manager';
        $this->login($this->client, $actor);
        $connection = $this->manager()->getConnection();
        $id = $this->users[$actor]['id'];
        match ($change) {
            'inactive' => $connection->update('app_user', ['active' => 0], ['id' => $id]),
            'admin' => $connection->update('app_user', ['is_administrator' => 0], ['id' => $id]),
            'role' => $connection->update('staff_assignment', ['role' => 'employee'], ['user_id' => $id]),
            'team' => $connection->update('staff_assignment', ['team_id' => $this->teamIds['beta']], ['user_id' => $id]),
            'credential' => $connection->executeStatement('UPDATE app_user SET credential_version = credential_version + 1 WHERE id = ?', [$id]),
            default => throw new \LogicException('Unexpected test case.'),
        };
        $this->client->request('GET', '/api/auth/me');
        $this->assertError($this->client, 401, 'AUTH_REQUIRED');
    }

    public function testTemporaryPasswordExpirationAlsoEndsAnAlreadyAuthenticatedSession(): void
    {
        $this->setTemporary('employee', new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')));
        $this->login($this->client, 'employee');
        $this->updateUser('employee', ['temporary_password_expires_at' => '2000-01-01 00:00:00']);
        $this->client->request('GET', '/api/auth/me');
        $this->assertError($this->client, 401, 'AUTH_REQUIRED');
    }

    public function testReactivationCannotReviveASessionThatPredatesDeactivation(): void
    {
        $this->login($this->client, 'employee');
        $em = $this->manager();
        $id = $this->users['employee']['id'];
        $user = $em->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);
        $version = $user->getCredentialVersion();
        $user->deactivate();
        $em->flush();
        self::assertSame($version + 1, $user->getCredentialVersion());

        // Simulate a later activation before the stale browser makes any request.
        $em->getConnection()->update('app_user', ['active' => 1], ['id' => $id]);
        $this->client->request('GET', '/api/auth/me');
        $this->assertError($this->client, 401, 'AUTH_REQUIRED');
        $this->login($this->client, 'employee');
    }

    /** @return iterable<string, array{int, int}> */
    public static function expiredSessionCases(): iterable
    {
        yield 'idle timeout' => [4000, 3601];
        yield 'absolute timeout despite recent activity' => [28801, 1];
    }

    #[DataProvider('expiredSessionCases')]
    public function testIdleAndAbsoluteSessionLimitsAreEnforced(int $startedAgo, int $lastSeenAgo): void
    {
        $this->login($this->client, 'employee');
        $session = $this->client->getSession();
        self::assertNotNull($session);
        $session->set(SecuritySession::STARTED_AT, time() - $startedAgo);
        $session->set(SecuritySession::LAST_SEEN_AT, time() - $lastSeenAgo);
        $session->save();
        $this->client->request('GET', '/api/auth/me');
        $this->assertError($this->client, 401, 'AUTH_REQUIRED');
    }

    public function testRepeatedInvalidLoginsAreThrottledAcrossBrowserSessions(): void
    {
        $body = ['email' => $this->users['employee']['email'], 'password' => 'Repeated incorrect passphrase'];
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->post($this->client, '/api/auth/login', $body);
            $this->assertError($this->client, 401, 'INVALID_CREDENTIALS');
        }
        $this->post($this->client, '/api/auth/login', $body);
        $this->assertError($this->client, 429, 'LOGIN_THROTTLED');
        $other = $this->newBrowser($this->ip);
        $this->post($other, '/api/auth/login', ['email' => $this->users['employee']['email'], 'password' => self::PASSWORD]);
        $this->assertError($other, 429, 'LOGIN_THROTTLED');
    }

    private function seedUsers(): void
    {
        $em = $this->manager();
        $factory = $this->client->getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);
        $this->passwordHash = $factory->getPasswordHasher(SecurityIdentity::class)->hash(self::PASSWORD);
        $employment = new EmploymentType($this->prefix.'_uop', 'Synthetic employment', true);
        $alpha = new Team($this->prefix.'_alpha', 'Synthetic Alpha');
        $beta = new Team($this->prefix.'_beta', 'Synthetic Beta');
        $em->persist($employment);
        $em->persist($alpha);
        $em->persist($beta);
        $objects = [];
        foreach (['admin' => TeamRole::EMPLOYEE, 'employee' => TeamRole::EMPLOYEE, 'peer' => TeamRole::EMPLOYEE, 'manager' => TeamRole::MANAGER, 'deputy' => TeamRole::DEPUTY, 'other' => TeamRole::EMPLOYEE] as $key => $role) {
            $user = new User('Synthetic', ucfirst($key), $this->prefix.'_'.$key.'@example.test', 'admin' === $key);
            $user->replacePasswordHash($this->passwordHash, false, null);
            $assignment = new StaffAssignment($user, 'other' === $key ? $beta : $alpha, $employment, $role, new \DateTimeImmutable('2020-01-01', new \DateTimeZone('Europe/Warsaw')));
            $em->persist($user);
            $em->persist($assignment);
            $objects[$key] = $user;
        }
        $em->flush();
        foreach ($objects as $key => $user) {
            $id = $user->getId();
            self::assertNotNull($id);
            $this->users[$key] = ['id' => $id, 'email' => $user->getEmail()];
        }
        self::assertNotNull($alpha->getId());
        self::assertNotNull($beta->getId());
        $this->teamIds = ['alpha' => $alpha->getId(), 'beta' => $beta->getId()];
    }

    private function manager(): EntityManagerInterface
    {
        $registry = $this->client->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $em = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function newBrowser(?string $ip = null): KernelBrowser
    {
        $browser = new KernelBrowser(new Kernel('test', true), ['REMOTE_ADDR' => $ip ?? $this->ip, 'HTTP_HOST' => 'localhost']);
        $this->extraBrowsers[] = $browser;

        return $browser;
    }

    private function login(KernelBrowser $browser, string $key, string $password = self::PASSWORD): void
    {
        $this->post($browser, '/api/auth/login', ['email' => $this->users[$key]['email'], 'password' => $password]);
        $this->assertUserResponse($browser, $this->users[$key]['id']);
    }

    private function csrf(KernelBrowser $browser): string
    {
        $browser->request('GET', '/api/auth/csrf');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), $this->content($browser));
        self::assertStringContainsString('no-store', $browser->getResponse()->headers->get('Cache-Control') ?? '');
        $token = $this->data($browser)['csrfToken'];
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    /** @param array<string, mixed> $body */
    private function post(KernelBrowser $browser, string $path, array $body, bool $csrf = true): void
    {
        $headers = $csrf ? ['HTTP_X_CSRF_TOKEN' => $this->csrf($browser)] : [];
        $browser->jsonRequest('POST', $path, $body, $headers);
    }

    private function assertError(KernelBrowser $browser, int $status, string $code): void
    {
        self::assertSame($status, $browser->getResponse()->getStatusCode(), $this->content($browser));
        $body = $this->data($browser);
        self::assertIsArray($body['error'] ?? null);
        self::assertSame($code, $body['error']['code'] ?? null);
        self::assertIsString($body['error']['message'] ?? null);
    }

    /** @return array<string, mixed> */
    private function assertUserResponse(KernelBrowser $browser, int $id): array
    {
        self::assertSame(200, $browser->getResponse()->getStatusCode(), $this->content($browser));
        $user = $this->data($browser)['user'] ?? null;
        self::assertIsArray($user);
        self::assertSame($id, $user['id'] ?? null);
        $keys = array_keys($user);
        sort($keys);
        self::assertSame(['email', 'firstName', 'id', 'isAdministrator', 'lastName', 'mustChangePassword', 'role', 'team'], $keys);
        self::assertStringNotContainsString($this->passwordHash, $this->content($browser));
        self::assertStringNotContainsString(self::PASSWORD, $this->content($browser));

        return $user;
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

    /** @param array<string, int|string|null> $values */
    private function updateUser(string $key, array $values): void
    {
        $this->manager()->getConnection()->update('app_user', $values, ['id' => $this->users[$key]['id']]);
    }

    private function setTemporary(string $key, \DateTimeImmutable $expires): void
    {
        $em = $this->manager();
        $user = $em->find(User::class, $this->users[$key]['id']);
        self::assertInstanceOf(User::class, $user);
        $user->replacePasswordHash($this->passwordHash, true, $expires);
        $em->flush();
    }

    /** @return array{password_hash: string, credential_version: int|string, must_change_password: int|string, temporary_password_expires_at: string|null} */
    private function credentialRow(string $key): array
    {
        $row = $this->manager()->getConnection()->fetchAssociative('SELECT password_hash, credential_version, must_change_password, temporary_password_expires_at FROM app_user WHERE id = ?', [$this->users[$key]['id']]);
        self::assertIsArray($row);
        self::assertIsString($row['password_hash']);

        return $row;
    }

    /** @param list<string> $secrets */
    private function assertAuditContainsNoCredentials(string $key, array $secrets): void
    {
        $rows = $this->manager()->getConnection()->fetchAllAssociative('SELECT before_data, after_data FROM audit_event WHERE subject_id = ?', [$this->users[$key]['id']]);
        self::assertNotEmpty($rows, 'A credential mutation must leave an audit event.');
        $json = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }
}
