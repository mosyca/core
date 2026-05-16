<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Exception;

/**
 * Thrown when no active (non-expired) Vault secret exists for a given context.
 *
 * This exception is intentionally surfaced to CLI administrators with full context
 * (tenant, integration, user) to aid diagnostics.
 *
 * At the JSON-RPC / MCP layer (V0.14d) this exception MUST be caught and converted
 * to ActionResult::authRequired() — the raw exception must never reach the LLM client.
 */
final class SecretNotFoundException extends \RuntimeException
{
    public static function forContext(
        string $tenantId,
        string $integrationType,
        ?string $userId = null,
    ): self {
        $scope = null !== $userId
            ? \sprintf(' (user: %s)', $userId)
            : '';

        return new self(\sprintf(
            'No active vault secret found for tenant "%s", integration "%s"%s.',
            $tenantId,
            $integrationType,
            $scope,
        ));
    }
}
