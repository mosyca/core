<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Console;

use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Console\Command\ActionListCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ActionListCommandTest extends TestCase
{
    private ActionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ActionRegistry();
    }

    public function testListShowsAllActions(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping', ['core']));
        $this->registry->register($this->makeAction('mosyca:system:echo', ['core']));

        $tester = new CommandTester(new ActionListCommand($this->registry));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('mosyca:system:ping', $tester->getDisplay());
        self::assertStringContainsString('mosyca:system:echo', $tester->getDisplay());
        self::assertStringContainsString('2 action(s)', $tester->getDisplay());
    }

    public function testListFiltersByTag(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping', ['core']));
        $this->registry->register($this->makeAction('shopware:order:list', ['ecommerce', 'shopware']));

        $tester = new CommandTester(new ActionListCommand($this->registry));
        $tester->execute(['--tag' => 'ecommerce']);

        self::assertStringContainsString('shopware:order:list', $tester->getDisplay());
        self::assertStringNotContainsString('mosyca:system:ping', $tester->getDisplay());
    }

    public function testListShowsWarningWhenNoActionsRegistered(): void
    {
        $tester = new CommandTester(new ActionListCommand($this->registry));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No actions registered', $tester->getDisplay());
    }

    public function testListShowsWarningWhenTagMatchesNothing(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping', ['core']));

        $tester = new CommandTester(new ActionListCommand($this->registry));
        $tester->execute(['--tag' => 'nonexistent']);

        self::assertStringContainsString("No actions found with tag 'nonexistent'", $tester->getDisplay());
    }

    /**
     * @param string[] $tags
     */
    private function makeAction(string $name, array $tags = []): ActionInterface
    {
        $action = $this->createMock(ActionInterface::class);
        $action->method('getName')->willReturn($name);
        $action->method('getDescription')->willReturn("Description for {$name}");
        $action->method('getUsage')->willReturn('Usage');
        $action->method('getParameters')->willReturn([]);
        $action->method('getRequiredScopes')->willReturn([]);
        $action->method('getTags')->willReturn($tags);
        $action->method('isMutating')->willReturn(false);
        $action->method('getDefaultFormat')->willReturn('json');
        $action->method('getDefaultTemplate')->willReturn(null);
        $action->method('execute')->willReturn(ActionResult::ok([], 'ok'));

        return $action;
    }
}
