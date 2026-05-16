<?php

declare(strict_types=1);

namespace Mosyca\Core\Bridge;

use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Bridge\TenantSession\TenantSessionInterceptor;
use Mosyca\Core\Context\ExecutionContext;
use Mosyca\Core\Resource\ResourceRegistry;

/**
 * MCP tool execution service for the PHP-native Bridge (V0.13 — ADR 3.1 + 3.3 + 3.4).
 *
 * Implements the `call_tool` side of the MCP Bridge.
 *
 * ## Execution chain (ADR 3.4)
 *
 *  1. Extract `arguments['tenant']` → ExecutionContext::$tenantId
 *  2. Remove `tenant` from the payload (Action validators must not see it)
 *  3. Resolve the Action from the tool name via ResourceRegistry (ADR 3.1)
 *  4. Execute: $action->execute($payload, $context)
 *  5. Return ActionResult::toArray() as the MCP JSON-RPC response body
 *
 * ## Tool name → Action resolution
 *
 *   Tool name format: {ns}_{resource}_{opSlug}
 *   Algorithm: iterate ResourceRegistry, find resource whose "{ns}_{resource}_" is
 *   a prefix of the tool name, extract opSlug, look up the operation's action FQCN,
 *   resolve via ActionRegistry::getByClass().
 *
 * @see McpDiscoveryService
 * @see ResourceRegistry
 * @see ActionRegistry
 * @see TenantSessionInterceptor
 */
final readonly class McpExecutionService
{
    public function __construct(
        private ResourceRegistry $resourceRegistry,
        private ActionRegistry $actionRegistry,
        private ?TenantSessionInterceptor $interceptor = null,
    ) {
    }

    /**
     * Execute the action behind the given MCP tool name.
     *
     * @param array<string, mixed> $arguments Full MCP arguments (includes 'tenant')
     *
     * @return array<string, mixed> ActionResult::toArray() — the MCP response body
     *
     * @throws \InvalidArgumentException When the tool name cannot be resolved to an action
     */
    public function callTool(string $toolName, array $arguments): array
    {
        // OOB-CA (V0.16): intercept calls that carry _mcp_context_token BEFORE tenant extraction.
        // - No token → null (pass-through, no-op).
        // - PENDING/DENIED/invalid → array (short-circuit ActionResult — action does not run).
        // - ACTIVE → null ($arguments['tenant'] mutated to JWT-authorised tenant_id).
        if (null !== $this->interceptor) {
            $interceptorResult = $this->interceptor->intercept($arguments);
            if (null !== $interceptorResult) {
                return $interceptorResult;
            }
        }

        // ADR 3.4 step 1: extract tenant.
        $tenantId = \is_string($arguments['tenant'] ?? null)
            ? (string) $arguments['tenant']
            : 'default';

        // ADR 3.4 step 2: remove tenant so Action validators never see it.
        $payload = $arguments;
        unset($payload['tenant']);

        // ADR 3.1: resolve action directly from ResourceRegistry — no REST.
        $action = $this->resolveAction($toolName);

        $context = new ExecutionContext(
            tenantId: $tenantId,
            userId: null,
            actingUserId: null,
            delegated: false,
            authenticated: false,
            aclBypassed: false,
        );

        return $action->execute($payload, $context)->toArray();
    }

    /**
     * Locate the action for a flat MCP tool name by iterating the ResourceRegistry.
     *
     * @throws \InvalidArgumentException When no matching resource + operation is found
     */
    private function resolveAction(string $toolName): ActionInterface
    {
        foreach ($this->resourceRegistry->all() as $resource) {
            $prefix = $resource->getPluginNamespace().'_'.$resource->getName().'_';

            if (str_starts_with($toolName, $prefix)) {
                $opSlug = substr($toolName, \strlen($prefix));
                $operations = $resource->getOperations();

                if (isset($operations[$opSlug])) {
                    return $this->actionRegistry->getByClass($operations[$opSlug]['action']);
                }
            }
        }

        throw new \InvalidArgumentException(\sprintf("No action found for MCP tool name '%s'.", $toolName));
    }
}
