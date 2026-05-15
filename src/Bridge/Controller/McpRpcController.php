<?php

declare(strict_types=1);

namespace Mosyca\Core\Bridge\Controller;

use Mosyca\Core\Bridge\McpDiscoveryService;
use Mosyca\Core\Bridge\McpExecutionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON-RPC 2.0 entry point for the MCP Bridge (V0.13b).
 *
 * POST /api/v1/mcp/rpc
 *
 * Supported methods:
 *   initialize              → MCP handshake (protocol version + server capabilities)
 *   notifications/initialized → Client confirmation after handshake (no-op ack)
 *   ping                    → Health check
 *   tools/list              → McpDiscoveryService::listTools()
 *   tools/call              → McpExecutionService::callTool()
 *
 * Does NOT extend AbstractController — pure Symfony controller with constructor injection.
 * Uses the PHP-native Bridge services directly (ADR 3.1), bypassing API Platform entirely.
 *
 * Final+readonly: cannot be mocked by PHPUnit. Tests must use real service instances.
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
    ) {
    }

    public function __invoke(Request $request): JsonResponse
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
        $id = $body['id'] ?? null;
        $method = \is_string($body['method'] ?? null) ? (string) $body['method'] : '';

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => '2024-11-05',
                    'serverInfo'      => ['name' => 'mosyca-mcp-server', 'version' => '0.13.2'],
                    'capabilities'    => ['tools' => []],
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

        return [
            'content' => [
                ['type' => 'text', 'text' => (string) json_encode($actionResult)],
            ],
        ];
    }
}
