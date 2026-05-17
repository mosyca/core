<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Bridge;

use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\Builtin\EchoAction;
use Mosyca\Core\Action\Builtin\PingAction;
use Mosyca\Core\Action\Builtin\Resource\SystemResource;
use Mosyca\Core\Bridge\McpExecutionService;
use Mosyca\Core\Resource\ResourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mosyca\Core\Bridge\McpExecutionService
 */
final class McpExecutionServiceTest extends TestCase
{
    private McpExecutionService $service;

    protected function setUp(): void
    {
        $actionRegistry = new ActionRegistry();
        $actionRegistry->register(new PingAction());
        $actionRegistry->register(new EchoAction());

        $resourceRegistry = new ResourceRegistry();
        $resourceRegistry->register(new SystemResource());

        $this->service = new McpExecutionService($resourceRegistry, $actionRegistry);
    }

    public function testCallToolPingReturnsSuccess(): void
    {
        $result = $this->service->callTool('mosyca_system_ping', ['tenant' => 'default']);

        self::assertTrue($result['success']);
        self::assertSame('✅ pong', $result['summary']);
    }

    public function testCallToolEchoReturnsEchoedMessage(): void
    {
        $result = $this->service->callTool('mosyca_system_echo', [
            'tenant' => 'default',
            'message' => 'Hello Architecture',
        ]);

        self::assertTrue($result['success']);
        self::assertSame('echo: Hello Architecture', $result['summary']);
        self::assertSame(['message' => 'Hello Architecture'], $result['data']);
    }

    public function testCallToolRemovesTenantFromPayload(): void
    {
        // EchoAction returns $args as data — tenant must NOT appear in the result data.
        $result = $this->service->callTool('mosyca_system_echo', [
            'tenant' => 'default',
            'message' => 'hi',
        ]);

        self::assertIsArray($result['data']);
        self::assertArrayNotHasKey('tenant', $result['data']);
        self::assertArrayHasKey('message', $result['data']);
    }

    public function testCallToolExtractsTenantIntoContext(): void
    {
        // PingAction returns data including echo of args — we verify the action ran
        // without tenant in args (it would fail if tenant was passed and validated).
        $result = $this->service->callTool('mosyca_system_ping', [
            'tenant' => 'shop-berlin',
            'message' => 'test',
        ]);

        // PingAction executed successfully — tenant was extracted, not forwarded as arg.
        self::assertTrue($result['success']);
        self::assertIsArray($result['data']);
        self::assertArrayNotHasKey('tenant', $result['data']);
    }

    public function testCallToolDefaultsTenantWhenMissing(): void
    {
        // No tenant in arguments — should default to 'default' without throwing.
        $result = $this->service->callTool('mosyca_system_ping', []);

        self::assertTrue($result['success']);
    }

    public function testCallToolDefaultsTenantWhenNonString(): void
    {
        // Non-string tenant value — should fall back to 'default'.
        $result = $this->service->callTool('mosyca_system_ping', ['tenant' => 42]);

        self::assertTrue($result['success']);
    }

    public function testCallToolThrowsOnUnknownToolName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/No action found for MCP tool name 'unknown_tool_name'/");

        $this->service->callTool('unknown_tool_name', ['tenant' => 'default']);
    }

    public function testCallToolThrowsOnPartialToolName(): void
    {
        // "core_system" matches no operation slug — must throw.
        $this->expectException(\InvalidArgumentException::class);

        $this->service->callTool('mosyca_system', ['tenant' => 'default']);
    }

    public function testCallToolResultContainsStandardKeys(): void
    {
        $result = $this->service->callTool('mosyca_system_ping', ['tenant' => 'default']);

        self::assertArrayHasKey('success', $result);
        self::assertArrayHasKey('summary', $result);
        self::assertArrayHasKey('data', $result);
    }
}
