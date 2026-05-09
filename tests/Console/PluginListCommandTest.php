<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Console;

use Mosyca\Core\Console\Command\PluginListCommand;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginRegistry;
use Mosyca\Core\Plugin\PluginResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PluginListCommandTest extends TestCase
{
    private PluginRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PluginRegistry();
    }

    public function testListShowsAllPlugins(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', ['core']));
        $this->registry->register($this->makePlugin('core:system:echo', ['core']));

        $tester = new CommandTester(new PluginListCommand($this->registry));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('core:system:ping', $tester->getDisplay());
        self::assertStringContainsString('core:system:echo', $tester->getDisplay());
        self::assertStringContainsString('2 plugin(s)', $tester->getDisplay());
    }

    public function testListFiltersByTag(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', ['core']));
        $this->registry->register($this->makePlugin('shopware:order:list', ['ecommerce', 'shopware']));

        $tester = new CommandTester(new PluginListCommand($this->registry));
        $tester->execute(['--tag' => 'ecommerce']);

        self::assertStringContainsString('shopware:order:list', $tester->getDisplay());
        self::assertStringNotContainsString('core:system:ping', $tester->getDisplay());
    }

    public function testListShowsWarningWhenNoPluginsRegistered(): void
    {
        $tester = new CommandTester(new PluginListCommand($this->registry));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No plugins registered', $tester->getDisplay());
    }

    public function testListShowsWarningWhenTagMatchesNothing(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', ['core']));

        $tester = new CommandTester(new PluginListCommand($this->registry));
        $tester->execute(['--tag' => 'nonexistent']);

        self::assertStringContainsString("No plugins found with tag 'nonexistent'", $tester->getDisplay());
    }

    /**
     * @param string[] $tags
     */
    private function makePlugin(string $name, array $tags = []): PluginInterface
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn($name);
        $plugin->method('getDescription')->willReturn("Description for {$name}");
        $plugin->method('getUsage')->willReturn('Usage');
        $plugin->method('getParameters')->willReturn([]);
        $plugin->method('getRequiredScopes')->willReturn([]);
        $plugin->method('getTags')->willReturn($tags);
        $plugin->method('isMutating')->willReturn(false);
        $plugin->method('getDefaultFormat')->willReturn('json');
        $plugin->method('getDefaultTemplate')->willReturn(null);
        $plugin->method('execute')->willReturn(PluginResult::ok([], 'ok'));

        return $plugin;
    }
}
