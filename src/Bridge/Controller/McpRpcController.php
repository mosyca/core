<?php

declare(strict_types=1);

namespace Mosyca\Core\Bridge\Controller;

use Mosyca\Core\Bridge\McpDiscoveryService;
use Mosyca\Core\Bridge\McpExecutionService;
use Mosyca\Core\Vault\Provisioning\ProvisioningLinkGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON-RPC 2.0 entry point for the MCP Bridge (V0.13d).
 *
 * POST /api/v1/mcp/rpc
 *
 * Supported methods:
 *   initialize              → MCP handshake (protocol version + server capabilities)
 *   notifications/initialized → Client confirmation after handshake (Notification — no response)
 *   ping                    → Health check
 *   tools/list              → McpDiscoveryService::listTools()
 *   tools/call              → McpExecutionService::callTool()
 *
 * Does NOT extend AbstractController — pure Symfony controller with constructor injection.
 * Uses the PHP-native Bridge services directly (ADR 3.1), bypassing API Platform entirely.
 *
 * Final+readonly: cannot be mocked by PHPUnit. Tests must use real service instances.
 *
 * Notification protocol (JSON-RPC 2.0 §5):
 *   A request without an "id" member is a Notification.
 *   The server MUST NOT reply → HTTP 204 No Content.
 *   Detection: array_key_exists('id', $body) — NOT ($body['id'] === null).
 *
 * Error codes (JSON-RPC 2.0 reserved):
 *   -32700  Parse error      — invalid JSON in request body
 *   -32601  Method not found — unknown JSON-RPC method
 *   -32602  Invalid params   — unknown MCP tool name
 */
#[Route('/api/v1/mcp/rpc', name: 'mosyca_mcp_rpc', methods: ['POST'])]
final readonly class McpRpcController
{
    public function __construct(
        private McpDiscoveryService $discoveryService,
        private McpExecutionService $executionService,
        private ?ProvisioningLinkGenerator $provisioningLinkGenerator = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $decoded = json_decode($request->getContent(), true);

        if (!\is_array($decoded)) {
            return new JsonResponse(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']],
                Response::HTTP_OK,
            );
        }

        /** @var array<string, mixed> $body */
        $body = $decoded;

        // JSON-RPC 2.0 §5: a request without an "id" member is a Notification.
        // The server MUST NOT send any response — return HTTP 204 No Content.
        if (!\array_key_exists('id', $body)) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $id = $body['id'] ?? null;
        $method = \is_string($body['method'] ?? null) ? (string) $body['method'] : '';

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => '2025-11-25',
                    'serverInfo' => ['name' => 'mosyca-mcp-server', 'version' => '0.13.2'],
                    'capabilities' => ['tools' => new \stdClass()],
                ],
                'notifications/initialized', 'ping' => ['status' => 'ok'],
                'tools/list' => ['tools' => $this->discoveryService->listTools()],
                'tools/call' => $this->callTool($body),
                default => throw new \InvalidArgumentException(\sprintf('Method not found: %s', $method), -32601),
            };
        } catch (\InvalidArgumentException $e) {
            $code = -32601 === $e->getCode() ? -32601 : -32602;

            return new JsonResponse(
                ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $e->getMessage()]],
                Response::HTTP_OK,
            );
        }

        return new JsonResponse(
            ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result],
            Response::HTTP_OK,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function callTool(array $body): array
    {
        /** @var array<string, mixed> $params */
        $params = \is_array($body['params'] ?? null) ? $body['params'] : [];
        $toolName = \is_string($params['name'] ?? null) ? (string) $params['name'] : '';

        /** @var array<string, mixed> $arguments */
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $actionResult = $this->executionService->callTool($toolName, $arguments);

        // Vault integration: when AUTH_REQUIRED is returned and a ProvisioningLinkGenerator
        // is wired in, replace the generic correctionHint with a signed, time-limited URL
        // that the operator can open to store credentials (Vault Rule V2 — no credential values here).
        if ('AUTH_REQUIRED' === ($actionResult['errorCode'] ?? null)
            && null !== $this->provisioningLinkGenerator
        ) {
            $integration = \is_string($actionResult['data']['integration_type'] ?? null)
                ? (string) $actionResult['data']['integration_type'] : '';
            $tenantId = \is_string($arguments['tenant'] ?? null) ? (string) $arguments['tenant'] : 'default';

            if ('' !== $integration) {
                try {
                    $url = $this->provisioningLinkGenerator->generate($integration, $tenantId);
                    $actionResult['correctionHint'] = \sprintf(
                        'Open this link to store credentials for "%s" (valid 24 h): %s',
                        $integration,
                        $url,
                    );
                } catch (\Throwable) {
                    // URL generation failure must never break the MCP response.
                    // The original correctionHint from ActionResult::authRequired() survives.
                }
            }
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => (string) json_encode($actionResult)],
            ],
        ];
    }
}
