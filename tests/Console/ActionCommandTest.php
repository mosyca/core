<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Console;

use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Console\ActionCommand;
use Mosyca\Core\Context\ContextProvider;
use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Renderer\OutputRendererInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ActionCommandTest extends TestCase
{
    /** @var ContextProvider&MockObject */
    private ContextProvider $contextProvider;

    protected function setUp(): void
    {
        $executionContext = $this->createMock(ExecutionContextInterface::class);
        $executionContext->method('isAclBypassed')->willReturn(false);
        $executionContext->method('getTenantId')->willReturn('default');

        $this->contextProvider = $this->createMock(ContextProvider::class);
        $this->contextProvider->method('createForCli')->willReturn($executionContext);
    }

    public function testCommandNameMatchesAction(): void
    {
        $command = $this->buildCommand($this->stubAction('mosyca:system:ping'));

        self::assertSame('mosyca:system:ping', $command->getName());
    }

    public function testCommandDescriptionMatchesAction(): void
    {
        $action = $this->stubAction('mosyca:system:ping', description: 'Sends a ping');
        $command = $this->buildCommand($action);

        self::assertSame('Sends a ping', $command->getDescription());
    }

    public function testCommandHasFormatAndTemplateOptions(): void
    {
        $command = $this->buildCommand($this->stubAction('mosyca:system:ping'));

        self::assertTrue($command->getDefinition()->hasOption('format'));
        self::assertTrue($command->getDefinition()->hasOption('template'));
        self::assertTrue($command->getDefinition()->hasOption('no-confirm'));
    }

    public function testActionParametersBecomeCLIOptions(): void
    {
        $action = $this->stubAction('mosyca:system:echo', parameters: [
            'message' => ['type' => 'string', 'description' => 'Echo message', 'required' => true],
        ]);
        $command = $this->buildCommand($action);

        self::assertTrue($command->getDefinition()->hasOption('message'));
    }

    public function testExecuteRendersSuccessfulResult(): void
    {
        $result = ActionResult::ok(['pong' => 'pong'], '✅ pong');
        $action = $this->stubAction('mosyca:system:ping', result: $result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with($result, 'json', null)
            ->willReturn('{"success":true}');

        $tester = new CommandTester($this->buildCommand($action, $renderer));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('{"success":true}', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureOnActionError(): void
    {
        $result = ActionResult::error('Something went wrong');
        $action = $this->stubAction('mosyca:system:ping', result: $result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('{"success":false}');

        $tester = new CommandTester($this->buildCommand($action, $renderer));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testExecutePassesFormatOption(): void
    {
        $result = ActionResult::ok([], 'ok');
        $action = $this->stubAction('mosyca:system:ping', result: $result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with($result, 'yaml', null)
            ->willReturn('success: true');

        $tester = new CommandTester($this->buildCommand($action, $renderer));
        $tester->execute(['--format' => 'yaml']);
    }

    public function testRequiredParamMissingReturnsInvalid(): void
    {
        $action = $this->stubAction('mosyca:system:echo', parameters: [
            'message' => ['type' => 'string', 'description' => 'The message', 'required' => true],
        ]);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->expects(self::never())->method('render');

        $tester = new CommandTester($this->buildCommand($action, $renderer));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testCoercesIntegerParameter(): void
    {
        $result = ActionResult::ok([], 'ok');
        $action = $this->createMock(ActionInterface::class);
        $action->method('getName')->willReturn('core:test:int');
        $action->method('getDescription')->willReturn('Test');
        $action->method('getUsage')->willReturn('Test');
        $action->method('getParameters')->willReturn([
            'limit' => ['type' => 'integer', 'required' => false],
        ]);
        $action->method('getRequiredScopes')->willReturn([]);
        $action->method('getTags')->willReturn([]);
        $action->method('isMutating')->willReturn(false);
        $action->method('getDefaultFormat')->willReturn('json');
        $action->method('getDefaultTemplate')->willReturn(null);
        $action->expects(self::once())
            ->method('execute')
            ->with(['limit' => 42], self::isInstanceOf(ExecutionContextInterface::class))
            ->willReturn($result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('ok');

        $tester = new CommandTester($this->buildCommand($action, $renderer));
        $tester->execute(['--limit' => '42']);
    }

    public function testCoercesBooleanParameter(): void
    {
        $result = ActionResult::ok([], 'ok');
        $action = $this->createMock(ActionInterface::class);
        $action->method('getName')->willReturn('core:test:bool');
        $action->method('getDescription')->willReturn('Test');
        $action->method('getUsage')->willReturn('Test');
        $action->method('getParameters')->willReturn([
            'active' => ['type' => 'boolean', 'required' => false],
        ]);
        $action->method('getRequiredScopes')->willReturn([]);
        $action->method('getTags')->willReturn([]);
        $action->method('isMutating')->willReturn(false);
        $action->method('getDefaultFormat')->willReturn('json');
        $action->method('getDefaultTemplate')->willReturn(null);
        $action->expects(self::once())
            ->method('execute')
            ->with(['active' => true], self::isInstanceOf(ExecutionContextInterface::class))
            ->willReturn($result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('ok');

        $tester = new CommandTester($this->buildCommand($action, $renderer));
        $tester->execute(['--active' => 'true']);
    }

    public function testMutatingActionSkipsConfirmWhenNotInteractive(): void
    {
        $result = ActionResult::ok([], 'ok');
        $action = $this->stubAction('core:test:delete', mutating: true, result: $result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('ok');

        // CommandTester is non-interactive by default
        $tester = new CommandTester($this->buildCommand($action, $renderer));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * CLI must pass ExecutionContext to execute() — context is built by ContextProvider::createForCli().
     */
    public function testExecutePassesContextToAction(): void
    {
        $result = ActionResult::ok([], 'ok');
        $action = $this->createMock(ActionInterface::class);
        $action->method('getName')->willReturn('mosyca:system:ping');
        $action->method('getDescription')->willReturn('Ping');
        $action->method('getUsage')->willReturn('Ping');
        $action->method('getParameters')->willReturn([]);
        $action->method('getRequiredScopes')->willReturn([]);
        $action->method('getTags')->willReturn([]);
        $action->method('isMutating')->willReturn(false);
        $action->method('getDefaultFormat')->willReturn('json');
        $action->method('getDefaultTemplate')->willReturn(null);
        $action->expects(self::once())
            ->method('execute')
            ->with([], self::isInstanceOf(ExecutionContextInterface::class))
            ->willReturn($result);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('ok');

        $tester = new CommandTester($this->buildCommand($action, $renderer));
        $tester->execute([]);
    }

    // -------------------------------------------------------------------------

    private function buildCommand(ActionInterface $action, ?OutputRendererInterface $renderer = null): ActionCommand
    {
        return new ActionCommand(
            $action,
            $renderer ?? $this->createMock(OutputRendererInterface::class),
            $this->contextProvider,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $parameters
     */
    private function stubAction(
        string $name,
        string $description = 'Test action',
        array $parameters = [],
        bool $mutating = false,
        ?ActionResult $result = null,
    ): ActionInterface {
        $action = $this->createMock(ActionInterface::class);
        $action->method('getName')->willReturn($name);
        $action->method('getDescription')->willReturn($description);
        $action->method('getUsage')->willReturn('Test usage');
        $action->method('getParameters')->willReturn($parameters);
        $action->method('getRequiredScopes')->willReturn([]);
        $action->method('getTags')->willReturn(['core']);
        $action->method('isMutating')->willReturn($mutating);
        $action->method('getDefaultFormat')->willReturn('json');
        $action->method('getDefaultTemplate')->willReturn(null);
        $action->method('execute')->willReturn($result ?? ActionResult::ok([], 'ok'));

        return $action;
    }
}
