<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Console;

use Mosyca\Core\Console\ConsoleAdapter;
use Mosyca\Core\Console\PluginCommand;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginRegistry;
use Mosyca\Core\Plugin\PluginResult;
use Mosyca\Core\Renderer\OutputRendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;

final class ConsoleAdapterTest extends TestCase
{
    private PluginRegistry $registry;
    private ConsoleAdapter $adapter;

    protected function setUp(): void
    {
        $this->registry = new PluginRegistry();
        $renderer = $this->createMock(OutputRendererInterface::class);
        $this->adapter = new ConsoleAdapter($this->registry, $renderer);
    }

    public function testGetNamesReturnsRegisteredPluginNames(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping'));
        $this->registry->register($this->makePlugin('core:system:echo'));

        self::assertSame(['core:system:ping', 'core:system:echo'], $this->adapter->getNames());
    }

    public function testGetNamesIsEmptyWithNoPlugins(): void
    {
        self::assertSame([], $this->adapter->getNames());
    }

    public function testHasReturnsTrueForRegisteredPlugin(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping'));

        self::assertTrue($this->adapter->has('core:system:ping'));
    }

    public function testHasReturnsFalseForUnknownPlugin(): void
    {
        self::assertFalse($this->adapter->has('does:not:exist'));
    }

    public function testGetReturnsPluginCommand(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping'));

        $command = $this->adapter->get('core:system:ping');

        self::assertInstanceOf(PluginCommand::class, $command);
        self::assertSame('core:system:ping', $command->getName());
    }

    public function testGetThrowsCommandNotFoundException(): void
    {
        $this->expectException(CommandNotFoundException::class);

        $this->adapter->get('does:not:exist');
    }

    public function testBuildCommandWrapsPlugin(): void
    {
        $plugin = $this->makePlugin('shopware:order:list');
        $command = $this->adapter->buildCommand($plugin);

        self::assertInstanceOf(PluginCommand::class, $command);
        self::assertSame('shopware:order:list', $command->getName());
    }

    private function makePlugin(string $name): PluginInterface
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn($name);
        $plugin->method('getDescription')->willReturn('Test plugin');
        $plugin->method('getUsage')->willReturn('Test usage');
        $plugin->method('getParameters')->willReturn([]);
        $plugin->method('getRequiredScopes')->willReturn([]);
        $plugin->method('getTags')->willReturn([]);
        $plugin->method('isMutating')->willReturn(false);
        $plugin->method('getDefaultFormat')->willReturn('json');
        $plugin->method('getDefaultTemplate')->willReturn(null);
        $plugin->method('execute')->willReturn(PluginResult::ok([], 'ok'));

        return $plugin;
    }
}
