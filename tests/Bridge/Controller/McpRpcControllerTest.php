<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Bridge\Controller;

use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\Builtin\EchoAction;
use Mosyca\Core\Action\Builtin\PingAction;
use Mosyca\Core\Action\Builtin\Resource\SystemResource;
use Mosyca\Core\Bridge\ConstraintSchemaTranslator;
use Mosyca\Core\Bridge\Controller\McpRpcController;
use Mosyca\Core\Bridge\McpDiscoveryService;
use Mosyca\Core\Bridge\McpExecutionService;
use Mosyca\Core\Resource\ResourceRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Mosyca\Core\Bridge\Controller\McpRpcController
 */
final class McpRpcControllerTest extends TestCase
{
    private McpRpcController $controller;

    protected function setUp(): void
    {
        $actionRegistry = new ActionRegistry();
        $actionRegistry->register(new PingAction());
        $actionRegistry->register(new EchoAction());

        $resourceRegistry = new ResourceRegistry();
        $resourceRegistry->register(new SystemResource());

        $discoveryService = new McpDiscoveryService(
            $resourceRegistry,
            $actionRegistry,
            new ConstraintSchemaTranslator(),
        );

        $executionService = new McpExecutionService($resourceRegistry, $actionRegistry);

        $this->controller = new McpRpcController($discoveryService, $executionService);
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function call(array $body): mixed
    {
        $request = Request::create(
            '/api/v1/mcp/rpc',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );

        $response = ($this->controller)($request);

        return json_decode((string) $response->getContent(), true);
    }

    private function callRaw(string $rawBody): mixed
    {
        $request = Request::create(
            '/api/v1/mcp/rpc',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody,
        );

        $response = ($this->controller)($request);

        return json_decode((string) $response->getContent(), true);
    }

    // -----------------------------------------------------------------------
    // tools/list
    // -----------------------------------------------------------------------

    public function testListToolsReturnsJsonRpcEnvelope(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertIsArray($data);
        self::assertSame('2.0', $data['jsonrpc']);
        self::assertSame(1, $data['id']);
        self::assertArrayHasKey('result', $data);
        self::assertArrayHasKey('tools', $data['result']);
    }

    public function testListToolsIncludesCoreSystemPing(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertIsArray($data['result']['tools']);
        $names = array_column($data['result']['tools'], 'name');
        self::assertContains('core_system_ping', $names);
    }

    public function testListToolsIncludesCoreSystemEcho(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $names = array_column($data['result']['tools'], 'name');
        self::assertContains('core_system_echo', $names);
    }

    public function testListToolsPreservesRequestId(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 42, 'method' => 'tools/list']);

        self::assertSame(42, $data['id']);
    }

    public function testListToolsEachToolHasTenantInSchema(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        foreach ($data['result']['tools'] as $tool) {
            self::assertArrayHasKey('tenant', $tool['inputSchema']['properties']);
            self::assertContains('tenant', $tool['inputSchema']['required']);
        }
    }

    // -----------------------------------------------------------------------
    // tools/call
    // -----------------------------------------------------------------------

    public function testCallToolPingReturnsSuccessEnvelope(): void
    {
        $data = $this->call([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'core_system_ping', 'arguments' => ['tenant' => 'default']],
        ]);

        self::assertIsArray($data);
        self::assertSame('2.0', $data['jsonrpc']);
        self::assertSame(2, $data['id']);
        self::assertArrayHasKey('result', $data);
        self::assertArrayHasKey('content', $data['result']);
    }

    public function testCallToolPingContentIsTextType(): void
    {
        $data = $this->call([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'core_system_ping', 'arguments' => ['tenant' => 'default']],
        ]);

        $content = $data['result']['content'];
        self::assertIsArray($content);
        self::assertCount(1, $content);
        self::assertSame('text', $content[0]['type']);
    }

    public function testCallToolPingTextDecodesToSuccessResult(): void
    {
        $data = $this->call([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'core_system_ping', 'arguments' => ['tenant' => 'default']],
        ]);

        $decoded = json_decode($data['result']['content'][0]['text'], true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success']);
        self::assertSame('✅ pong', $decoded['summary']);
    }

    public function testCallToolEchoReturnsEchoedMessage(): void
    {
        $data = $this->call([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'core_system_echo',
                'arguments' => ['tenant' => 'default', 'message' => 'hello bridge'],
            ],
        ]);

        $decoded = json_decode($data['result']['content'][0]['text'], true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success']);
        self::assertSame('echo: hello bridge', $decoded['summary']);
    }

    public function testCallToolMissingTenantDefaultsToDefault(): void
    {
        // No tenant key — executionService defaults to 'default', must not throw.
        $data = $this->call([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'core_system_ping', 'arguments' => []],
        ]);

        self::assertArrayHasKey('result', $data);
        self::assertArrayNotHasKey('error', $data);
    }

    // -----------------------------------------------------------------------
    // error cases
    // -----------------------------------------------------------------------

    public function testUnknownMethodReturnsMethodNotFoundError(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/unknown']);

        self::assertArrayHasKey('error', $data);
        self::assertSame(-32601, $data['error']['code']);
        self::assertStringContainsString('tools/unknown', $data['error']['message']);
    }

    public function testUnknownToolNameReturnsInvalidParamsError(): void
    {
        $data = $this->call([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'bad_namespace_res_op', 'arguments' => ['tenant' => 'default']],
        ]);

        self::assertArrayHasKey('error', $data);
        self::assertSame(-32602, $data['error']['code']);
    }

    public function testInvalidJsonReturnsParseError(): void
    {
        $data = $this->callRaw('{not valid json}');

        self::assertIsArray($data);
        self::assertSame('2.0', $data['jsonrpc']);
        self::assertNull($data['id']);
        self::assertArrayHasKey('error', $data);
        self::assertSame(-32700, $data['error']['code']);
        self::assertSame('Parse error', $data['error']['message']);
    }

    public function testNullIdPreservedInErrorResponse(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'method' => 'tools/unknown']);
        // No id key in request — should be null in response.
        self::assertNull($data['id']);
    }

    public function testResponseIsAlwaysHttp200(): void
    {
        $request = Request::create(
            '/api/v1/mcp/rpc',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{not json}',
        );

        $response = ($this->controller)($request);
        self::assertSame(200, $response->getStatusCode());
    }
}
