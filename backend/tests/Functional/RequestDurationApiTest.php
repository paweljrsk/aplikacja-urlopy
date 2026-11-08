<?php

namespace App\Tests\Functional;

use App\Entity\Holiday;
use App\Entity\User;
use App\Security\SecurityIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Calendar-only count through the real session, HTTP kernel and migrated MySQL. */
final class RequestDurationApiTest extends WebTestCase
{
    private const PASSWORD = 'Synthetic duration password 816!';
    private KernelBrowser $client;
    private ClockInterface $previousClock;
    private string $email;
    private int $userId;

    protected function setUp(): void
    {
        $this->previousClock = Clock::get();
        Clock::set(new MockClock('2026-11-09 12:00:00 Europe/Warsaw'));
        $this->client = self::createClient([], ['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254))]);
        $em = $this->manager();
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', (string) $em->getConnection()->getDatabase());
        $factory = $this->client->getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);
        $this->email = 'duration_'.bin2hex(random_bytes(6)).'@example.test';
        // No assignment, opening balance or annual limit: counting dates cannot depend on them.
        $user = new User('Synthetic', 'Duration', $this->email);
        $user->replacePasswordHash($factory->getPasswordHasher(SecurityIdentity::class)->hash(self::PASSWORD), false, null);
        $em->persist($user);
        $em->flush();
        self::assertNotNull($user->getId());
        $this->userId = $user->getId();
    }

    protected function tearDown(): void
    {
        try { parent::tearDown(); } finally { Clock::set($this->previousClock); }
    }

    public function testWorkingDaysExcludeWeekendsStatutoryAndMovableHolidaysWithoutAccountingWrites(): void
    {
        $this->login();
        $before = $this->snapshot();
        foreach ([
            ['2026-11-09', '2026-11-15', 4], // Wednesday holiday and both weekend days.
            ['2026-12-23', '2026-12-28', 2], // Christmas Eve, Christmas and weekend.
            ['2026-04-03', '2026-04-07', 2], // Easter weekend and Monday.
            ['2028-02-28', '2028-02-29', 2], // A real leap day, including historical dates.
            ['2026-12-31', '2027-01-04', 2], // Split years, New Year and weekend.
            ['2026-11-14', '2026-11-15', 0],
            ['2026-11-11', '2026-11-11', 0],
        ] as [$start, $end, $count]) {
            $this->send($this->body($start, $end));
            self::assertSame($this->duration($count, $count * 8), $this->success()['duration']);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testHourlyDurationPreservesHoursAndReturnsZeroForNonWorkingDates(): void
    {
        $this->login();
        foreach ([1, 3, 7] as $hours) {
            $this->send($this->body('2026-11-12', hours: $hours));
            self::assertSame($this->duration(1, $hours), $this->success()['duration']);
        }
        foreach (['2026-11-11', '2026-11-14'] as $date) {
            $this->send($this->body($date, hours: 3));
            self::assertSame($this->duration(0, 0), $this->success()['duration']);
        }
    }

    public function testCompanyDayChangeIsReadFresh(): void
    {
        $this->login();
        $db = $this->manager()->getConnection();
        $date = '2040-02-13'; // Monday; isolated from existing fixtures.
        self::assertFalse($db->fetchOne('SELECT id FROM holiday WHERE date = ?', [$date]));
        $holiday = new Holiday(new \DateTimeImmutable($date.' Europe/Warsaw'), 'Synthetic duration day off');
        $em = $this->manager();
        $em->persist($holiday); $em->flush();
        self::assertNotNull($holiday->getId());
        $id = $holiday->getId();
        try {
            $this->send($this->body('2040-02-10', '2040-02-14'));
            self::assertSame($this->duration(2, 16), $this->success()['duration']);
            $em = $this->manager();
            $fresh = $em->find(Holiday::class, $id);
            self::assertInstanceOf(Holiday::class, $fresh);
            $fresh->deactivate(); $em->flush();
            $this->send($this->body('2040-02-10', '2040-02-14'));
            self::assertSame($this->duration(3, 24), $this->success()['duration']);
        } finally {
            $this->manager()->getConnection()->delete('holiday', ['id' => $id]);
        }
    }

    public function testStrictDatesModesAndPayloadCannotOverrideTheCalculatedCount(): void
    {
        $this->login();
        $before = $this->snapshot();
        $base = $this->body('2026-11-12');
        foreach ([
            ['startDate' => '2026-02-30'], ['startDate' => '2026-11-13'],
            ['startDate' => '2026-1-2'], ['startDate' => null],
            ['endDate' => '2026-11-12T00:00:00Z'], ['mode' => 'days'], ['mode' => null],
            ['hours' => 8], ['mode' => 'hourly'], ['mode' => 'hourly', 'hours' => 8],
            ['mode' => 'hourly', 'hours' => 0], ['mode' => 'hourly', 'hours' => -1],
            ['mode' => 'hourly', 'hours' => 1.5], ['mode' => 'hourly', 'hours' => '3'],
            ['mode' => 'hourly', 'hours' => true],
            ['mode' => 'hourly', 'hours' => 3, 'endDate' => '2026-11-13'],
            ['userId' => $this->userId], ['teamId' => 1], ['absenceTypeId' => 1],
            ['totalHours' => 99], ['operationKey' => 'forged'], ['expectedVersion' => 1],
        ] as $change) {
            $this->send(array_replace($base, $change));
            $this->assertError(422, 'VALIDATION_FAILED');
        }
        foreach (['startDate', 'endDate', 'mode'] as $field) {
            $missing = $base; unset($missing[$field]);
            $this->send($missing); $this->assertError(422, 'VALIDATION_FAILED');
        }
        foreach (['2024-12-31', '2101-01-01'] as $date) {
            $this->send($this->body($date)); $this->assertError(422, 'CALENDAR_YEAR_UNSUPPORTED');
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testAuthenticationCsrfAndTemporaryPasswordGuardsApplyToTheRead(): void
    {
        $this->send($this->body('2026-11-12'));
        $this->assertError(401, 'AUTH_REQUIRED');
        $this->login();
        $this->send($this->body('2026-11-12'), csrf: false);
        $this->assertError(403, 'CSRF_INVALID');
        $this->send($this->body('2026-11-12'));
        self::assertSame(['duration'], array_keys($this->success()));
        $em = $this->manager();
        $user = $em->find(User::class, $this->userId);
        self::assertInstanceOf(User::class, $user);
        $hash = $user->getPasswordHash(); self::assertNotNull($hash);
        $user->replacePasswordHash($hash, true, new \DateTimeImmutable('2026-11-10 Europe/Warsaw'));
        $em->flush();
        // The previous session is invalidated; a fresh temporary session is restricted too.
        $this->client->getCookieJar()->clear();
        $this->login();
        $this->send($this->body('2026-11-12'));
        $this->assertError(403, 'PASSWORD_CHANGE_REQUIRED');
    }

    /** @return array{startDate: string, endDate: string, mode: string, hours?: int} */
    private function body(string $start, ?string $end = null, ?int $hours = null): array
    {
        $body = ['startDate' => $start, 'endDate' => $end ?? $start, 'mode' => null === $hours ? 'full_days' : 'hourly'];
        if (null !== $hours) { $body['hours'] = $hours; }
        return $body;
    }

    /** @return array{workingDays: int, totalHours: int, hoursPerDay: int, timezone: string} */
    private function duration(int $days, int $hours): array
    {
        return ['workingDays' => $days, 'totalHours' => $hours, 'hoursPerDay' => 8, 'timezone' => 'Europe/Warsaw'];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $db = $this->manager()->getConnection(); $result = [];
        foreach (['leave_request' => 'user_id', 'leave_account' => 'user_id', 'hour_pool' => 'user_id', 'daily_occupancy' => 'user_id', 'audit_event' => 'subject_id'] as $table => $column) {
            $result[$table] = $db->fetchAllAssociative('SELECT * FROM '.$table.' WHERE '.$column.' = ? ORDER BY id', [$this->userId]);
        }
        return $result;
    }

    private function login(): void
    {
        $this->send(['email' => $this->email, 'password' => self::PASSWORD], path: '/api/auth/login');
        self::assertSame($this->userId, $this->success()['user']['id']);
    }

    /** @param array<string, mixed> $body */
    private function send(array $body, bool $csrf = true, string $path = '/api/requests/duration'): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf) {
            $this->client->request('GET', '/api/auth/csrf');
            $headers['HTTP_X_CSRF_TOKEN'] = $this->success()['csrfToken'];
        }
        $this->client->request('POST', $path, [], [], $headers, json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function success(): array
    {
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->content());
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
        $content = $this->client->getResponse()->getContent(); self::assertIsString($content); return $content;
    }

    private function manager(): EntityManagerInterface
    {
        $registry = $this->client->getContainer()->get('doctrine'); self::assertInstanceOf(ManagerRegistry::class, $registry);
        $em = $registry->getManager(); self::assertInstanceOf(EntityManagerInterface::class, $em); return $em;
    }
}
