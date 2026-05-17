<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Console;

use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Console\ActionCommand;
use Mosyca\Core\Console\ConsoleAdapter;
use Mosyca\Core\Context\ContextProvider;
use Mosyca\Core\Renderer\OutputRendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;

final class ConsoleAdapterTest extends TestCase
{
    private ActionRegistry $registry;
    private ConsoleAdapter $adapter;

    protected function setUp(): void
    {
        $this->registry = new ActionRegistry();
        $renderer = $this->createMock(OutputRendererInterface::class);
        $contextProvider = $this->createMock(ContextProvider::class);
        $this->adapter = new ConsoleAdapter($this->registry, $renderer, $contextProvider);
    }

    public function testGetNamesReturnsRegisteredActionNames(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping'));
        $this->registry->register($this->makeAction('mosyca:system:echo'));

        self::assertSame(['mosyca:system:ping', 'mosyca:system:echo'], $this->adapter->getNames());
    }

    public function testGetNamesIsEmptyWithNoActions(): void
    {
        self::assertSame([], $this->adapter->getNames());
    }

    public function testHasReturnsTrueForRegisteredAction(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping'));

        self::assertTrue($this->adapter->has('mosyca:system:ping'));
    }

    public function testHasReturnsFalseForUnknownAction(): void
    {
        self::assertFalse($this->adapter->has('does:not:exist'));
    }

    public function testGetReturnsActionCommand(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping'));

        $command = $this->adapter->get('mosyca:system:ping');

        self::assertInstanceOf(ActionCommand::class, $command);
        self::assertSame('mosyca:system:ping', $command->getName());
    }

    public function testGetThrowsCommandNotFoundException(): void
    {
        $this->expectException(CommandNotFoundException::class);

        $this->adapter->get('does:not:exist');
    }

    public function testBuildCommandWrapsAction(): void
    {
        $action = $this->makeAction('shopware:order:list');
        $command = $this->adapter->buildCommand($action);

        self::assertInstanceOf(ActionCommand::class, $command);
        self::assertSame('shopware:order:list', $command->getName());
    }

    private function makeAction(string $name): ActionInterface
    {
        $action = $this->createMock(ActionInterface::class);
        $action->method('getName')->willReturn($name);
        $action->method('getDescription')->willReturn('Test action');
        $action->method('getUsage')->willReturn('Test usage');
        $action->method('getParameters')->willReturn([]);
        $action->method('getRequiredScopes')->willReturn([]);
        $action->method('getTags')->willReturn([]);
        $action->method('isMutating')->willReturn(false);
        $action->method('getDefaultFormat')->willReturn('json');
        $action->method('getDefaultTemplate')->willReturn(null);
        $action->method('execute')->willReturn(ActionResult::ok([], 'ok'));

        return $action;
    }
}
