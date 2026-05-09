<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Console;

use Mosyca\Core\Console\ConsoleAdapter;
use Mosyca\Core\Console\PluginCommand;
use Mosyca\Core\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleAdapterFunctionalTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
    }

    public function testConsoleAdapterIsRegisteredAsCommandLoader(): void
    {
        self::bootKernel();
        $adapter = self::getContainer()->get(ConsoleAdapter::class);

        self::assertInstanceOf(ConsoleAdapter::class, $adapter);
    }

    public function testPluginNamesAreExposedAsCommands(): void
    {
        self::bootKernel();
        /** @var ConsoleAdapter $adapter */
        $adapter = self::getContainer()->get(ConsoleAdapter::class);
        $names = $adapter->getNames();

        self::assertContains('core:system:ping', $names);
        self::assertContains('core:system:echo', $names);
    }

    public function testAdapterBuildsPluginCommandForPing(): void
    {
        self::bootKernel();
        /** @var ConsoleAdapter $adapter */
        $adapter = self::getContainer()->get(ConsoleAdapter::class);

        $command = $adapter->get('core:system:ping');

        self::assertInstanceOf(PluginCommand::class, $command);
        self::assertSame('core:system:ping', $command->getName());
    }

    public function testPluginListCommandRunsInApplication(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('mosyca:plugin:list'));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('core:system:ping', $tester->getDisplay());
        self::assertStringContainsString('core:system:echo', $tester->getDisplay());
    }

    public function testPluginShowCommandRunsInApplication(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('mosyca:plugin:show'));
        $exitCode = $tester->execute(['name' => 'core:system:ping']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('core:system:ping', $tester->getDisplay());
        self::assertStringContainsString('pong', $tester->getDisplay());
    }

    public function testPingPluginCommandRunsViaApplication(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('core:system:ping'));
        $exitCode = $tester->execute(['--format' => 'json']);

        self::assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('success', $output);
        self::assertStringContainsString('pong', $output);
    }

    public function testPluginShowCommandFailsForUnknownPlugin(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('mosyca:plugin:show'));
        $exitCode = $tester->execute(['name' => 'does:not:exist']);

        self::assertSame(1, $exitCode);
    }
}
