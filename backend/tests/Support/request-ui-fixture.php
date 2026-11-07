<?php

declare(strict_types=1);

// Explicit disposable-CI fixture, never an application command or HTTP endpoint.
// The caller captures stdout in memory: the JSON contains fresh synthetic passwords.
try {
    $environment = getenv('APP_ENV');
    if ('cli' !== PHP_SAPI || 'true' !== getenv('CI')
        || 'ALLOW_SYNTHETIC_CI' !== getenv('REQUEST_UI_FIXTURE')
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
    $clock = new Symfony\Component\Clock\NativeClock('Europe/Warsaw');
    $today = $clock->now()->setTime(0, 0);
    $calendar = new App\Service\Calendar\WorkingCalendar($em);
    $workingDay = static function (DateTimeImmutable $from) use ($calendar): DateTimeImmutable {
        for ($offset = 0; $offset < 370; ++$offset) {
            $date = $from->modify('+'.$offset.' days');
            if (8 === $calendar->hoursOn($date)) {
                return $date;
            }
        }
        throw new RuntimeException('No future working day available.');
    };
    $date = $workingDay($today->modify('+7 days'));
    $changeDate = $workingDay($date->modify('+7 days'));
    // Keep this UI example within one leave year; dedicated backend tests cover year boundaries.
    if ($date->format('Y') !== $changeDate->format('Y')) {
        $date = $changeDate;
        $changeDate = $workingDay($date->modify('+7 days'));
    }
    if ($date->format('Y') !== $changeDate->format('Y')) {
        throw new RuntimeException('A same-year pair of future working dates is required.');
    }
    $prefix = 'request_ui_'.bin2hex(random_bytes(8));
    $assignments = new App\Security\CurrentAssignmentResolver();
    $accounting = new App\Service\Accounting\AccountingService(
        $em, $clock, $assignments, new App\Security\BalanceAccessPolicy($assignments),
        new App\Security\RequestAccessPolicy($assignments),
    );
    $factory = new Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory([
        App\Security\SecurityIdentity::class => ['algorithm' => 'auto'],
    ]);
    $fixture = $em->wrapInTransaction(static function () use ($em, $today, $date, $changeDate, $prefix, $accounting, $factory): array {
        $team = new App\Entity\Team($prefix, 'Synthetic request UI team');
        $employment = new App\Entity\EmploymentType($prefix.'_uop', 'Synthetic request UI UoP', true);
        $em->persist($team);
        $em->persist($employment);
        $users = $credentials = [];
        foreach (['employee' => App\Enum\TeamRole::EMPLOYEE, 'manager' => App\Enum\TeamRole::MANAGER] as $name => $role) {
            $password = bin2hex(random_bytes(24));
            $user = new App\Entity\User('Synthetic', ucfirst($name), $prefix.'_'.$name.'@example.test');
            $user->replacePasswordHash($factory->getPasswordHasher(App\Security\SecurityIdentity::class)->hash($password), false, null);
            new App\Entity\StaffAssignment($user, $team, $employment, $role, $today->modify('first day of January this year'));
            $em->persist($user);
            $users[$name] = $user;
            $credentials[$name] = ['email' => $user->getEmail(), 'password' => $password];
        }
        $type = new App\Entity\AbsenceType($prefix.'_paid');
        $policy = $type->createVersion('Synthetic płatny urlop', true, [$employment]);
        $em->persist($type);
        $em->persist($policy);
        $em->flush();
        $currentYear = (int) $today->format('Y');
        $leaveYear = (int) $date->format('Y');
        $initial = $accounting->getBalance($users['employee'], $currentYear);
        $accounting->initializeOpening($users['manager'], $users['employee'], [
            'operationKey' => $prefix.'.opening', 'expectedVersion' => $initial['version'],
            'effectiveDate' => $today->format('Y-01-01'), 'annualHours' => 80,
            'currentYearUsedHours' => 0, 'carries' => [],
        ]);
        if ($leaveYear !== $currentYear) {
            $next = $accounting->getBalance($users['employee'], $leaveYear);
            $accounting->setAnnualLimit($users['manager'], $users['employee'], $leaveYear, [
                'operationKey' => $prefix.'.limit', 'expectedVersion' => $next['version'],
                'annualHours' => 80, 'reason' => 'Synthetic UI test future-year limit',
            ]);
        }
        foreach ($users as $name => $user) {
            $credentials[$name]['id'] = $user->getId();
        }
        return $credentials + ['absenceTypeId' => $type->getId(), 'date' => $date->format('Y-m-d'),
            'changeDate' => $changeDate->format('Y-m-d'), 'year' => $leaveYear, 'timezone' => 'Europe/Warsaw'];
    });
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // Never print exception chains, fixture data, hashes or credentials.
    fwrite(STDERR, "Synthetic request UI fixture failed; details suppressed.\n");
    exit(1);
}
