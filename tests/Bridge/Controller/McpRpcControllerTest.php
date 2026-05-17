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
use Mosyca\Core\Tests\Bridge\JsonRpcSchemaValidatorTrait;
use Mosyca\Core\Vault\Provisioning\ProvisioningLinkGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @covers \Mosyca\Core\Bridge\Controller\McpRpcController
 */
final class McpRpcControllerTest extends TestCase
{
    use JsonRpcSchemaValidatorTrait;

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
     * Dispatch a JSON-RPC 2.0 request and return the decoded response.
     * Also asserts JSON-RPC 2.0 schema conformance on every non-null response.
     *
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
        $data = json_decode((string) $response->getContent(), true);

        if (\is_array($data)) {
            $this->assertValidJsonRpcResponse($data);
        }

        return $data;
    }

    /**
     * Dispatch a raw string body and return the decoded response.
     * Also asserts JSON-RPC 2.0 schema conformance on every non-null response.
     */
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
        $data = json_decode((string) $response->getContent(), true);

        if (\is_array($data)) {
            $this->assertValidJsonRpcResponse($data);
        }

        return $data;
    }

    /**
     * Dispatch a JSON-RPC 2.0 request and return the raw Response object.
     * Use this to assert HTTP status codes (e.g. 204 for Notifications).
     *
     * @param array<string, mixed> $body
     */
    private function callResponse(array $body): Response
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

        return ($this->controller)($request);
    }

    // -----------------------------------------------------------------------
    // MCP handshake — initialize / notifications/initialized / ping
    // -----------------------------------------------------------------------

    public function testInitializeReturnsProtocolVersion(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => []]);

        self::assertSame('2.0', $data['jsonrpc']);
        self::assertSame(0, $data['id']);
        self::assertArrayHasKey('result', $data);
        self::assertSame('2025-11-25', $data['result']['protocolVersion']);
    }

    public function testInitializeReturnsServerInfo(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => []]);

        self::assertIsArray($data['result']['serverInfo']);
        self::assertSame('mosyca-mcp-server', $data['result']['serverInfo']['name']);
        self::assertArrayHasKey('version', $data['result']['serverInfo']);
    }

    public function testInitializeReturnsToolCapabilities(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => []]);

        self::assertArrayHasKey('capabilities', $data['result']);
        self::assertArrayHasKey('tools', $data['result']['capabilities']);
    }

    public function testInitializeToolCapabilityIsJsonObject(): void
    {
        // MCP spec requires "tools": {} (JSON object), not "tools": [] (JSON array).
        // PHP encodes stdClass as {} and empty array as [], so we must verify the
        // raw JSON wire format — not the decoded PHP value.
        $request = Request::create(
            '/api/v1/mcp/rpc',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => []]),
        );

        $responseJson = ($this->controller)($request)->getContent();

        self::assertStringContainsString('"tools":{}', str_replace(' ', '', (string) $responseJson));
    }

    public function testNotificationsInitializedWithExplicitNullIdReturnsOk(): void
    {
        // "id": null is present as a key → this is a regular request, not a Notification.
        // Server must respond normally.
        $data = $this->call(['jsonrpc' => '2.0', 'id' => null, 'method' => 'notifications/initialized']);

        self::assertArrayHasKey('result', $data);
        self::assertSame('ok', $data['result']['status']);
    }

    public function testNotificationsInitializedWithoutIdReturns204(): void
    {
        // No "id" key at all = JSON-RPC 2.0 Notification (§5).
        // Server MUST NOT reply — HTTP 204 No Content, empty body.
        $response = $this->callResponse(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
    }

    public function testAnyRequestWithoutIdReturns204(): void
    {
        // Even unknown methods sent as Notifications must get 204, not -32601.
        // Per JSON-RPC 2.0 spec the server MUST NOT respond to any Notification.
        $response = $this->callResponse(['jsonrpc' => '2.0', 'method' => 'tools/unknown']);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testPingReturnsOk(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'ping']);

        self::assertSame(99, $data['id']);
        self::assertArrayHasKey('result', $data);
        self::assertSame('ok', $data['result']['status']);
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
        self::assertContains('mosyca_system_ping', $names);
    }

    public function testListToolsIncludesCoreSystemEcho(): void
    {
        $data = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $names = array_column($data['result']['tools'], 'name');
        self::assertContains('mosyca_system_echo', $names);
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
            'params' => ['name' => 'mosyca_system_ping', 'arguments' => ['tenant' => 'default']],
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
            'params' => ['name' => 'mosyca_system_ping', 'arguments' => ['tenant' => 'default']],
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
            'params' => ['name' => 'mosyca_system_ping', 'arguments' => ['tenant' => 'default']],
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
                'name' => 'mosyca_system_echo',
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
            'params' => ['name' => 'mosyca_system_ping', 'arguments' => []],
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
        // Explicit "id": null is present as a key → regular request (not Notification).
        // An unknown method with explicit id:null must return an error with id:null.
        $data = $this->call(['jsonrpc' => '2.0', 'id' => null, 'method' => 'tools/unknown']);

        self::assertNull($data['id']);
        self::assertSame(-32601, $data['error']['code']);
    }

    public function testResponseIsAlwaysHttp200ForJsonRpcRequests(): void
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

    // -----------------------------------------------------------------------
    // AUTH_REQUIRED + ProvisioningLinkGenerator (V0.14d)
    // -----------------------------------------------------------------------

    public function testAuthRequiredWithoutGeneratorPreservesOriginalCorrectionHint(): void
    {
        $controller = $this->makeAuthRequiredController(withGenerator: false);

        $data = $this->callWith($controller, [
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => ['name' => 'test_vault_auth_req', 'arguments' => ['tenant' => 'acme']],
        ]);

        $decoded = json_decode($data['result']['content'][0]['text'], true);
        self::assertIsArray($decoded);
        self::assertSame('AUTH_REQUIRED', $decoded['errorCode']);
        // Original correctionHint from ActionResult::authRequired() must survive unmodified.
        self::assertStringContainsString('out-of-band provisioning', $decoded['correctionHint']);
        self::assertStringNotContainsString('Open this link', $decoded['correctionHint']);
    }

    public function testAuthRequiredWithGeneratorReplacesHintWithSignedUrl(): void
    {
        $controller = $this->makeAuthRequiredController(withGenerator: true);

        $data = $this->callWith($controller, [
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => ['name' => 'test_vault_auth_req', 'arguments' => ['tenant' => 'acme']],
        ]);

        $decoded = json_decode($data['result']['content'][0]['text'], true);
        self::assertIsArray($decoded);
        self::assertSame('AUTH_REQUIRED', $decoded['errorCode']);
        self::assertStringContainsString('Open this link', $decoded['correctionHint']);
        self::assertStringContainsString('spotify', $decoded['correctionHint']);
        self::assertStringContainsString('https://', $decoded['correctionHint']);
    }

    public function testAuthRequiredUrlGenerationFailurePreservesCorrectionHint(): void
    {
        // ProvisioningLinkGenerator is present but its UrlGenerator throws.
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willThrowException(new \RuntimeException('Routing unavailable'));

        $linkGenerator = new ProvisioningLinkGenerator($urlGenerator, new UriSigner('test-secret'));

        $controller = $this->makeAuthRequiredControllerWith($linkGenerator);

        $data = $this->callWith($controller, [
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'tools/call',
            'params' => ['name' => 'test_vault_auth_req', 'arguments' => ['tenant' => 'acme']],
        ]);

        // Response must still be valid — URL generation failure never breaks MCP.
        $decoded = json_decode($data['result']['content'][0]['text'], true);
        self::assertIsArray($decoded);
        self::assertSame('AUTH_REQUIRED', $decoded['errorCode']);
        // Original correctionHint is preserved when generation fails.
        self::assertStringContainsString('out-of-band provisioning', $decoded['correctionHint']);
    }

    // -----------------------------------------------------------------------
    // helpers (AUTH_REQUIRED)
    // -----------------------------------------------------------------------

    /**
     * Dispatch a JSON-RPC request through an arbitrary controller instance.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function callWith(McpRpcController $controller, array $body): mixed
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

        $response = $controller($request);

        return json_decode((string) $response->getContent(), true);
    }

    private function makeAuthRequiredController(bool $withGenerator): McpRpcController
    {
        $linkGenerator = null;

        if ($withGenerator) {
            $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
            $urlGenerator
                ->method('generate')
                ->willReturn('https://example.com/api/vault/provision?integration=spotify&tenant_id=acme');

            $linkGenerator = new ProvisioningLinkGenerator($urlGenerator, new UriSigner('test-secret'));
        }

        return $this->makeAuthRequiredControllerWith($linkGenerator);
    }

    private function makeAuthRequiredControllerWith(?ProvisioningLinkGenerator $linkGenerator): McpRpcController
    {
        $actionRegistry = new ActionRegistry();
        $actionRegistry->register(new AuthRequiredAction());

        $resourceRegistry = new ResourceRegistry();
        $resourceRegistry->register(new AuthRequiredResource());

        $discoveryService = new McpDiscoveryService(
            $resourceRegistry,
            $actionRegistry,
            new ConstraintSchemaTranslator(),
        );

        $executionService = new McpExecutionService($resourceRegistry, $actionRegistry);

        return new McpRpcController($discoveryService, $executionService, $linkGenerator);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Test fixtures for AUTH_REQUIRED scenarios (V0.14d)
// ─────────────────────────────────────────────────────────────────────────────

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Resource\AbstractResource;

/**
 * Minimal action fixture that returns AUTH_REQUIRED.
 *
 * MCP tool name: test_vault_auth_req
 *
 * @internal for McpRpcControllerTest only
 */
