<?php

/** CLI-only synthetic worker for RequestChangesConcurrencyTest; never a public endpoint. */

use App\Entity\User;
use App\Kernel;
use App\Service\CredentialsException;
use App\Service\Accounting\AccountingService;
use App\Service\Requests\RequestWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input) || 'test' !== getenv('APP_ENV')) {
        throw new RuntimeException('Synthetic concurrency worker requires APP_ENV=test and JSON input.');
    }
    /** @var array{database:string,actorId:int,method:string,requestId?:int,subjectId?:int,year?:int,data:array<string,mixed>,ready:string} $input */
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $container->set(ClockInterface::class, new MockClock('2026-11-07 12:00:00 Europe/Warsaw'));
    $em = $container->get('doctrine')->getManager();
    if (!$em instanceof EntityManagerInterface || $em->getConnection()->getDatabase() !== $input['database']
        || 1 !== preg_match('/_test[A-Za-z0-9_]*$/D', $input['database'])) {
        throw new RuntimeException('Worker database differs from the dedicated test database.');
    }
    $em->getConnection()->executeStatement('SET SESSION innodb_lock_wait_timeout = 8');
    $actor = $em->find(User::class, $input['actorId']);
    if (!$actor instanceof User) {
        throw new RuntimeException('Synthetic actor is missing.');
    }
    $service = $container->get(RequestWorkflowService::class);
    if (!$service instanceof RequestWorkflowService) {
        throw new RuntimeException('Request workflow service is unavailable.');
    }
    // Parent holds the real shared database mutex until BOTH workers reach this line.
    if (false === file_put_contents($input['ready'], 'ready', LOCK_EX)) {
        throw new RuntimeException('Cannot signal worker readiness.');
    }
    try {
        $receipt = match ($input['method']) {
            'create' => $service->create($actor, $input['data']),
            'approve' => $service->approve($actor, $input['requestId'] ?? 0, $input['data']),
            'reject' => $service->reject($actor, $input['requestId'] ?? 0, $input['data']),
            'proposeChange' => $service->proposeChange($actor, $input['requestId'] ?? 0, $input['data']),
            'correct' => $service->correct($actor, $input['requestId'] ?? 0, $input['data']),
            'setAnnualLimit' => $container->get(AccountingService::class)->setAnnualLimit($actor,
                $em->find(User::class, $input['subjectId'] ?? 0) ?? throw new RuntimeException('Synthetic subject is missing.'),
                $input['year'] ?? 0, $input['data']),
            default => throw new RuntimeException('Unsupported concurrency operation.'),
        };
        $result = ['ok' => true, 'receipt' => $receipt];
    } catch (CredentialsException $exception) {
        $result = ['ok' => false, 'status' => $exception->httpStatus, 'code' => $exception->errorCode];
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
    $kernel->shutdown();
} catch (Throwable $exception) {
    // No database credentials, payloads or environment are printed.
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
