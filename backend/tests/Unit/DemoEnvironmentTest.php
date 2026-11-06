<?php

namespace App\Tests\Unit;

use App\Command\LoadDemoDataCommand;
use App\DataFixtures\DemoData;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DemoEnvironmentTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function refusedInvocations(): iterable
    {
        yield 'production with flag' => ['prod', true];
        yield 'unrecognized environment with flag' => ['staging', true];
        yield 'dev without flag' => ['dev', false];
        yield 'test without flag' => ['test', false];
    }

    #[DataProvider('refusedInvocations')]
    public function testGuardRejectsBeforeAnyDatabaseAccess(string $environment, bool $confirmed): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');
        $em->expects(self::never())->method('getRepository');
        $em->expects(self::never())->method('getConnection');
        $loader = new DemoData($em, $environment);

        $this->expectException(\DomainException::class);
        $loader->load($confirmed);
    }

    public function testConsoleReturnsNonzeroForProductionEvenWithExplicitFlag(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');
        $command = new CommandTester(new LoadDemoDataCommand(new DemoData($em, 'prod')));

        self::assertSame(Command::FAILURE, $command->execute(['--confirm-demo' => true]));
        self::assertStringContainsString('never in production', $command->getDisplay());
    }
}
