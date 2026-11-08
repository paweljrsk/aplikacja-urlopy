<?php

declare(strict_types=1);

// Local disposable CI only. Credentials are returned to the caller's memory, never to logs/artifacts.
$phase = 'guard';
try {
    $environment = getenv('APP_ENV');
    if ('cli' !== PHP_SAPI || 'true' !== getenv('CI')
        || 'ALLOW_SYNTHETIC_CI' !== getenv('PERFORMANCE_FIXTURE')
        || !in_array($environment, ['dev', 'test'], true)) {
        throw new RuntimeException('Explicit development/test CI invocation required.');
    }
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
    $kernel = new App\Kernel($environment, false);
    $kernel->boot();
    $registry = $kernel->getContainer()->get('doctrine');
    if (!$registry instanceof Doctrine\Persistence\ManagerRegistry) { throw new RuntimeException('Doctrine required.'); }
    $em = $registry->getManager();
    if (!$em instanceof Doctrine\ORM\EntityManagerInterface) { throw new RuntimeException('ORM required.'); }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->setTime(0, 0);
    $calendar = new App\Service\Calendar\WorkingCalendar($em);
    $days = [];
    for ($offset = 1; $offset < 90 && count($days) < 20; ++$offset) {
        $day = $today->modify('+'.$offset.' days');
        if (8 === $calendar->hoursOn($day)) { $days[] = $day; }
    }
    if (20 !== count($days)) { throw new RuntimeException('Twenty working dates required.'); }
    $clock = new Symfony\Component\Clock\MockClock($today->format('Y-m-d').' 12:00:00 Europe/Warsaw');
    $assignments = new App\Security\CurrentAssignmentResolver();
    $access = new App\Security\RequestAccessPolicy($assignments);
    $accounting = new App\Service\Accounting\AccountingService($em, $clock, $assignments, new App\Security\BalanceAccessPolicy($assignments), $access);
    $workflow = new App\Service\Requests\RequestWorkflowService($em, $clock, $assignments, $access, $calendar, $accounting);
    $prefix = 'performance_'.bin2hex(random_bytes(8));
    $password = bin2hex(random_bytes(24));
    $factory = new Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory([App\Security\SecurityIdentity::class => ['algorithm' => 'auto']]);
    $hash = $factory->getPasswordHasher(App\Security\SecurityIdentity::class)->hash($password);
    $phase = 'people';
    $data = $em->wrapInTransaction(static function () use ($em, $today, $prefix, $hash): array {
        $employment = new App\Entity\EmploymentType($prefix.'_uop', 'Synthetic performance UoP', true);
        $em->persist($employment);
        $teams = [];
        for ($index = 0; $index < 5; ++$index) {
            $teams[$index] = new App\Entity\Team($prefix.'_'.$index, 'Synthetic performance team '.$index);
            $em->persist($teams[$index]);
        }
        $users = [];
        for ($index = 0; $index < 100; ++$index) {
            $user = new App\Entity\User('Synthetic', sprintf('Performance %03d', $index), $prefix.'_'.$index.'@example.test', 20 === $index);
            // Only three isolated accounts need to authenticate during the measurement.
            if (in_array($index, [0, 1, 20], true)) { $user->replacePasswordHash($hash, false, null); }
            new App\Entity\StaffAssignment($user, $teams[intdiv($index, 20)], $employment,
                0 === $index % 20 ? App\Enum\TeamRole::MANAGER : App\Enum\TeamRole::EMPLOYEE, $today->modify('first day of January this year'));
            $em->persist($user);
            $users[] = $user;
        }
        $type = new App\Entity\AbsenceType($prefix.'_paid');
        $policy = $type->createVersion('Synthetic performance paid leave', true, [$employment]);
        $em->persist($type); $em->persist($policy); $em->flush();
        return ['ids' => array_map(static fn (App\Entity\User $user): ?int => $user->getId(), $users),
            'managerEmail' => $users[0]->getEmail(), 'employeeEmail' => $users[1]->getEmail(),
            'teamId' => $teams[0]->getId(), 'typeId' => $type->getId()];
    });
    $phase = 'limits and requests';
    $year = (int) $today->format('Y');
    $lastYear = (int) $days[19]->format('Y');
    foreach ($data['ids'] as $index => $userId) {
        $subject = $em->find(App\Entity\User::class, $userId) ?? throw new RuntimeException('Missing person.');
        $admin = $em->find(App\Entity\User::class, $data['ids'][20]) ?? throw new RuntimeException('Missing administrator.');
        $balance = $accounting->getBalance($subject, $year);
        $accounting->initializeOpening($admin, $subject, ['operationKey' => $prefix.'.opening.'.$index,
            'expectedVersion' => $balance['version'], 'effectiveDate' => $today->format('Y-01-01'),
            'annualHours' => 208, 'currentYearUsedHours' => 0, 'carries' => []]);
        if ($lastYear !== $year) {
            $balance = $accounting->getBalance($subject, $lastYear);
            $accounting->setAnnualLimit($admin, $subject, $lastYear, ['operationKey' => $prefix.'.limit.'.$index,
                'expectedVersion' => $balance['version'], 'annualHours' => 208, 'reason' => 'Synthetic performance next-year limit']);
        }
        foreach ($days as $number => $day) {
            $created = $workflow->create($subject, ['operationKey' => $prefix.'.create.'.$index.'.'.$number,
                'absenceTypeId' => $data['typeId'], 'mode' => 'hourly', 'startDate' => $day->format('Y-m-d'),
                'endDate' => $day->format('Y-m-d'), 'hours' => 1]);
            if (0 === $number % 2) {
                $manager = $em->find(App\Entity\User::class, $data['ids'][intdiv($index, 20) * 20]) ?? throw new RuntimeException('Missing manager.');
                $workflow->approve($manager, $created['id'], ['operationKey' => $prefix.'.approve.'.$index.'.'.$number, 'expectedVersion' => $created['version']]);
            }
        }
        $em->clear();
    }
    $phase = 'counts';
    $connection = $em->getConnection();
    $counts = ['people' => 100, 'teams' => 5, 'requests' => 2000, 'requestsPerPerson' => 20,
        'approved' => 1000, 'pending' => 1000, 'managerScopePeople' => 20];
    $actual = (int) $connection->fetchOne('SELECT COUNT(*) FROM leave_request r JOIN app_user u ON u.id = r.user_id WHERE u.email LIKE ?', [$prefix.'_%@example.test']);
    if (2000 !== $actual) { throw new RuntimeException('Unexpected request count.'); }
    echo json_encode(['manager' => ['id' => $data['ids'][0], 'email' => $data['managerEmail'], 'password' => $password],
        'employee' => ['id' => $data['ids'][1], 'email' => $data['employeeEmail'], 'password' => $password],
        'teamId' => $data['teamId'], 'year' => $year, 'from' => $today->format('Y-m-d'), 'to' => $today->modify('+13 days')->format('Y-m-d'),
        'upcomingTo' => $today->modify('+60 days')->format('Y-m-d'), 'dataset' => $counts,
        'databaseTotals' => ['people' => (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user'), 'requests' => (int) $connection->fetchOne('SELECT COUNT(*) FROM leave_request')],
        'runtime' => ['php' => PHP_VERSION, 'mysql' => (string) $connection->fetchOne('SELECT VERSION()'), 'appEnv' => $environment]], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Synthetic performance fixture failed during '.$phase."; sensitive details suppressed.\n");
    exit(1);
}
