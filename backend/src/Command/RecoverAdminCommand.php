<?php

namespace App\Command;

use App\Service\CredentialsException;
use App\Service\CredentialsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:admin:recover', description: 'Interactively recover an existing active administrator from a trusted server console.')]
final class RecoverAdminCommand extends Command
{
    public function __construct(private readonly CredentialsService $credentials)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Odzyskanie dostępu wymaga interaktywnego, zaufanego terminala serwera.');

            return Command::FAILURE;
        }
        $email = $io->ask('E-mail istniejącego administratora', null, static function (mixed $value): string {
            if (!\is_string($value) || false === filter_var(trim($value), FILTER_VALIDATE_EMAIL) || strlen(trim($value)) > 254) {
                throw new \InvalidArgumentException('Podaj poprawny adres e-mail.');
            }

            return strtolower(trim($value));
        });
        if (!\is_string($email) || !$io->confirm('Wygenerować nowe hasło tymczasowe i unieważnić wszystkie sesje tego administratora?', false)) {
            $io->note('Anulowano.');

            return Command::FAILURE;
        }

        try {
            $result = $this->credentials->recoverAdministrator($email);
        } catch (CredentialsException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        $io->success('Zapisano odzyskanie dostępu i unieważniono dotychczasowe sesje.');
        $io->writeln('Hasło tymczasowe (wyświetlane tylko teraz): '.$result['temporaryPassword']);
        $io->writeln('Ważne do: '.$result['expiresAt']->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i:s T'));
        $io->note('Pierwsze logowanie wymaga zmiany hasła. Nie zapisuj hasła w repozytorium, logach ani zgłoszeniach.');

        return Command::SUCCESS;
    }
}
