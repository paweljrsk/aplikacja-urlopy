<?php

namespace App\Tests\Support;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\Kernel;
use App\Security\SecurityIdentity;
use App\Service\CredentialsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

/**
 * Real-MySQL CLI acceptance, run once on the migrated test database before PHPUnit.
 * Never resets schema or data. Refuses an already initialized administrator database.
 * Captured command output and generated passwords remain in memory and are never printed.
 */
final class CredentialsCliCheck
{
    private const ADMIN_EMAIL = 'cli.bootstrap@example.test';
    private const REPEATED_EMAIL = 'cli.repeated-bootstrap@example.test';
    private const EMPLOYEE_EMAIL = 'cli.employee@example.test';

    private ?Kernel $kernel = null;
    private EntityManagerInterface $em;
    private string $phase = 'environment';

    public function run(): void
    {
        $this->ensure('cli' === \PHP_SAPI && 'test' === ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null), 'The check requires the test CLI environment.');
        $this->reboot();
        $this->ensure(null === $this->em->getRepository(AuditEvent::class)->findOneBy(['operationKey' => CredentialsService::BOOTSTRAP_KEY]), 'The bootstrap marker already exists; refusing to overwrite test data.');
        $this->ensure(null === $this->em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]), 'The acceptance account already exists.');
        foreach ($this->em->getRepository(User::class)->findBy(['isAdministrator' => true]) as $administrator) {
            $this->ensure(null === $administrator->getPasswordHash(), 'An initialized administrator already exists.');
        }

        $this->phase = 'interactive bootstrap';
        $beforeBootstrap = time();
        $bootstrap = $this->command('app:admin:bootstrap', [self::ADMIN_EMAIL, 'Synthetic', 'CLI Administrator']);
        $afterBootstrap = time();
        $this->ensure(Command::SUCCESS === $bootstrap['status'], 'Bootstrap command failed.');
        $firstPassword = $this->extractPassword($bootstrap['output']);
        unset($bootstrap);
        $this->reboot();
        $user = $this->administrator();
        $id = $user->getId();
        $this->ensure(null !== $id, 'Bootstrap did not persist an identifier.');
        $initialVersion = $user->getCredentialVersion();
        $initialHash = $user->getPasswordHash();
        $this->checkTemporaryCredential($user, $firstPassword, $beforeBootstrap, $afterBootstrap);
        $this->ensure(2 === $initialVersion, 'Bootstrap must advance the initial credential revision.');
        $this->ensure(1 === $this->countAudit('security.admin_bootstrapped', $user), 'Bootstrap must create one audit event.');

        $this->phase = 'repeated bootstrap refusal';
        $repeated = $this->command('app:admin:bootstrap', [self::REPEATED_EMAIL, 'Synthetic', 'Rejected Administrator']);
        $this->ensure(Command::FAILURE === $repeated['status'], 'A second bootstrap was accepted.');
        $this->ensure(!str_contains($repeated['output'], 'Hasło tymczasowe (wyświetlane tylko teraz):'), 'A rejected bootstrap disclosed a generated password.');
        unset($repeated);
        $this->reboot();
        $user = $this->administrator();
        $this->ensure($id === $user->getId() && $initialVersion === $user->getCredentialVersion(), 'A rejected bootstrap changed the first administrator.');
        $this->ensure(null === $this->em->getRepository(User::class)->findOneBy(['email' => self::REPEATED_EMAIL]), 'A rejected bootstrap left a partial account.');
        $this->ensure(1 === $this->countAudit('security.admin_bootstrapped', $user), 'A rejected bootstrap duplicated audit.');

        $this->phase = 'interactive recovery';
        $beforeRecovery = time();
        $recovery = $this->command('app:admin:recover', [self::ADMIN_EMAIL, 'yes']);
        $afterRecovery = time();
        $this->ensure(Command::SUCCESS === $recovery['status'], 'Recovery command failed.');
        $secondPassword = $this->extractPassword($recovery['output']);
        unset($recovery);
        $this->reboot();
        $user = $this->administrator();
        $this->ensure($id === $user->getId(), 'Recovery replaced the administrator identity.');
        $this->ensure($initialVersion + 1 === $user->getCredentialVersion(), 'Recovery did not revoke previous sessions.');
        $this->checkTemporaryCredential($user, $secondPassword, $beforeRecovery, $afterRecovery);
        $this->ensure($firstPassword !== $secondPassword, 'Recovery reused a temporary password.');
        $this->ensure(!$this->hasherFactory()->getPasswordHasher(SecurityIdentity::class)->verify((string) $user->getPasswordHash(), $firstPassword), 'Recovery left the old password usable.');
        $this->ensure(1 === $this->countAudit('security.admin_recovered', $user), 'Recovery must create one audit event.');
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['subject' => $user]);
        $this->ensure(2 === \count($events), 'Unexpected credential audit count.');
        $secondHash = $user->getPasswordHash();
        foreach ($events as $event) {
            $this->ensure(null === $event->getActor(), 'Console operations must have no fabricated user actor.');
            $audit = json_encode([$event->getBeforeData(), $event->getAfterData()], JSON_THROW_ON_ERROR);
            foreach ([$firstPassword, $secondPassword, $initialHash, $secondHash] as $secret) {
                $this->ensure(\is_string($secret) && '' !== $secret && !str_contains($audit, $secret), 'A secret leaked into the audit payload.');
            }
        }
        unset($firstPassword, $secondPassword, $initialHash, $secondHash);

        $this->phase = 'recovery confirmation refusal';
        $declined = $this->command('app:admin:recover', [self::ADMIN_EMAIL, '']);
        $this->ensure(Command::FAILURE === $declined['status'], 'Recovery proceeded without explicit confirmation.');
        $this->ensure(!str_contains($declined['output'], 'Hasło tymczasowe (wyświetlane tylko teraz):'), 'A declined recovery disclosed a password.');
        unset($declined);
        $this->reboot();
        $user = $this->administrator();
        $this->ensure($initialVersion + 1 === $user->getCredentialVersion() && 1 === $this->countAudit('security.admin_recovered', $user), 'Declining recovery changed credentials or audit.');

        $this->phase = 'non-administrator recovery refusal';
        $employee = new User('Synthetic', 'CLI Employee', self::EMPLOYEE_EMAIL);
        $this->em->persist($employee);
        $this->em->flush();
        $refused = $this->command('app:admin:recover', [self::EMPLOYEE_EMAIL, 'yes']);
        $this->ensure(Command::FAILURE === $refused['status'], 'Recovery promoted a non-administrator.');
        $this->ensure(!str_contains($refused['output'], 'Hasło tymczasowe (wyświetlane tylko teraz):'), 'Refused recovery disclosed a password.');
        unset($refused);
        $this->reboot();
        $employee = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMPLOYEE_EMAIL]);
        $this->ensure($employee instanceof User, 'The synthetic employee disappeared.');
        $this->ensure(!$employee->isAdministrator() && null === $employee->getPasswordHash() && 1 === $employee->getCredentialVersion(), 'Refused recovery changed the employee.');
        $this->ensure(0 === $this->countAudit('security.admin_recovered', $employee), 'Refused recovery wrote a success audit.');

        $this->kernel?->shutdown();
        echo "PASS: MySQL interactive bootstrap, duplicate refusal, recovery, revocation, expiry and secret-free audit.\n";
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    private function reboot(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $registry = $this->kernel->getContainer()->get('doctrine');
        $this->ensure($registry instanceof ManagerRegistry, 'Doctrine registry is unavailable.');
        $manager = $registry->getManager();
        $this->ensure($manager instanceof EntityManagerInterface, 'Doctrine ORM is unavailable.');
        $this->em = $manager;
        $database = $manager->getConnection()->getDatabase();
        $this->ensure(\is_string($database) && 1 === preg_match('/_test[A-Za-z0-9_]*$/D', $database), 'Refusing to write outside the dedicated test database.');
        $this->ensure(str_contains($manager->getConnection()->getParams()['driver'] ?? '', 'mysql'), 'The check requires MySQL.');
    }

    /**
     * @param list<string> $inputs
     *
     * @return array{status: int, output: string}
     */
    private function command(string $name, array $inputs): array
    {
        $this->reboot();
        $this->ensure(null !== $this->kernel, 'Kernel is unavailable.');
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find($name));
        $tester->setInputs($inputs);

        return ['status' => $tester->execute([], ['interactive' => true, 'decorated' => false]), 'output' => $tester->getDisplay()];
    }

    private function administrator(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        $this->ensure($user instanceof User && $user->isActive() && $user->isAdministrator(), 'The initialized administrator is unavailable.');

        return $user;
    }

    private function hasherFactory(): PasswordHasherFactoryInterface
    {
        $this->ensure(null !== $this->kernel, 'Kernel is unavailable.');
        $factory = $this->kernel->getContainer()->get('test.service_container')->get(PasswordHasherFactoryInterface::class);
        $this->ensure($factory instanceof PasswordHasherFactoryInterface, 'Password hasher factory is unavailable.');

        return $factory;
    }

    private function extractPassword(#[\SensitiveParameter] string $output): string
    {
        $found = preg_match_all('/^Hasło tymczasowe \(wyświetlane tylko teraz\): ([A-Za-z0-9_-]{32})$/m', $output, $matches);
        $this->ensure(1 === $found, 'The command must emit exactly one random temporary password to the captured terminal.');

        return $matches[1][0];
    }

    private function checkTemporaryCredential(User $user, #[\SensitiveParameter] string $password, int $before, int $after): void
    {
        $hash = $user->getPasswordHash();
        $expiry = $user->getTemporaryPasswordExpiresAt();
        $this->ensure($user->mustChangePassword() && \is_string($hash) && null !== $expiry, 'A temporary credential is incomplete.');
        $this->ensure($this->hasherFactory()->getPasswordHasher(SecurityIdentity::class)->verify($hash, $password), 'The returned temporary password does not match the persisted hash.');
        $this->ensure($expiry->getTimestamp() >= $before + 86400 && $expiry->getTimestamp() <= $after + 86400, 'The temporary password expiry is not 24 hours.');
        $this->ensure('UTC' === $expiry->getTimezone()->getName(), 'Persisted expiry did not hydrate in UTC.');
    }

    private function countAudit(string $action, User $subject): int
    {
        return $this->em->getRepository(AuditEvent::class)->count(['action' => $action, 'subject' => $subject]);
    }

    /** @phpstan-assert true $condition */
    private function ensure(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}

$check = new CredentialsCliCheck();
try {
    $check->run();
} catch (\Throwable $exception) {
    // Never dump command output, exception arguments or generated credentials into CI logs.
    fwrite(STDERR, sprintf("Credentials CLI check failed during %s (%s).\n", $check->getPhase(), $exception::class));
    exit(1);
}
