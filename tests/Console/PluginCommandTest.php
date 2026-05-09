<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Console;

use Mosyca\Core\Console\PluginCommand;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginResult;
use Mosyca\Core\Renderer\OutputRendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PluginCommandTest extends TestCase
{
    public function testCommandNameMatchesPlugin(): void
    {
        $command = $this->buildCommand($this->stubPlugin('core:system:ping'));

        self::assertSame('core:system:ping', $command->getName());
    }

    public function testCommandDescriptionMatchesPlugin(): void
    {
        $plugin = $this->stubPlugin('core:system:ping', description: 'Sends a ping');
        $command = $this->buildCommand($plugin);

        self::assertSame('Sends a ping', $command->getDescription());
    }

    public function testCommandHasFormatAndTemplateOptions(): void
    {
        $command = $this->buildCommand($this->stubPlugin('core:system:ping'));

        self::assertTrue($command->getDefinition()->hasOption('format'));
        self::assertTrue($command->getDefinition()->hasOption('template'));
        self::assertTrue($command->getDefinition()->hasOption('no-confirm'));
    }

    public function testPluginParametersBecomeCLIOptions(): void
    {
        $plugin = $this->stubPlugin('core:system:echo', parameters: [
            'message' => ['type' => 'string', 'description' => 'Echo message', 'required' => true],
        ]);
        $command = $this->buildCommand($plugin);

        self::assertTrue($command->getDefinition()->hasOption('message'));
    }

    public function testExecuteRendersSuccessfulResult(): void
    {
        $result = PluginResult::ok(['pong' => 'pong'], '✅ pong');
        $plugin = $this->stubPlugin('core:system:ping', result: $result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with($result, 'json', null)
            ->willReturn('{"success":true}');

        $tester = new CommandTester($this->buildCommand($plugin, $renderer));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('{"success":true}', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureOnPluginError(): void
    {
        $result = PluginResult::error('Something went wrong');
        $plugin = $this->stubPlugin('core:system:ping', result: $result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('{"success":false}');

        $tester = new CommandTester($this->buildCommand($plugin, $renderer));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testExecutePassesFormatOption(): void
    {
        $result = PluginResult::ok([], 'ok');
        $plugin = $this->stubPlugin('core:system:ping', result: $result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with($result, 'yaml', null)
            ->willReturn('success: true');

        $tester = new CommandTester($this->buildCommand($plugin, $renderer));
        $tester->execute(['--format' => 'yaml']);
    }

    public function testRequiredParamMissingReturnsInvalid(): void
    {
        $plugin = $this->stubPlugin('core:system:echo', parameters: [
            'message' => ['type' => 'string', 'description' => 'The message', 'required' => true],
        ]);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->expects(self::never())->method('render');

        $tester = new CommandTester($this->buildCommand($plugin, $renderer));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testCoercesIntegerParameter(): void
    {
        $result = PluginResult::ok([], 'ok');
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn('core:test:int');
        $plugin->method('getDescription')->willReturn('Test');
        $plugin->method('getUsage')->willReturn('Test');
        $plugin->method('getParameters')->willReturn([
            'limit' => ['type' => 'integer', 'required' => false],
        ]);
        $plugin->method('getRequiredScopes')->willReturn([]);
        $plugin->method('getTags')->willReturn([]);
        $plugin->method('isMutating')->willReturn(false);
        $plugin->method('getDefaultFormat')->willReturn('json');
        $plugin->method('getDefaultTemplate')->willReturn(null);
        $plugin->expects(self::once())
            ->method('execute')
            ->with(['limit' => 42])
            ->willReturn($result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('ok');

        $tester = new CommandTester($this->buildCommand($plugin, $renderer));
        $tester->execute(['--limit' => '42']);
    }

    public function testCoercesBooleanParameter(): void
    {
        $result = PluginResult::ok([], 'ok');
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn('core:test:bool');
        $plugin->method('getDescription')->willReturn('Test');
        $plugin->method('getUsage')->willReturn('Test');
        $plugin->method('getParameters')->willReturn([
            'active' => ['type' => 'boolean', 'required' => false],
        ]);
        $plugin->method('getRequiredScopes')->willReturn([]);
        $plugin->method('getTags')->willReturn([]);
        $plugin->method('isMutating')->willReturn(false);
        $plugin->method('getDefaultFormat')->willReturn('json');
        $plugin->method('getDefaultTemplate')->willReturn(null);
        $plugin->expects(self::once())
            ->method('execute')
            ->with(['active' => true])
            ->willReturn($result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('ok');

        $tester = new CommandTester($this->buildCommand($plugin, $renderer));
        $tester->execute(['--active' => 'true']);
    }

    public function testMutatingPluginSkipsConfirmWhenNotInteractive(): void
    {
        $result = PluginResult::ok([], 'ok');
        $plugin = $this->stubPlugin('core:test:delete', mutating: true, result: $result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('ok');

        // CommandTester is non-interactive by default
        $tester = new CommandTester($this->buildCommand($plugin, $renderer));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    // -------------------------------------------------------------------------

    private function buildCommand(PluginInterface $plugin, ?OutputRendererInterface $renderer = null): PluginCommand
    {
        return new PluginCommand($plugin, $renderer ?? $this->createMock(OutputRendererInterface::class));
    }

    /**
     * @param array<string, array<string, mixed>> $parameters
     */
    private function stubPlugin(
        string $name,
        string $description = 'Test plugin',
        array $parameters = [],
        bool $mutating = false,
        ?PluginResult $result = null,
    ): PluginInterface {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn($name);
        $plugin->method('getDescription')->willReturn($description);
        $plugin->method('getUsage')->willReturn('Test usage');
        $plugin->method('getParameters')->willReturn($parameters);
        $plugin->method('getRequiredScopes')->willReturn([]);
        $plugin->method('getTags')->willReturn(['core']);
        $plugin->method('isMutating')->willReturn($mutating);
        $plugin->method('getDefaultFormat')->willReturn('json');
        $plugin->method('getDefaultTemplate')->willReturn(null);
        $plugin->method('execute')->willReturn($result ?? PluginResult::ok([], 'ok'));

        return $plugin;
    }
}
