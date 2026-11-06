<?php

namespace App\Command;

use App\Entity\User;
use App\Service\CredentialsException;
use App\Service\CredentialsService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:admin:bootstrap', description: 'Interactively initialize the first administrator with a random temporary password.')]
final class BootstrapAdminCommand extends Command
{
    public function __construct(private readonly CredentialsService $credentials, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Uruchom tę komendę interaktywnie w zaufanym terminalu. Hasło nie jest argumentem polecenia.');

            return Command::FAILURE;
        }

        try {
            $email = $io->ask('E-mail administratora', null, static function (mixed $value): string {
                if (!\is_string($value) || false === filter_var(trim($value), FILTER_VALIDATE_EMAIL) || strlen(trim($value)) > 254) {
                    throw new \InvalidArgumentException('Podaj poprawny adres e-mail.');
                }

                return strtolower(trim($value));
            });
            if (!\is_string($email)) {
                return Command::FAILURE;
            }
            $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null !== $existing) {
                if (!$existing->isAdministrator() || !$existing->isActive() || null !== $existing->getPasswordHash()) {
                    $io->error('To konto nie jest aktywnym administratorem oczekującym na pierwsze hasło.');

                    return Command::FAILURE;
                }
                if (!$io->confirm('Ustawić pierwsze hasło istniejącego administratora, zachowując jego dane?', false)) {
                    $io->note('Anulowano.');

                    return Command::FAILURE;
                }
                $firstName = $existing->getFirstName();
                $lastName = $existing->getLastName();
            } else {
                $firstName = $io->ask('Imię');
                $lastName = $io->ask('Nazwisko');
                if (!\is_string($firstName) || !\is_string($lastName)) {
                    $io->error('Imię i nazwisko są wymagane.');

                    return Command::FAILURE;
                }
            }

            $result = $this->credentials->bootstrapAdmin($email, $firstName, $lastName, null !== $existing);
            $io->success('Administrator zainicjalizowany. Pierwsze logowanie wymaga zmiany hasła.');
            $io->writeln('Hasło tymczasowe (wyświetlane tylko teraz): '.$result['temporaryPassword']);
            $io->writeln('Ważne do: '.$result['expiresAt']->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i:s T'));
            $io->note('Przekaż hasło poza repozytorium i logami. Nie zapisuj go w zgłoszeniach.');

            return Command::SUCCESS;
        } catch (CredentialsException|\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            $io->error('Inna operacja zainicjalizowała administratora lub zajęła ten adres. Żadne hasło nie zostało zwrócone.');
        }

        return Command::FAILURE;
    }
}
