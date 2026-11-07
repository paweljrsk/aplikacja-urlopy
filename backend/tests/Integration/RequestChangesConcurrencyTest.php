<?php

namespace App\Tests\Integration;

use App\Entity\AbsenceType;
use App\Entity\EmploymentType;
use App\Entity\LeaveRequest;
use App\Entity\StaffAssignment;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Service\Accounting\AccountingService;
use App\Service\CredentialsException;
use App\Service\Requests\RequestWorkflowService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** Independent PHP processes and MySQL connections, not mocked concurrency. */
final class RequestChangesConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AccountingService $accounting;
    private RequestWorkflowService $workflow;
    private MockClock $clock;
    private string $prefix;
    private int $sequence = 0;

    /** @var array<string,int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->prefix = 'rqchgconc_'.bin2hex(random_bytes(6));
        $this->clock = new MockClock('2026-11-07 12:00:00 Europe/Warsaw');
        $this->bootServices();
        $this->seed();
    }

    public function testCompetingApprovalAndRejectionOfAChangeKeepOneEffectiveVersion(): void
    {
        $request = $this->approved('2026-11-09', 2);
        $change = $this->workflow->proposeChange($this->user(), $request['id'],
            $this->createData('2026-11-09', hours: 7) + ['expectedVersion' => $request['version']]);
        self::assertSame(5, $this->balance()['changeReservedHours']);
        $results = $this->race([
            ['method' => 'approve', 'actorId' => $this->ids['admin'], 'requestId' => $request['id'],
                'data' => $this->decision($change, 'accept-change')],
            ['method' => 'reject', 'actorId' => $this->ids['admin'], 'requestId' => $request['id'],
                'data' => $this->decision($change, 'reject-change') + ['reason' => 'Synthetic competing rejection']],
        ]);
        $this->assertSingleWinner($results);
        $hours = $results[0]['ok'] ? 7 : 2;
        self::assertSame(2, $this->countRevisions());
        self::assertSame(2, $this->countDecisions());
        self::assertSame(4, $this->countWorkflowEvents());
        self::assertSame(0, $this->balance()['changeReservedHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame($hours, $this->balance()['approvedFutureHours']);
        self::assertSame(8 - $hours, $this->balance()['availableHours']);
        self::assertSame($hours, $this->occupancy());
    }

    public function testChangeAndNewRequestCannotSpendTheSameRemainingBalance(): void
    {
        $request = $this->approved('2026-11-09', 2);
        $results = $this->race([
            ['method' => 'proposeChange', 'actorId' => $this->ids['employee'], 'requestId' => $request['id'],
                'data' => $this->createData('2026-11-09', hours: 7) + ['expectedVersion' => $request['version']]],
            ['method' => 'create', 'actorId' => $this->ids['employee'],
                'data' => $this->createData('2026-11-10')],
        ]);
        $this->assertSingleWinner($results, 'INSUFFICIENT_BALANCE');
        $changed = $results[0]['ok'];
        self::assertSame($changed ? 1 : 2, $this->countRequests());
        self::assertSame(2, $this->countRevisions());
        self::assertSame(1, $this->countDecisions());
        self::assertSame(3, $this->countWorkflowEvents());
        self::assertSame(2, $this->balance()['approvedFutureHours']);
        self::assertSame($changed ? 5 : 0, $this->balance()['changeReservedHours']);
        self::assertSame($changed ? 0 : 6, $this->balance()['reservedHours']);
        self::assertSame($changed ? 1 : 0, $this->balance()['availableHours']);
    }

    public function testUnpaidChangeAndNewRequestCannotOverbookTheSameDay(): void
    {
        $request = $this->approved('2026-11-09', 3, 'unpaid');
        $results = $this->race([
            ['method' => 'proposeChange', 'actorId' => $this->ids['employee'], 'requestId' => $request['id'],
                'data' => $this->createData('2026-11-09', 'unpaid', 7) + ['expectedVersion' => $request['version']]],
            ['method' => 'create', 'actorId' => $this->ids['employee'],
                'data' => $this->createData('2026-11-09', 'unpaid', 3)],
        ]);
        $this->assertSingleWinner($results, 'DAILY_CAPACITY_EXCEEDED');
        self::assertSame($results[0]['ok'] ? 7 : 6, $this->occupancy());
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        self::assertSame(0, $this->balance()['approvedFutureHours']);
    }

    public function testConcurrentRetriesOfCancellationApprovalReturnHoursOnlyOnce(): void
    {
        $request = $this->approved('2026-11-09', 6);
        $cancel = $this->workflow->proposeCancellation($this->user(), $request['id'], $this->decision($request, 'cancel'));
        self::assertSame(2, $this->balance()['availableHours'], 'Proposing cancellation must not refund hours.');
        $operation = ['method' => 'approve', 'actorId' => $this->ids['admin'], 'requestId' => $request['id'],
            'data' => $this->decision($cancel, 'approve-cancel')];
        $results = $this->race([$operation, $operation]);
        self::assertTrue($results[0]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        self::assertTrue($results[1]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSameReceipt($results[0]['receipt'], $results[1]['receipt']);
        self::assertSame('cancelled', $results[0]['receipt']['status']);
        self::assertSame(2, $this->countRevisions());
        self::assertSame(2, $this->countDecisions());
        self::assertSame(4, $this->countWorkflowEvents());
        self::assertSame(8, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['approvedFutureHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        self::assertSame(0, $this->occupancy());
    }

    public function testConcurrentRetriesOfHistoricalCorrectionUseOnePreviewAndOneAccountingEffect(): void
    {
        $request = $this->historicalApproved();
        $data = $this->correction($request, 5);
        $before = [$this->countRevisions(), $this->countDecisions(), $this->ledgerCount(), $this->countWorkflowEvents()];
        $preview = $this->workflow->previewCorrection($this->user('admin'), $request['id'], $data);
        self::assertFalse($preview['requiresDeficitConfirmation']);
        self::assertSame($before, [$this->countRevisions(), $this->countDecisions(), $this->ledgerCount(), $this->countWorkflowEvents()], 'Preview must leave no rows.');
        $operation = ['method' => 'correct', 'actorId' => $this->ids['admin'], 'requestId' => $request['id'],
            'data' => $data + ['operationKey' => $this->key('correct-retry'), 'previewToken' => $preview['previewToken'], 'confirm' => true]];
        $results = $this->race([$operation, $operation]);
        self::assertTrue($results[0]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        self::assertTrue($results[1]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSameReceipt($results[0]['receipt'], $results[1]['receipt']);
        self::assertSame(1, $this->countRequests());
        self::assertSame(2, $this->countRevisions());
        self::assertSame(2, $this->countDecisions());
        self::assertSame(5, $this->balance()['usedHours']);
        self::assertSame(3, $this->balance()['availableHours']);
        self::assertSame(0, $this->balance()['reservedHours']);
        self::assertSame(0, $this->balance()['changeReservedHours']);
        self::assertSame(5, $this->occupancy());
    }

    public function testPreviewBecomesStaleWhenAnotherProcessChangesBalanceWithoutChangingTheRequest(): void
    {
        $request = $this->historicalApproved();
        $data = $this->correction($request, 5);
        $preview = $this->workflow->previewCorrection($this->user('admin'), $request['id'], $data);
        $results = $this->race([
            ['method' => 'setAnnualLimit', 'actorId' => $this->ids['admin'], 'subjectId' => $this->ids['employee'],
                'year' => 2026, 'data' => ['operationKey' => $this->key('different-limit'),
                    'expectedVersion' => $this->balance()['version'], 'annualHours' => 7, 'reason' => 'Synthetic intervening correction']],
        ], false);
        self::assertTrue($results[0]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        $rowsBeforeConfirm = [$this->countRevisions(), $this->countDecisions(), $this->ledgerCount(), $this->countWorkflowEvents()];
        try {
            $this->workflow->correct($this->user('admin'), $request['id'], $data + [
                'operationKey' => $this->key('stale-preview'), 'previewToken' => $preview['previewToken'], 'confirm' => true,
            ]);
            self::fail('A stale balance preview was accepted even though the request version remained unchanged.');
        } catch (CredentialsException $exception) {
            self::assertSame(409, $exception->httpStatus);
        }
        $this->rebootServices();
        self::assertSame($rowsBeforeConfirm, [$this->countRevisions(), $this->countDecisions(), $this->ledgerCount(), $this->countWorkflowEvents()]);
        self::assertSame(1, $this->countRevisions());
        self::assertSame(3, $this->balance()['usedHours']);
        self::assertSame(7, $this->balance()['annualHours']);
        self::assertSame(4, $this->balance()['availableHours']);
        self::assertSame(3, $this->occupancy());
    }

    /** @return array{id:int, reference:string, version:int, revision:int, status:string} */
    private function approved(string $date, int $hours, string $type = 'paid'): array
    {
        $request = $this->workflow->create($this->user(), $this->createData($date, $type, $hours));
        return $this->workflow->approve($this->user('admin'), $request['id'], $this->decision($request, 'approve-initial'));
    }

    /** @return array{id:int, reference:string, version:int, revision:int, status:string} */
    private function historicalApproved(): array
    {
        $this->clock->modify('2026-11-06 12:00:00 Europe/Warsaw');
        $request = $this->approved('2026-11-06', 3);
        $this->clock->modify('2026-11-07 12:00:00 Europe/Warsaw');
        return $request;
    }

    /** @param array<string,mixed> $receipt
     * @return array{operationKey:string,expectedVersion:int}
     */
    private function decision(array $receipt, string $action): array
    {
        return ['operationKey' => $this->key($action), 'expectedVersion' => $receipt['version']];
    }

    /** @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function correction(array $receipt, int $hours): array
    {
        return ['expectedVersion' => $receipt['version'], 'action' => 'change', 'reason' => 'Synthetic historical correction',
            'absenceTypeId' => $this->ids['paid'], 'startDate' => '2026-11-06', 'endDate' => '2026-11-06',
            'mode' => 'hourly', 'hours' => $hours];
    }

    /**
     * Hold the real mutex, prepare independent PHP workers, then release them together.
     * All resources are bounded and cleaned even if an assertion or child fails.
     *
     * @param list<array<string,mixed>> $operations
     * @return list<array<string,mixed>>
     */
    private function race(array $operations, bool $clearParent = true): array
    {
        $directory = sys_get_temp_dir().'/'.$this->prefix.'-'.++$this->sequence;
        self::assertTrue(mkdir($directory, 0700));
        $blocker = DriverManager::getConnection($this->em->getConnection()->getParams());
        $workers = [];
        $blocker->beginTransaction();
        try {
            self::assertSame(1, (int) $blocker->fetchOne('SELECT id FROM administration_lock WHERE id=1 FOR UPDATE'));
            foreach ($operations as $index => $operation) {
                $ready = $directory.'/'.$index.'.ready';
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-d', 'date.timezone=UTC', dirname(__DIR__).'/Support/request-changes-concurrency-worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
                if (!is_resource($process)) {
                    throw new \RuntimeException('Could not start the synthetic concurrency worker.');
                }
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'ready' => $ready];
                fwrite($pipes[0], json_encode($operation + ['database' => $this->em->getConnection()->getDatabase(), 'ready' => $ready], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
            }
            $deadline = microtime(true) + 15;
            do {
                $readyCount = 0;
                foreach ($workers as $worker) {
                    if (is_file($worker['ready'])) {
                        ++$readyCount;
                    }
                    $state = proc_get_status($worker['process']);
                    if (!$state['running']) {
                        self::fail('Worker exited before the mutex was released: '.stream_get_contents($worker['stderr']).stream_get_contents($worker['stdout']));
                    }
                }
                if ($readyCount === count($workers)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame(count($workers), $readyCount, 'Every independent worker must reach the workflow call.');
            // Both calls must be waiting, not already completed before this transaction commits.
            usleep(100000);
            foreach ($workers as $worker) {
                self::assertTrue(proc_get_status($worker['process'])['running'], 'A mutation bypassed the shared administration mutex.');
            }
            $blocker->commit();

            $results = [];
            foreach ($workers as $worker) {
                $out = $error = '';
                $deadline = microtime(true) + 15;
                do {
                    $out .= stream_get_contents($worker['stdout']);
                    $error .= stream_get_contents($worker['stderr']);
                    $state = proc_get_status($worker['process']);
                    if (!$state['running']) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                self::assertFalse($state['running'], 'Concurrency worker timed out.');
                $out .= stream_get_contents($worker['stdout']);
                $error .= stream_get_contents($worker['stderr']);
                self::assertSame(0, $state['exitcode'], $error);
                self::assertSame('', trim($error), 'Worker must not emit warnings.');
                $result = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertArrayHasKey('ok', $result);
                $results[] = $result;
            }
            if ($clearParent) {
                $this->em->clear();
            }
            return $results;
        } finally {
            if ($blocker->isTransactionActive()) {
                $blocker->rollBack();
            }
            $blocker->close();
            foreach ($workers as $worker) {
                if (proc_get_status($worker['process'])['running']) {
                    proc_terminate($worker['process'], 9);
                }
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                proc_close($worker['process']);
                if (is_file($worker['ready'])) {
                    unlink($worker['ready']);
                }
            }
            rmdir($directory);
        }
    }

    /** @param list<array<string,mixed>> $results
     * @return array<string,mixed>
     */
    private function assertSingleWinner(array $results, ?string $failureCode = null): array
    {
        $winners = array_values(array_filter($results, static fn (array $result): bool => true === $result['ok']));
        $losers = array_values(array_filter($results, static fn (array $result): bool => false === $result['ok']));
        self::assertCount(1, $winners, json_encode($results, JSON_THROW_ON_ERROR));
        self::assertCount(1, $losers, json_encode($results, JSON_THROW_ON_ERROR));
        if (null === $failureCode) {
            self::assertSame(409, $losers[0]['status']);
        } else {
            self::assertSame($failureCode, $losers[0]['code']);
        }
        return $winners[0];
    }

    /** JSON object key order is not significant; values and their types must remain identical.
     * @param array<string,mixed> $first
     * @param array<string,mixed> $second
     */
    private function assertSameReceipt(array $first, array $second): void
    {
        ksort($first);
        ksort($second);
        self::assertSame($first, $second);
    }

    private function bootServices(): void
    {
        self::bootKernel();
        self::getContainer()->set(ClockInterface::class, $this->clock);
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $database = $em->getConnection()->getDatabase();
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/_test[A-Za-z0-9_]*$/D', $database);
        self::assertStringContainsString('mysql', $em->getConnection()->getParams()['driver'] ?? '');
        $service = self::getContainer()->get(AccountingService::class);
        self::assertInstanceOf(AccountingService::class, $service);
        $this->accounting = $service;
        $workflow = self::getContainer()->get(RequestWorkflowService::class);
        self::assertInstanceOf(RequestWorkflowService::class, $workflow);
        $this->workflow = $workflow;
    }

    private function rebootServices(): void
    {
        self::ensureKernelShutdown();
        $this->bootServices();
    }

    private function seed(): void
    {
        $team = new Team($this->prefix.'_team', 'Synthetic concurrency team');
        $employment = new EmploymentType($this->prefix.'_employment', 'Synthetic employment', true);
        $admin = new User('Synthetic', 'Administrator', $this->prefix.'_admin@example.test', true);
        $employee = new User('Synthetic', 'Employee', $this->prefix.'_employee@example.test');
        $assignment = new StaffAssignment($employee, $team, $employment, TeamRole::EMPLOYEE, new \DateTimeImmutable('2025-01-01 Europe/Warsaw'));
        $paid = new AbsenceType($this->prefix.'_paid');
        $paidPolicy = $paid->createVersion('Synthetic paid leave', true, [$employment]);
        $unpaid = new AbsenceType($this->prefix.'_unpaid');
        $unpaidPolicy = $unpaid->createVersion('Synthetic non-deducting absence', false, [$employment]);
        foreach ([$team, $employment, $admin, $employee, $assignment, $paid, $paidPolicy, $unpaid, $unpaidPolicy] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        foreach (['employee' => $employee, 'admin' => $admin, 'paid' => $paid, 'unpaid' => $unpaid] as $name => $entity) {
            self::assertNotNull($entity->getId());
            $this->ids[$name] = $entity->getId();
        }
        $this->accounting->initializeOpening($admin, $employee, [
            'operationKey' => $this->key('opening'), 'expectedVersion' => $this->balance()['version'],
            'effectiveDate' => '2026-01-01', 'annualHours' => 8, 'currentYearUsedHours' => 0, 'carries' => [],
        ]);
    }

    /** @return array<string,mixed> */
    private function createData(string $date, string $type = 'paid', int $hours = 6): array
    {
        return ['operationKey' => $this->key('submit'), 'absenceTypeId' => $this->ids[$type],
            'startDate' => $date, 'endDate' => $date, 'mode' => 'hourly', 'hours' => $hours];
    }

    private function user(string $name = 'employee'): User
    {
        $user = $this->em->find(User::class, $this->ids[$name]);
        self::assertInstanceOf(User::class, $user);
        return $user;
    }

    /** @return array<string,mixed> */
    private function balance(): array
    {
        return $this->accounting->getBalance($this->user(), 2026);
    }

    private function countRequests(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM leave_request WHERE user_id=?', [$this->ids['employee']]);
    }

    private function countRevisions(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM request_revision r JOIN leave_request q ON q.id=r.request_id WHERE q.user_id=?', [$this->ids['employee']]);
    }

    private function countDecisions(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM request_decision d JOIN request_revision r ON r.id=d.revision_id JOIN leave_request q ON q.id=r.request_id WHERE q.user_id=?', [$this->ids['employee']]);
    }

    private function countWorkflowEvents(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_event WHERE subject_id=? AND target_type='request' AND action LIKE 'request.%'", [$this->ids['employee']]);
    }

    private function ledgerCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ledger_entry e JOIN hour_pool p ON p.id=e.pool_id WHERE p.user_id=?', [$this->ids['employee']]);
    }

    private function occupancy(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(SUM(hours),0) FROM daily_occupancy WHERE user_id=?', [$this->ids['employee']]);
    }

    private function key(string $operation): string
    {
        return $this->prefix.':'.$operation.':'.++$this->sequence;
    }
}