#[AsAction]
final class AuthRequiredAction implements \Mosyca\Core\Action\ActionInterface
{
    use ActionTrait;

    public function getName(): string
    {
        return 'test:vault:auth_req';
    }

    public function getDescription(): string
    {
        return 'Test fixture: always returns AUTH_REQUIRED for spotify.';
    }

    public function getUsage(): string
    {
        return 'Test fixture. Always returns AUTH_REQUIRED.';
    }

    /** @return array<string, array<string, mixed>> */
    public function getParameters(): array
    {
        return [];
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        return ActionResult::authRequired('spotify', ['playlist-read']);
    }
}

/**
 * Minimal resource fixture that exposes AuthRequiredAction.
 *
 * MCP tool name: test_vault_auth_req
 *
 * @internal for McpRpcControllerTest only
 */
final class AuthRequiredResource extends AbstractResource
{
    public function getPluginNamespace(): string
    {
        return 'test';
    }

    public function getName(): string
    {
        return 'vault';
    }

    public function getDescription(): string
    {
        return 'Test fixture resource.';
    }

    /**
     * @return array<string, array{action: class-string, method: string, path: string}>
     */
    public function getOperations(): array
    {
        return [
            'auth_req' => [
                'action' => AuthRequiredAction::class,
                'method' => 'GET',
                'path' => '/auth-req',
            ],
        ];
    }
}
