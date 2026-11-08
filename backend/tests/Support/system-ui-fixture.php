<?php

declare(strict_types=1);

// Independent development-only fixture. Credentials travel only to the parent test process.
try {
    $environment = getenv('APP_ENV');
    if ('cli' !== PHP_SAPI || 'true' !== getenv('CI')
        || 'ALLOW_SYNTHETIC_CI' !== getenv('SYSTEM_UI_FIXTURE')
        || !in_array($environment, ['dev', 'test'], true)) {
        throw new RuntimeException('Explicit development/test CI invocation required.');
    }
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
    $kernel = new App\Kernel($environment, true);
    $kernel->boot();
    $registry = $kernel->getContainer()->get('doctrine');
    if (!$registry instanceof Doctrine\Persistence\ManagerRegistry) { throw new RuntimeException('Doctrine required.'); }
    $em = $registry->getManager();
    if (!$em instanceof Doctrine\ORM\EntityManagerInterface) { throw new RuntimeException('ORM required.'); }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->setTime(0, 0);
    $calendar = new App\Service\Calendar\WorkingCalendar($em);
    $workingDay = static function (DateTimeImmutable $from, int $direction = 1) use ($calendar): DateTimeImmutable {
        for ($offset = 0; $offset < 370; ++$offset) {
            $date = $from->modify(sprintf('%+d days', $offset * $direction));
            if (8 === $calendar->hoursOn($date)) { return $date; }
        }
        throw new RuntimeException('No working day available.');
    };
    $start = $workingDay($today->modify('first day of January next year')->modify('+7 days'));
    $end = $workingDay($start->modify('+1 day'));
    $change = $workingDay($end->modify('+7 days'));
    $unpaid = $workingDay($change->modify('+7 days'));
    $past = $workingDay($today->modify('-14 days'), -1);
    $transfer = $today->modify('-7 days');
    $boundaryYear = (int) $today->format('Y') + 1;
    $december = $workingDay(new DateTimeImmutable($boundaryYear.'-12-31', new DateTimeZone('Europe/Warsaw')), -1);
    $january = $workingDay(new DateTimeImmutable(($boundaryYear + 1).'-01-01', new DateTimeZone('Europe/Warsaw')));
    $clock = new Symfony\Component\Clock\MockClock($today->format('Y-m-d').' 12:00:00 Europe/Warsaw');
    $assignments = new App\Security\CurrentAssignmentResolver();
    $access = new App\Security\RequestAccessPolicy($assignments);
    $accounting = new App\Service\Accounting\AccountingService($em, $clock, $assignments, new App\Security\BalanceAccessPolicy($assignments), $access);
    $pastClock = new Symfony\Component\Clock\MockClock($past->format('Y-m-d').' 12:00:00 Europe/Warsaw');
    $pastAccounting = new App\Service\Accounting\AccountingService($em, $pastClock, $assignments, new App\Security\BalanceAccessPolicy($assignments), $access);
    $pastWorkflow = new App\Service\Requests\RequestWorkflowService($em, $pastClock, $assignments, $access, $calendar, $pastAccounting);
    $factory = new Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory([App\Security\SecurityIdentity::class => ['algorithm' => 'auto']]);
    $prefix = 'system_ui_'.bin2hex(random_bytes(8));
    $fixture = $em->wrapInTransaction(static function () use ($em, $today, $start, $end, $change, $unpaid, $past, $transfer, $december, $january, $boundaryYear, $prefix, $accounting, $pastWorkflow, $factory): array {
        $team = new App\Entity\Team($prefix, 'Synthetic system old team');
        $otherTeam = new App\Entity\Team($prefix.'_other', 'Synthetic system new team');
        $employment = new App\Entity\EmploymentType($prefix.'_uop', 'Synthetic system UoP', true);
        foreach ([$team, $otherTeam, $employment] as $entity) { $em->persist($entity); }
        $users = $credentials = [];
        foreach (['employee' => App\Enum\TeamRole::EMPLOYEE, 'manager' => App\Enum\TeamRole::MANAGER,
            'newmanager' => App\Enum\TeamRole::MANAGER, 'administrator' => App\Enum\TeamRole::EMPLOYEE,
            'transferred' => App\Enum\TeamRole::EMPLOYEE] as $name => $role) {
            $password = bin2hex(random_bytes(24));
            $user = new App\Entity\User('Synthetic', ucfirst($name), $prefix.'_'.$name.'@example.test', 'administrator' === $name);
            $user->replacePasswordHash($factory->getPasswordHasher(App\Security\SecurityIdentity::class)->hash($password), false, null);
            new App\Entity\StaffAssignment($user, 'newmanager' === $name ? $otherTeam : $team, $employment, $role,
                $past->modify('first day of January this year'), 'transferred' === $name ? $transfer : null);
            if ('transferred' === $name) { new App\Entity\StaffAssignment($user, $otherTeam, $employment, $role, $transfer); }
            $em->persist($user);
            $users[$name] = $user;
            $credentials[$name] = ['email' => $user->getEmail(), 'password' => $password];
        }
        $types = [];
        foreach (['paid' => true, 'unpaid' => false] as $name => $deducts) {
            $type = new App\Entity\AbsenceType($prefix.'_'.$name);
            $policy = $type->createVersion('Synthetic system '.$name, $deducts, [$employment]);
            $em->persist($type); $em->persist($policy); $types[$name] = $type;
        }
        $em->flush();
        foreach ($users as $name => $subject) { $credentials[$name]['id'] = $subject->getId(); }
        $years = array_values(array_unique([(int) $start->format('Y'), (int) $change->format('Y'), (int) $unpaid->format('Y'), $boundaryYear, $boundaryYear + 1]));
        foreach (['employee', 'transferred'] as $name) {
            $subject = $users[$name];
            $opening = 'transferred' === $name ? $past : $today;
            $openingYear = (int) $opening->format('Y');
            $initial = $accounting->getBalance($subject, $openingYear);
            $accounting->initializeOpening($users['administrator'], $subject, [
                'operationKey' => $prefix.'.opening.'.$name, 'expectedVersion' => $initial['version'],
                'effectiveDate' => $opening->format('Y-01-01'), 'annualHours' => 80, 'currentYearUsedHours' => 0, 'carries' => [],
            ]);
            foreach ($years as $year) {
                if ($year === $openingYear) { continue; }
                $balance = $accounting->getBalance($subject, $year);
                $accounting->setAnnualLimit($users['administrator'], $subject, $year, [
                    'operationKey' => $prefix.'.limit.'.$name.'.'.$year, 'expectedVersion' => $balance['version'],
                    'annualHours' => 80, 'reason' => 'Synthetic system future-year limit',
                ]);
            }
        }
        $history = $pastWorkflow->create($users['transferred'], [
            'operationKey' => $prefix.'.history', 'absenceTypeId' => $types['paid']->getId(), 'mode' => 'hourly',
            'startDate' => $past->format('Y-m-d'), 'endDate' => $past->format('Y-m-d'), 'hours' => 6,
        ]);
        $history = $pastWorkflow->approve($users['manager'], $history['id'], ['operationKey' => $prefix.'.history.approved', 'expectedVersion' => $history['version']]);
        return $credentials + ['paidId' => $types['paid']->getId(), 'unpaidId' => $types['unpaid']->getId(),
            'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'changeDate' => $change->format('Y-m-d'),
            'unpaidDate' => $unpaid->format('Y-m-d'), 'december' => $december->format('Y-m-d'), 'january' => $january->format('Y-m-d'),
            'boundaryYear' => $boundaryYear, 'historicalRequestId' => $history['id'], 'pastYear' => (int) $past->format('Y'),
            'timezone' => 'Europe/Warsaw'];
    });
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Synthetic system UI fixture failed; details suppressed.\n");
    exit(1);
}
