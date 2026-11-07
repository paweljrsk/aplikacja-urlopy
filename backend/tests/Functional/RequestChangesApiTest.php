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
final class RequestChangesApiTest extends WebTestCase
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
        $this->prefix = 'changeapi_'.bin2hex(random_bytes(6));
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

    public function testApprovedChangeRejectionReductionAndCancellationHaveSeparateVersionsAndOneRefund(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $original = $this->create('2026-11-12', '2026-11-13');
        $approved = $this->approveAs('manager', $original);
        $this->login('employee');
        $details = $this->detail($original['id']);
        self::assertTrue($details['actions']['change']);
        self::assertTrue($details['actions']['cancel']);
        self::assertNull($details['pending']);
        self::assertSame(16, $details['effective']['totalHours']);
        $changeBody = $this->body('2026-11-12', '2026-11-16') + ['expectedVersion' => $approved['version']];
        $this->send('/api/requests/'.$original['id'].'/change', $changeBody);
        $changed = $this->success()['operation'];
        self::assertSame('change_pending', $changed['status']);
        $details = $this->detail($original['id']);
        self::assertSame(16, $details['effective']['totalHours']);
        self::assertSame(24, $details['pending']['totalHours']);
        self::assertSame($details['effective']['revision'], $details['pending']['baseRevision']);
        self::assertFalse($details['actions']['edit']);
        self::assertFalse($details['actions']['change']);
        self::assertTrue($details['actions']['withdraw']);
        self::assertSame(56, $this->balance('employee')['availableHours']);
        $ledger = $this->ledgerCount();
        $this->send('/api/requests/'.$original['id'].'/change', $changeBody);
        $this->assertSameReceipt($changed, $this->success()['operation']);
        self::assertSame($ledger, $this->ledgerCount());
        $this->login('manager');
        $this->send('/api/requests/'.$original['id'].'/reject', $this->transition($changed) + ['reason' => 'Synthetic reason for refusing longer leave']);
        $rejected = $this->success()['operation'];
        self::assertSame('approved', $rejected['status']);
        self::assertSame(64, $this->balance('employee')['availableHours']);
        $this->login('employee');
        $details = $this->detail($original['id']);
        self::assertNull($details['pending']);
        self::assertSame(16, $details['effective']['totalHours']);
        self::assertSame('rejected', $details['history'][1]['decision']['outcome']);
        $this->send('/api/requests/'.$original['id'].'/change', $this->body('2026-11-12') + ['expectedVersion' => $rejected['version']]);
        $reduction = $this->success()['operation'];
        self::assertSame(64, $this->balance('employee')['availableHours']);
        $reduced = $this->approveAs('manager', $reduction);
        self::assertSame(72, $this->balance('employee')['availableHours']);
        $this->login('employee');
        $this->send('/api/requests/'.$original['id'].'/cancel', $this->transition($reduced));
        $cancellation = $this->success()['operation'];
        self::assertSame('cancellation_pending', $cancellation['status']);
        self::assertSame(72, $this->balance('employee')['availableHours']);
        $details = $this->detail($original['id']);
        self::assertSame('cancellation', $details['pending']['kind']);
        self::assertSame(8, $details['effective']['totalHours']);
        $this->login('manager');
        $confirm = $this->transition($cancellation);
        $this->send('/api/requests/'.$original['id'].'/approve', $confirm);
        $cancelled = $this->success()['operation'];
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(80, $this->balance('employee')['availableHours']);
        $ledger = $this->ledgerCount();
        $this->send('/api/requests/'.$original['id'].'/approve', $confirm);
        $this->assertSameReceipt($cancelled, $this->success()['operation']);
        self::assertSame($ledger, $this->ledgerCount());
        self::assertNull($this->detail($original['id'])['effective']);
    }

    public function testWithdrawalOfAChangeRetainsTheApprovedLeaveAndAuthorizationCannotBeBypassed(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $approved = $this->approveAs('manager', $this->create('2026-11-12', null, 3));
        $this->login('employee');
        $body = $this->body('2026-11-12', null, 5) + ['expectedVersion' => $approved['version']];
        $this->send('/api/requests/'.$approved['id'].'/change', $body, false);
        $this->assertError(403, 'CSRF_INVALID');
        $this->send('/api/requests/'.$approved['id'].'/change', $body + ['userId' => $this->ids['other']]);
        $this->assertError(422);
        $this->send('/api/requests/'.$approved['id'].'/change', $body);
        $change = $this->success()['operation'];
        self::assertSame(75, $this->balance('employee')['availableHours']);
        $this->send('/api/requests/'.$approved['id'].'/approve', $this->transition($change));
        $this->assertError(403, 'FORBIDDEN');
        $this->login('other');
        $this->send('/api/requests/'.$approved['id'].'/withdraw', $this->transition($change));
        $this->assertError(403, 'FORBIDDEN');
        $this->send('/api/requests/'.$approved['id'].'/correction-preview', $this->correction($change, '2026-11-12', null, 4));
        $this->assertError(403, 'FORBIDDEN');
        $this->login('employee');
        $this->send('/api/requests/'.$approved['id'].'/withdraw', $this->transition($change));
        self::assertSame('approved', $this->success()['operation']['status']);
        self::assertSame(77, $this->balance('employee')['availableHours']);
        self::assertSame(3, $this->detail($approved['id'])['effective']['totalHours']);
        self::assertNull($this->detail($approved['id'])['pending']);
        $this->send('/api/requests/'.$approved['id'].'/cancel', $this->transition($approved));
        $this->assertError(409, 'CONFLICT');
    }

    public function testHistoricalCorrectionRequiresAReadOnlyFreshPreviewReasonAndExplicitConfirmation(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $approved = $this->approveAs('manager', $this->create('2026-11-09'));
        $this->logout();
        $this->clock->modify('2026-11-12 12:00:00 Europe/Warsaw');
        $this->login('employee');
        self::assertFalse($this->detail($approved['id'])['actions']['change']);
        $payload = $this->correction($approved, '2026-11-09', null, 4);
        $this->send('/api/requests/'.$approved['id'].'/correction-preview', $payload);
        $this->assertError(403, 'FORBIDDEN');
        $this->login('manager');
        self::assertTrue($this->detail($approved['id'])['actions']['correct']);
        $this->send('/api/requests/'.$approved['id'].'/correction-preview', array_replace($payload, ['reason' => '   ']));
        $this->assertError(422);
        $before = $this->counts();
        $this->send('/api/requests/'.$approved['id'].'/correction-preview', $payload);
        $preview = $this->success()['preview'];
        self::assertSame($before, $this->counts());
        self::assertFalse($preview['requiresDeficitConfirmation']);
        self::assertSame(8, array_sum(array_column($preview['before']['days'], 'hours')));
        self::assertSame(4, array_sum(array_column($preview['after']['days'], 'hours')));
        self::assertSame([['year' => 2026, 'beforeHours' => 8, 'afterHours' => 4, 'deltaHours' => -4]], $preview['impact']['hoursByYear']);
        $confirm = $payload + ['operationKey' => $this->key(), 'previewToken' => $preview['previewToken'], 'confirm' => true];
        $this->send('/api/requests/'.$approved['id'].'/correct', array_replace($confirm, ['confirm' => false]));
        $this->assertError(422);
        $this->send('/api/requests/'.$approved['id'].'/correct', array_replace($confirm, ['hours' => 5]));
        $this->assertError(409);
        self::assertSame($before, $this->counts());
        $this->send('/api/requests/'.$approved['id'].'/correct', $confirm);
        $corrected = $this->success()['operation'];
        self::assertSame('approved', $corrected['status']);
        self::assertSame(76, $this->balance('employee')['availableHours']);
        $history = $this->detail($approved['id'])['history'];
        self::assertCount(2, $history);
        self::assertSame(8, $history[0]['totalHours']);
        self::assertSame(4, $history[1]['totalHours']);
        self::assertSame($payload['reason'], $history[1]['submitted']['reason']);
        self::assertSame($payload['reason'], $history[1]['decision']['reason']);
        $written = $this->counts();
        $this->send('/api/requests/'.$approved['id'].'/correct', $confirm);
        $this->assertSameReceipt($corrected, $this->success()['operation']);
        self::assertSame($written, $this->counts());
    }

    public function testManagerMayCorrectOwnHistoryButDeputyCannotDecideOrCorrectOwnLeave(): void
    {
        foreach (['manager', 'deputy'] as $role) {
            $this->opening($role);
            $this->login($role);
            $created = $this->create('2026-11-09');
            if ('deputy' === $role) {
                $this->send('/api/requests/'.$created['id'].'/approve', $this->transition($created));
                $this->assertError(403, 'FORBIDDEN');
            }
            $approved[$role] = $this->approveAs('manager', $created);
        }
        $this->logout();
        $this->clock->modify('2026-11-12 12:00:00 Europe/Warsaw');
        $this->login('deputy');
        self::assertFalse($this->detail($approved['deputy']['id'])['actions']['correct']);
        $payload = $this->correction($approved['deputy'], '2026-11-09', null, 4);
        $this->send('/api/requests/'.$approved['deputy']['id'].'/correction-preview', $payload);
        $this->assertError(403, 'FORBIDDEN');
        $this->login('manager');
        foreach (['manager', 'deputy'] as $role) {
            self::assertTrue($this->detail($approved[$role]['id'])['actions']['correct']);
            $payload = $this->correction($approved[$role], '2026-11-09', null, 4);
            $this->send('/api/requests/'.$approved[$role]['id'].'/correction-preview', $payload);
            $preview = $this->success()['preview'];
            $this->send('/api/requests/'.$approved[$role]['id'].'/correct', $payload + ['operationKey' => $this->key(), 'previewToken' => $preview['previewToken'], 'confirm' => true]);
            self::assertSame('approved', $this->success()['operation']['status']);
            self::assertSame(4, $this->detail($approved[$role]['id'])['effective']['totalHours']);
        }
    }

    public function testOnlyAdministratorCanExplicitlyAcceptADeficitAndOtherApprovedLeaveIsPreserved(): void
    {
        $this->opening('employee', 16);
        $this->login('employee');
        $old = $this->approveAs('manager', $this->create('2026-11-09'));
        $this->login('employee');
        $other = $this->approveAs('manager', $this->create('2026-11-13'));
        $this->logout();
        $this->clock->modify('2026-11-12 12:00:00 Europe/Warsaw');
        $payload = $this->correction($old, '2026-11-09', '2026-11-10');
        $this->login('admin');
        $before = $this->counts();
        $this->send('/api/requests/'.$old['id'].'/correction-preview', $payload);
        $preview = $this->success()['preview'];
        self::assertTrue($preview['requiresDeficitConfirmation']);
        self::assertSame($before, $this->counts());
        $confirm = $payload + ['operationKey' => $this->key(), 'previewToken' => $preview['previewToken'], 'confirm' => true];
        $this->send('/api/requests/'.$old['id'].'/correct', $confirm);
        $this->assertError(422);
        $this->login('manager');
        $this->send('/api/requests/'.$old['id'].'/correct', $confirm + ['allowDeficit' => true]);
        $this->assertError(403, 'FORBIDDEN');
        $this->login('admin');
        $this->send('/api/requests/'.$old['id'].'/correct', $confirm + ['allowDeficit' => true]);
        $corrected = $this->success()['operation'];
        self::assertSame('approved', $corrected['status']);
        self::assertSame(-8, $this->balance('employee')['availableHours']);
        self::assertSame(8, $this->detail($other['id'])['effective']['totalHours']);
        self::assertSame('approved', $this->detail($other['id'])['status']);
        $this->login('employee');
        $this->send('/api/requests', $this->body('2026-11-16'));
        $this->assertError(422, 'INSUFFICIENT_BALANCE');
    }

    public function testTransferKeepsVisibleEffectiveVersionButDoesNotRevealMixedTeamProposal(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $old = $this->approveAs('manager', $this->create('2026-11-10'));
        $em = $this->manager();
        $subject = $em->find(User::class, $this->ids['employee']);
        $beta = $em->find(Team::class, $this->ids['beta']);
        $employment = $em->find(EmploymentType::class, $this->ids['employment']);
        self::assertInstanceOf(User::class, $subject);
        self::assertInstanceOf(Team::class, $beta);
        self::assertInstanceOf(EmploymentType::class, $employment);
        $subject->getAssignments()[0]->closeAt(self::date('2026-11-12'));
        $em->persist(new StaffAssignment($subject, $beta, $employment, TeamRole::EMPLOYEE, self::date('2026-11-12')));
        $em->flush();
        $this->login('employee');
        $this->send('/api/requests/'.$old['id'].'/change', $this->body('2026-11-16') + ['expectedVersion' => $old['version']]);
        $change = $this->success()['operation'];
        $this->logout();
        $this->clock->modify('2026-11-13 12:00:00 Europe/Warsaw');
        $this->login('manager');
        $oldView = $this->detail($old['id']);
        self::assertFalse($oldView['currentVisible']);
        self::assertNull($oldView['current']);
        self::assertNull($oldView['pending']);
        self::assertNull($oldView['version']);
        self::assertNull($oldView['status']);
        self::assertSame('2026-11-10', $oldView['effective']['startDate']);
        self::assertSame([1], array_column($oldView['history'], 'revision'));
        self::assertSame([false], array_values(array_unique($oldView['actions'])));
        self::assertStringNotContainsString('2026-11-16', json_encode($oldView, JSON_THROW_ON_ERROR));
        $this->client->request('GET', '/api/requests?scope=managed&status=awaiting');
        $queue = $this->success();
        self::assertNotContains($old['id'], array_column($queue['requests'], 'id'));
        $this->send('/api/requests/'.$old['id'].'/decision-preview', ['action' => 'approve', 'expectedVersion' => $change['version']]);
        $this->assertError(403, 'FORBIDDEN');
        self::assertStringNotContainsString('AvailableHours', $this->content());
        $this->send('/api/requests/'.$old['id'].'/approve', $this->transition($change));
        $this->assertError(403, 'FORBIDDEN');
        $this->login('managerBeta');
        $this->client->request('GET', '/api/requests/'.$old['id']);
        $this->assertError(403, 'FORBIDDEN');
        $this->login('admin');
        $view = $this->detail($old['id']);
        self::assertTrue($view['actions']['approve']);
        self::assertSame('2026-11-16', $view['pending']['startDate']);
        self::assertSame('2026-11-10', $view['effective']['startDate']);
    }

    public function testCorrectionPreviewExpiresWhenBalanceChangesAndHistoricalCancellationReturnsHoursOnce(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $approved = $this->approveAs('manager', $this->create('2026-11-09'));
        $this->logout();
        $this->clock->modify('2026-11-12 12:00:00 Europe/Warsaw');
        $this->login('admin');
        $payload = ['expectedVersion' => $approved['version'], 'action' => 'cancel', 'reason' => 'Synthetic cancellation of an incorrect historical entry'];
        $this->send('/api/requests/'.$approved['id'].'/correction-preview', $payload);
        $preview = $this->success()['preview'];
        $balance = $this->balance('employee');
        $this->send('/api/balances/users/'.$this->ids['employee'].'/2026/limit', [
            'operationKey' => $this->key(), 'expectedVersion' => $balance['version'], 'annualHours' => 79, 'reason' => 'Synthetic annual correction',
        ]);
        $this->success();
        $confirmation = $payload + ['operationKey' => $this->key(), 'previewToken' => $preview['previewToken'], 'confirm' => true];
        $this->send('/api/requests/'.$approved['id'].'/correct', $confirmation);
        $this->assertError(409);
        self::assertSame(8, $this->detail($approved['id'])['effective']['totalHours']);
        $this->send('/api/requests/'.$approved['id'].'/correction-preview', $payload);
        $preview = $this->success()['preview'];
        $confirmation['previewToken'] = $preview['previewToken'];
        $this->send('/api/requests/'.$approved['id'].'/correct', $confirmation);
        $cancelled = $this->success()['operation'];
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(79, $this->balance('employee')['availableHours']);
        $counts = $this->counts();
        $this->send('/api/requests/'.$approved['id'].'/correct', $confirmation);
        $this->assertSameReceipt($cancelled, $this->success()['operation']);
        self::assertSame($counts, $this->counts());
    }

    public function testCorrectedFutureVersionCanBeManagedByNewTeamWithoutExposingOldTeamHistory(): void
    {
        $this->opening('employee');
        $this->login('employee');
        $approved = $this->approveAs('manager', $this->create('2026-11-09'));
        $em = $this->manager();
        $subject = $em->find(User::class, $this->ids['employee']);
        $beta = $em->find(Team::class, $this->ids['beta']);
        $employment = $em->find(EmploymentType::class, $this->ids['employment']);
        self::assertInstanceOf(User::class, $subject);
        self::assertInstanceOf(Team::class, $beta);
        self::assertInstanceOf(EmploymentType::class, $employment);
        $subject->getAssignments()[0]->closeAt(self::date('2026-11-12'));
        $em->persist(new StaffAssignment($subject, $beta, $employment, TeamRole::EMPLOYEE, self::date('2026-11-12')));
        $em->flush();
        $this->logout();
        $this->clock->modify('2026-11-13 12:00:00 Europe/Warsaw');
        $this->login('manager');
        $historicPayload = $this->correction($approved, '2026-11-09', null, 4);
        $this->send('/api/requests/'.$approved['id'].'/correction-preview', $historicPayload);
        $preview = $this->success()['preview'];
        self::assertNull($preview['impact']['pools']);
        self::assertArrayNotHasKey('balance', $preview);
        self::assertStringNotContainsString('availableHours', json_encode($preview, JSON_THROW_ON_ERROR));
        $this->login('admin');
        $payload = $this->correction($approved, '2026-11-16');
        $this->send('/api/requests/'.$approved['id'].'/correction-preview', $payload);
        $preview = $this->success()['preview'];
        $this->send('/api/requests/'.$approved['id'].'/correct', $payload + ['operationKey' => $this->key(), 'previewToken' => $preview['previewToken'], 'confirm' => true]);
        $corrected = $this->success()['operation'];
        $this->login('managerBeta');
        $details = $this->detail($approved['id']);
        self::assertTrue($details['currentVisible']);
        self::assertSame('2026-11-16', $details['effective']['startDate']);
        self::assertSame([2], array_column($details['history'], 'revision'));
        self::assertStringNotContainsString('2026-11-09', json_encode($details, JSON_THROW_ON_ERROR));
        $this->login('employee');
        $ownerView = $this->detail($approved['id']);
        // The approved corrected version is future leave. Its historical ancestor must not
        // disable normal self-service; only withdrawing a pending proposal checks its base.
        self::assertTrue($ownerView['actions']['change']);
        self::assertTrue($ownerView['actions']['cancel']);
        $this->send('/api/requests/'.$approved['id'].'/change', $this->body('2026-11-17') + ['expectedVersion' => $corrected['version']]);
        $change = $this->success()['operation'];
        $this->login('managerBeta');
        self::assertTrue($this->detail($approved['id'])['actions']['approve']);
        $accepted = $this->approveAs('managerBeta', $change);
        self::assertSame('approved', $accepted['status']);
        self::assertSame('2026-11-17', $this->detail($approved['id'])['effective']['startDate']);
        $this->login('manager');
        $oldView = $this->detail($approved['id']);
        self::assertFalse($oldView['currentVisible']);
        self::assertNull($oldView['effective']);
        self::assertNull($oldView['pending']);
        self::assertSame([1], array_column($oldView['history'], 'revision'));
    }

    /** @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function approveAs(string $user, array $receipt): array
    {
        $this->login($user);
        $this->send('/api/requests/'.$receipt['id'].'/approve', $this->transition($receipt));

        return $this->success()['operation'];
    }

    /** @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function correction(array $receipt, string $start, ?string $end = null, ?int $hours = null): array
    {
        $payload = $this->body($start, $end, $hours);
        unset($payload['operationKey']);

        return $payload + ['expectedVersion' => $receipt['version'], 'action' => 'change', 'reason' => 'Synthetic historical correction reason'];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (['leave_request', 'request_revision', 'request_decision', 'audit_event', 'ledger_entry'] as $table) {
            $counts[$table] = (int) $this->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $counts;
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
