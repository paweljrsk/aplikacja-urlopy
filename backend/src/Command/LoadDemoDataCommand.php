<?php

namespace App\Command;

use App\DataFixtures\DemoData;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demo:load', description: 'Load synthetic stage 2 data into an empty development/test database.')]
final class LoadDemoDataCommand extends Command
{
    public function __construct(private readonly DemoData $demoData)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('confirm-demo', null, InputOption::VALUE_NONE, 'Explicitly confirm loading synthetic development/test data.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->demoData->load(true === $input->getOption('confirm-demo'));
        } catch (\DomainException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%s Synthetic fixture: %d users, %d teams, %d requests. No login passwords were created.', $result['created'] ? 'Demo data created.' : 'Demo data already present; nothing changed.', $result['users'], $result['teams'], $result['requests']));

        return Command::SUCCESS;
    }
}
