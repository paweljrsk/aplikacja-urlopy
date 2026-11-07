<?php

namespace App\Tests\Functional;

use App\Entity\AbsenceType;
use App\Entity\AbsenceTypeVersion;
use App\Entity\AuditEvent;
use App\Entity\EmploymentType;
use App\Entity\Holiday;
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
use App\Security\CurrentAssignmentResolver;
use App\Security\SecurityIdentity;
use App\Service\Calendar\WorkingCalendar;
use App\Service\CredentialsException;
use App\Service\Requests\TeamCalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Isolated synthetic fixtures; real HTTP, auth and MySQL, without mocked calendar policies. */
final class TeamCalendarApiTest extends WebTestCase
{
    private const PASSWORD = 'Synthetic calendar test phrase 61!';

    private KernelBrowser $client;
    private MockClock $clock;
    private ClockInterface $previousClock;
    private string $prefix;
    private ?string $loggedIn = null;
    /** @var array<string, int> */
    private array $ids = [];
    /** @var array<string, string> */
    private array $emails = [];

    protected function setUp(): void
    {
        $this->previousClock = Clock::get();
        $this->clock = new MockClock('2026-11-09 12:00:00 Europe/Warsaw');
        Clock::set($this->clock);
        $this->prefix = 'teamcal_'.bin2hex(random_bytes(6));
        $this->client = self::createClient([], ['REMOTE_ADDR' => sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254)), 'HTTP_HOST' => 'localhost']);
        $em = $this->manager();
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', (string) $em->getConnection()->getDatabase());
        self::assertStringContainsString('mysql', $em->getConnection()->getParams()['driver'] ?? '');
        $factory = $this->client->getContainer()->get(PasswordHasherFactoryInterface::class);
        $hash = $factory->getPasswordHasher(SecurityIdentity::class)->hash(self::PASSWORD);
        $alpha = new Team($this->prefix.'_a', 'Synthetic Alpha '.$this->prefix);
        $beta = new Team($this->prefix.'_b', 'Synthetic Beta '.$this->prefix);
        $employment = new EmploymentType($this->prefix.'_uop', 'Synthetic UoP', true);
        $objects = ['alpha' => $alpha, 'beta' => $beta, 'employment' => $employment];
        foreach (['admin' => TeamRole::EMPLOYEE, 'employee' => TeamRole::EMPLOYEE, 'peer' => TeamRole::EMPLOYEE,
            'manager' => TeamRole::MANAGER, 'other' => TeamRole::EMPLOYEE, 'managerBeta' => TeamRole::MANAGER,
            'unassigned' => TeamRole::EMPLOYEE, 'temporary' => TeamRole::EMPLOYEE] as $key => $role) {
            $user = new User('Synthetic', 'Calendar '.$key, $this->prefix.'_'.$key.'@example.test', 'admin' === $key);
            $temporary = 'temporary' === $key;
            $user->replacePasswordHash($hash, $temporary, $temporary ? self::date('2026-11-10') : null);
            if ('unassigned' !== $key) {
                new StaffAssignment($user, \in_array($key, ['other', 'managerBeta'], true) ? $beta : $alpha, $employment, $role, self::date('2025-01-01'));
            }
            $objects[$key] = $user;
            $this->emails[$key] = $user->getEmail();
        }
        $type = new AbsenceType($this->prefix.'_private_type');
        $policy = $type->createVersion('Private absence marker '.$this->prefix, true, [$employment]);
        $objects['type'] = $type;
        $objects['policy'] = $policy;
        foreach ($objects as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        foreach ($objects as $key => $entity) {
            self::assertNotNull($entity->getId());
            $this->ids[$key] = $entity->getId();
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Clock::set($this->previousClock);
        }
    }

    public function testAuthenticationForcedPasswordAndRevokedSessionProtectCalendar(): void
    {
        $this->get();
        $this->error(401);
        $this->login('temporary');
        $this->get();
        $this->error(403, 'PASSWORD_CHANGE_REQUIRED');
        $this->login('employee');
        $this->get();
        $this->success();
        $em = $this->manager();
        $actor = $em->find(User::class, $this->ids['employee']);
        $actor->deactivate();
        $em->flush();
        $this->get();
        $this->error(401);
    }

    public function testOnlyOwnTeamPublicFieldsAndSeparatePartialDayRequestsAreReturned(): void
    {
        $first = $this->request('employee', ['2026-11-12' => 3], DecisionOutcome::APPROVED);
        $second = $this->request('employee', ['2026-11-12' => 5]);
        $foreign = $this->request('other', ['2026-11-12' => 8], DecisionOutcome::APPROVED);
        $this->login('peer');
        $this->get();
        $result = $this->success();
        self::assertSame('Europe/Warsaw', $result['timezone']);
        self::assertSame('2026-11-09', $result['today']);
        self::assertSame($this->ids['alpha'], $result['selectedTeamId']);
        self::assertSame([$this->ids['alpha']], array_column($result['teams'], 'id'));
        self::assertNotContains($this->ids['other'], array_column($result['people'], 'id'));
        self::assertSame([$first, $second], array_column($result['entries'], 'requestId'));
        self::assertNotContains($foreign, array_column($result['entries'], 'requestId'));
        self::assertSame([3, 5], array_column($result['entries'], 'totalHours'));
        self::assertSame(['approved', 'pending'], array_column($result['entries'], 'status'));
        self::assertSame([false, false], array_column($result['entries'], 'canViewDetails'));
        foreach ($result['entries'] as $entry) {
            self::assertSame(['key', 'requestId', 'canViewDetails', 'userId', 'status', 'kind', 'days', 'startDate', 'endDate', 'totalHours'], array_keys($entry));
            self::assertSame(['date', 'hours'], array_keys($entry['days'][0]));
        }
        foreach ($result['people'] as $person) {
            self::assertSame(['id', 'firstName', 'lastName'], array_keys($person));
        }
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['Private absence marker', 'Private audit marker', 'absenceType', 'email', 'balance', 'reason', 'decision', 'employment', 'revision'] as $private) {
            self::assertStringNotContainsString($private, $serialized);
        }
        $this->client->request('GET', '/api/requests/'.$first);
        $this->error(403);
        $this->login('employee');
        $this->get();
        self::assertSame([true, true], array_column($this->success()['entries'], 'canViewDetails'));
    }

    public function testPendingChangeAndCancellationPreserveEffectiveAbsenceWithoutDuplicates(): void
    {
        $changed = $this->request('employee', ['2026-11-12' => 8], DecisionOutcome::APPROVED);
        $this->proposal($changed, RequestChangeKind::CHANGE, ['2026-11-13' => 8]);
        $cancelled = $this->request('employee', ['2026-11-16' => 8], DecisionOutcome::APPROVED);
        $this->proposal($cancelled, RequestChangeKind::CANCELLATION);
        $this->request('employee', ['2026-11-17' => 8], DecisionOutcome::REJECTED);
        $this->request('employee', ['2026-11-18' => 8], DecisionOutcome::WITHDRAWN);
        $closed = $this->request('employee', ['2026-11-19' => 8], DecisionOutcome::APPROVED);
        $this->proposal($closed, RequestChangeKind::CANCELLATION, [], DecisionOutcome::APPROVED);
        $this->login('peer');
        $this->get('2026-11-09', '2026-11-20');
        $entries = $this->success()['entries'];
        self::assertCount(3, $entries);
        self::assertSame([$changed, $changed, $cancelled], array_column($entries, 'requestId'));
        self::assertSame(['effective', 'proposed_change', 'effective'], array_column($entries, 'kind'));
        self::assertSame(['change_pending', 'change_pending', 'cancellation_pending'], array_column($entries, 'status'));
        self::assertSame(['2026-11-12', '2026-11-13', '2026-11-16'], array_column($entries, 'startDate'));
        self::assertCount(3, array_unique(array_column($entries, 'key')));
    }

    public function testTransferClipsDaysTotalsAndDetailPermissionWithoutLeakingHiddenProposal(): void
    {
        $this->transfer('employee', '2026-11-12');
        $mixed = $this->request('employee', ['2026-11-10' => 8, '2026-11-12' => 8], DecisionOutcome::APPROVED);
        $old = $this->request('employee', ['2026-11-09' => 8], DecisionOutcome::APPROVED);
        $this->proposal($old, RequestChangeKind::CHANGE, ['2026-11-16' => 8]);
        $this->clock->modify('2026-11-13 12:00:00 Europe/Warsaw');
        $this->login('manager');
        $this->get('2026-11-09', '2026-11-20');
        $entries = $this->success()['entries'];
        self::assertCount(2, $entries);
        $byId = array_column($entries, null, 'requestId');
        self::assertSame(8, $byId[$mixed]['totalHours']);
        self::assertSame('2026-11-10', $byId[$mixed]['startDate']);
        self::assertSame('2026-11-10', $byId[$mixed]['endDate']);
        self::assertFalse($byId[$mixed]['canViewDetails']);
        self::assertSame('approved', $byId[$old]['status']);
        self::assertTrue($byId[$old]['canViewDetails']);
        $serialized = json_encode($entries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('2026-11-12', $serialized);
        self::assertStringNotContainsString('2026-11-16', $serialized);
        self::assertStringNotContainsString('change_pending', $serialized);
        $this->login('managerBeta');
        $this->get('2026-11-09', '2026-11-20');
        $entries = $this->success()['entries'];
        self::assertSame(['2026-11-12', '2026-11-16'], array_column($entries, 'startDate'));
        self::assertSame([8, 8], array_column($entries, 'totalHours'));
        self::assertSame([false, false], array_column($entries, 'canViewDetails'));
    }

    public function testScheduledTransferGrantsNoEarlyCalendarAccessAndScopeChangesOnItsDate(): void
    {
        $this->transfer('employee', '2026-11-12');
        $id = $this->request('employee', ['2026-11-16' => 8], DecisionOutcome::APPROVED);
        $this->login('other');
        $this->get();
        self::assertNotContains($this->ids['employee'], array_column($this->success()['people'], 'id'));
        $this->get();
        self::assertNotContains($id, array_column($this->success()['entries'], 'requestId'));
        $this->logout();
        $this->clock->modify('2026-11-12 00:01:00 Europe/Warsaw');
        $this->login('other');
        $this->get();
        $result = $this->success();
        self::assertContains($this->ids['employee'], array_column($result['people'], 'id'));
        self::assertContains($id, array_column($result['entries'], 'requestId'));
        $this->login('peer');
        $this->get();
        self::assertNotContains($id, array_column($this->success()['entries'], 'requestId'));
    }

    public function testAdminTeamFilterAndDateWindowCannotRevealExcludedDatesOrTotals(): void
    {
        $alpha = $this->request('employee', ['2026-11-10' => 8, '2026-11-12' => 8, '2026-11-13' => 8], DecisionOutcome::APPROVED);
        $beta = $this->request('other', ['2026-11-12' => 3]);
        $this->login('peer');
        $this->get(extra: ['teamId' => (string) $this->ids['alpha']]);
        $this->error(403);
        $this->login('admin');
        $this->get('2026-11-12', '2026-11-12', ['teamId' => (string) $this->ids['alpha']]);
        $result = $this->success();
        self::assertSame($this->ids['alpha'], $result['selectedTeamId']);
        self::assertContains($this->ids['beta'], array_column($result['teams'], 'id'));
        self::assertSame([$alpha], array_column($result['entries'], 'requestId'));
        self::assertSame([8], array_column($result['entries'], 'totalHours'));
        self::assertSame(['2026-11-12'], array_column($result['entries'], 'startDate'));
        self::assertSame(['2026-11-12'], array_column($result['entries'], 'endDate'));
        self::assertNotContains($this->ids['other'], array_column($result['people'], 'id'));
        $this->get('2026-11-12', '2026-11-12', ['teamId' => (string) $this->ids['beta']]);
        self::assertSame([$beta], array_column($this->success()['entries'], 'requestId'));
        $this->get();
        $all = $this->success();
        self::assertNull($all['selectedTeamId']);
        self::assertContains($alpha, array_column($all['entries'], 'requestId'));
        self::assertContains($beta, array_column($all['entries'], 'requestId'));
        self::assertStringNotContainsString('Private absence marker', json_encode($all, JSON_THROW_ON_ERROR));
        $this->get(extra: ['teamId' => '999999999']);
        $this->error(404);
    }

    public function testStrictRangesBoundariesUnsupportedParametersAndUnassignedAccount(): void
    {
        $this->login('unassigned');
        $this->get();
        $result = $this->success();
        self::assertSame([], $result['teams']);
        self::assertNull($result['selectedTeamId']);
        self::assertSame([$this->ids['unassigned']], array_column($result['people'], 'id'));
        self::assertSame([], $result['entries']);
        foreach ([[], ['from' => '2026-11-09'], ['from' => '2026-02-30', 'to' => '2026-03-01'],
            ['from' => '2026-11-10', 'to' => '2026-11-09'], ['from' => '2024-12-31', 'to' => '2025-01-01'],
            ['from' => '2100-12-31', 'to' => '2101-01-01'], ['from' => ['2026-11-09'], 'to' => '2026-11-10'],
            ['from' => '2026-11-09', 'to' => '2026-11-10', 'userId' => (string) $this->ids['other']],
            ['from' => '2026-11-09', 'to' => '2026-11-10', 'teamId' => '1.0'],
            ['from' => '2026-11-09', 'to' => '2026-11-10', 'teamId' => ['1']]] as $query) {
            $this->client->request('GET', '/api/team-calendar', $query);
            $this->error(422);
        }
        $start = self::date('2026-11-09');
        $this->get('2026-11-09', $start->modify('+92 days')->format('Y-m-d'));
        $this->success();
        $this->get('2026-11-09', $start->modify('+93 days')->format('Y-m-d'));
        $this->error(422);
    }

    public function testCurrentRoleIsReloadedEvenWhenCallerHasAnOlderAdministratorEntity(): void
    {
        $em = $this->manager();
        $actor = $em->find(User::class, $this->ids['admin']);
        self::assertTrue($actor->isAdministrator());
        $service = $this->client->getContainer()->get(TeamCalendarService::class);
        $em->getConnection()->executeStatement('UPDATE app_user SET is_administrator = 0 WHERE id = ?', [$this->ids['admin']]);
        try {
            $service->list($actor, ['from' => '2026-11-09', 'to' => '2026-11-20', 'teamId' => (string) $this->ids['beta']]);
            self::fail('An old hydrated administrator entity must not bypass the fresh role.');
        } catch (CredentialsException $exception) {
            self::assertSame(403, $exception->httpStatus);
        }
    }

    public function testOnlyActiveCompanyHolidaysInsideTheRequestedWindowAreReturned(): void
    {
        $em = $this->manager();
        $date = self::date('2098-03-01');
        while (null !== $em->getRepository(Holiday::class)->findOneBy(['date' => $date])
            || null !== $em->getRepository(Holiday::class)->findOneBy(['date' => $date->modify('+1 day')])) {
            $date = $date->modify('+2 days');
        }
        $active = new Holiday($date, 'Synthetic active '.$this->prefix);
        $inactive = new Holiday($date->modify('+1 day'), 'Synthetic inactive '.$this->prefix);
        $inactive->deactivate();
        $em->persist($active);
        $em->persist($inactive);
        $em->flush();
        $ids = [$active->getId(), $inactive->getId()];
        try {
            $this->login('peer');
            $this->get($date->format('Y-m-d'), $date->modify('+2 days')->format('Y-m-d'));
            $holidays = array_column($this->success()['holidays'], 'name', 'date');
            self::assertStringContainsString('Synthetic active '.$this->prefix, $holidays[$date->format('Y-m-d')]);
            self::assertStringNotContainsString('Synthetic inactive '.$this->prefix, json_encode($holidays, JSON_THROW_ON_ERROR));
            $this->get($date->modify('+2 days')->format('Y-m-d'), $date->modify('+3 days')->format('Y-m-d'));
            self::assertStringNotContainsString($this->prefix, json_encode($this->success()['holidays'], JSON_THROW_ON_ERROR));
        } finally {
            $this->manager()->getConnection()->executeStatement('DELETE FROM holiday WHERE id IN (?, ?)', $ids);
        }
    }

    public function testMoreThanOneHundredEntriesRemainCompleteWithinTheBoundedPeriod(): void
    {
        $calendar = new WorkingCalendar($this->manager());
        $dates = array_slice(array_keys($calendar->days(self::date('2026-11-10'), self::date('2027-02-08'))), 0, 51);
        self::assertCount(51, $dates);
        foreach ($dates as $date) {
            $this->request('employee', [$date => 3], DecisionOutcome::APPROVED);
            $this->request('employee', [$date => 5]);
        }
        $this->login('peer');
        $this->get('2026-11-10', '2027-02-08');
        $result = $this->success();
        self::assertCount(102, $result['entries']);
        self::assertSame(408, array_sum(array_column($result['entries'], 'totalHours')));
        $holidays = array_column($result['holidays'], 'name', 'date');
        self::assertArrayHasKey('2026-11-11', $holidays);
        self::assertArrayHasKey('2026-12-24', $holidays);
        self::assertArrayHasKey('2027-01-01', $holidays);
        self::assertArrayNotHasKey('2026-11-01', $holidays);
    }

    /** @param array<string, int> $days */
    private function request(string $key, array $days, ?DecisionOutcome $outcome = null): int
    {
        $em = $this->manager();
        $subject = $em->find(User::class, $this->ids[$key]);
        $request = new LeaveRequest($subject, $this->clock->now());
        $revision = $this->revision($request, RequestChangeKind::INITIAL, $days);
        if (null !== $outcome) {
            new RequestDecision($revision, $outcome, $revision->getSource());
        }
        $em->persist($request);
        $em->flush();
        self::assertNotNull($request->getId());
        return $request->getId();
    }

    /** @param array<string, int> $days */
    private function proposal(int $id, RequestChangeKind $kind, array $days = [], ?DecisionOutcome $outcome = null): void
    {
        $em = $this->manager();
        $request = $em->find(LeaveRequest::class, $id);
        $base = $request->getEffectiveRevision();
        self::assertInstanceOf(RequestRevision::class, $base);
        if (RequestChangeKind::CANCELLATION === $kind) {
            foreach ($base->getDays() as $day) {
                $days[$day->getDate()->format('Y-m-d')] = $day->getHours();
            }
        }
        $revision = $this->revision($request, $kind, $days, $base);
        if (null !== $outcome) {
            new RequestDecision($revision, $outcome, $revision->getSource());
        }
        $em->flush();
    }

    /** @param array<string, int> $days */
    private function revision(LeaveRequest $request, RequestChangeKind $kind, array $days, ?RequestRevision $base = null): RequestRevision
    {
        ksort($days);
        $em = $this->manager();
        $subject = $request->getUser();
        $actor = $em->find(User::class, $this->ids['admin']);
        $source = new AuditEvent($this->prefix.'_'.bin2hex(random_bytes(8)), 'calendar.fixture', $actor, $subject,
            $this->clock->now(), [], ['reason' => 'Private audit marker '.$this->prefix], 'request', $request->getReference());
        $em->persist($source);
        $requested = [];
        $resolver = new CurrentAssignmentResolver();
        foreach ($days as $date => $hours) {
            $day = self::date($date);
            $assignment = $base?->capturedAssignmentFor($day) ?? $resolver->resolve($subject, $day);
            self::assertInstanceOf(StaffAssignment::class, $assignment);
            $requested[] = new RequestedDay($assignment, $day, $hours);
        }
        $mode = 1 === count($days) && reset($days) < 8 ? RequestMode::HOURLY : RequestMode::FULL_DAYS;
        return new RequestRevision($request, $kind, $em->find(AbsenceTypeVersion::class, $this->ids['policy']), $mode,
            self::date(array_key_first($days)), self::date(array_key_last($days)), $requested, $source, $base);
    }

    private function transfer(string $key, string $date): void
    {
        $em = $this->manager();
        $subject = $em->find(User::class, $this->ids[$key]);
        $subject->getAssignments()[0]->closeAt(self::date($date));
        $em->persist(new StaffAssignment($subject, $em->find(Team::class, $this->ids['beta']),
            $em->find(EmploymentType::class, $this->ids['employment']), TeamRole::EMPLOYEE, self::date($date)));
        $em->flush();
    }

    /** @param array<string, mixed> $extra */
    private function get(string $from = '2026-11-09', string $to = '2026-11-20', array $extra = []): void
    {
        $this->client->request('GET', '/api/team-calendar', ['from' => $from, 'to' => $to] + $extra);
    }

    private function login(string $key): void
    {
        if ($this->loggedIn === $key) {
            return;
        }
        $this->logout();
        $this->send('/api/auth/login', ['email' => $this->emails[$key], 'password' => self::PASSWORD]);
        self::assertSame($this->ids[$key], $this->success()['user']['id']);
        $this->loggedIn = $key;
    }

    private function logout(): void
    {
        if (null !== $this->loggedIn) {
            $this->send('/api/auth/logout', []);
            $this->success();
            $this->loggedIn = null;
        }
    }

    /** @param array<string, mixed> $body */
    private function send(string $path, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $token = $this->success()['csrfToken'];
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function success(): array
    {
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->content());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');
        return json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function error(int $status, ?string $code = null): void
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $this->content());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');
        if (null !== $code) {
            self::assertSame($code, json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR)['error']['code']);
        }
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

    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Warsaw'));
    }
}
