<?php

declare(strict_types=1);

// Disposable CI accounts only. The caller keeps generated credentials in memory.
try {
    $environment = getenv('APP_ENV');
    if ('cli' !== PHP_SAPI || 'true' !== getenv('CI')
        || 'ALLOW_SYNTHETIC_CI' !== getenv('MANAGED_REQUEST_UI_FIXTURE')
        || !in_array($environment, ['dev', 'test'], true)) {
        throw new RuntimeException('Explicit development/test CI invocation required.');
    }
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
    $kernel = new App\Kernel($environment, true);
    $kernel->boot();
    $registry = $kernel->getContainer()->get('doctrine');
    if (!$registry instanceof Doctrine\Persistence\ManagerRegistry) {
        throw new RuntimeException('Doctrine required.');
    }
    $em = $registry->getManager();
    if (!$em instanceof Doctrine\ORM\EntityManagerInterface) {
        throw new RuntimeException('ORM required.');
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->setTime(0, 0);
    $calendar = new App\Service\Calendar\WorkingCalendar($em);
    $workingDay = static function (DateTimeImmutable $from, int $direction) use ($calendar): DateTimeImmutable {
        for ($offset = 0; $offset < 370; ++$offset) {
            $date = $from->modify(sprintf('%+d days', $offset * $direction));
            if (8 === $calendar->hoursOn($date)) {
                return $date;
            }
        }
        throw new RuntimeException('No working day available.');
    };
    $pastDate = $workingDay($today->modify('-7 days'), -1);
    $date = $workingDay($today->modify('+7 days'), 1);
    $clock = new Symfony\Component\Clock\MockClock($pastDate->format('Y-m-d').' 12:00:00 Europe/Warsaw');
    $assignments = new App\Security\CurrentAssignmentResolver();
    $access = new App\Security\RequestAccessPolicy($assignments);
    $accounting = new App\Service\Accounting\AccountingService(
        $em, $clock, $assignments, new App\Security\BalanceAccessPolicy($assignments), $access,
    );
    $workflow = new App\Service\Requests\RequestWorkflowService($em, $clock, $assignments, $access, $calendar, $accounting);
    $factory = new Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory([
        App\Security\SecurityIdentity::class => ['algorithm' => 'auto'],
    ]);
    $prefix = 'managed_ui_'.bin2hex(random_bytes(8));
    $fixture = $em->wrapInTransaction(static function () use ($em, $pastDate, $date, $prefix, $accounting, $workflow, $factory): array {
        $team = new App\Entity\Team($prefix, 'Synthetic managed UI team');
        $otherTeam = new App\Entity\Team($prefix.'_other', 'Synthetic managed UI other team');
        $employment = new App\Entity\EmploymentType($prefix.'_uop', 'Synthetic managed UI UoP', true);
        $em->persist($team);
        $em->persist($otherTeam);
        $em->persist($employment);
        $users = $credentials = [];
        foreach (['employee' => App\Enum\TeamRole::EMPLOYEE, 'manager' => App\Enum\TeamRole::MANAGER,
            'deputy' => App\Enum\TeamRole::DEPUTY, 'administrator' => App\Enum\TeamRole::EMPLOYEE,
            'otheremployee' => App\Enum\TeamRole::EMPLOYEE] as $name => $role) {
            $password = bin2hex(random_bytes(24));
            $user = new App\Entity\User('Synthetic', ucfirst($name), $prefix.'_'.$name.'@example.test', 'administrator' === $name);
            $user->replacePasswordHash($factory->getPasswordHasher(App\Security\SecurityIdentity::class)->hash($password), false, null);
            new App\Entity\StaffAssignment($user, 'otheremployee' === $name ? $otherTeam : $team, $employment, $role, $pastDate->modify('first day of January this year'));
            $em->persist($user);
            $users[$name] = $user;
            $credentials[$name] = ['email' => $user->getEmail(), 'password' => $password];
        }
        $type = new App\Entity\AbsenceType($prefix.'_paid');
        $policy = $type->createVersion('Synthetic płatny urlop przełożonego', true, [$employment]);
        $em->persist($type);
        $em->persist($policy);
        $em->flush();
        $pastYear = (int) $pastDate->format('Y');
        $year = (int) $date->format('Y');
        foreach ($users as $name => $subject) {
            $initial = $accounting->getBalance($subject, $pastYear);
            $accounting->initializeOpening($users['administrator'], $subject, [
                'operationKey' => $prefix.'.opening.'.$name, 'expectedVersion' => $initial['version'],
                'effectiveDate' => $pastDate->format('Y-01-01'), 'annualHours' => 80,
                'currentYearUsedHours' => 0, 'carries' => [],
            ]);
            if ($year !== $pastYear) {
                $next = $accounting->getBalance($subject, $year);
                $accounting->setAnnualLimit($users['administrator'], $subject, $year, [
                    'operationKey' => $prefix.'.limit.'.$name, 'expectedVersion' => $next['version'],
                    'annualHours' => 80, 'reason' => 'Synthetic managed UI future-year limit',
                ]);
            }
            $credentials[$name]['id'] = $subject->getId();
        }
        $application = static fn (string $key, DateTimeImmutable $day, int $hours): array => [
            'operationKey' => $prefix.'.'.$key, 'absenceTypeId' => $type->getId(),
            'mode' => 'hourly', 'startDate' => $day->format('Y-m-d'), 'endDate' => $day->format('Y-m-d'), 'hours' => $hours,
        ];
        $history = $workflow->create($users['employee'], $application('history', $pastDate, 6));
        $history = $workflow->approve($users['manager'], $history['id'], [
            'operationKey' => $prefix.'.history.approved', 'expectedVersion' => $history['version'],
        ]);
        $requests = [];
        foreach (['manager' => 3, 'employee' => 5, 'deputy' => 2, 'otheremployee' => 4] as $name => $hours) {
            $created = $workflow->create($users[$name], $application('future.'.$name, $date, $hours));
            $requests[$name] = $created['id'];
        }
        return $credentials + ['absenceTypeId' => $type->getId(), 'date' => $date->format('Y-m-d'),
            'pastDate' => $pastDate->format('Y-m-d'), 'year' => $year, 'pastYear' => $pastYear,
            'historicalRequestId' => $history['id'], 'requests' => $requests, 'timezone' => 'Europe/Warsaw'];
    });
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Synthetic managed request UI fixture failed; details suppressed.\n");
    exit(1);
}
