<?php

declare(strict_types=1);

namespace Mosyca\Core\Context;

/**
 * Immutable execution context value object.
 *
 * Created exclusively by ContextProvider — never instantiate directly in plugins.
 *
 * All properties are readonly — the object cannot be mutated after creation.
 * This ensures that context state is consistent throughout the entire plugin
 * execution chain.
 */
final class ExecutionContext implements ExecutionContextInterface
{
    public function __construct(
        private readonly string $tenantId,
        private readonly ?string $userId,
        private readonly ?string $actingUserId,
        private readonly bool $delegated,
        private readonly bool $authenticated,
        private readonly bool $aclBypassed,
    ) {
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getActingUserId(): ?string
    {
        return $this->actingUserId;
    }

    public function isDelegated(): bool
    {
        return $this->delegated;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function isAclBypassed(): bool
    {
        return $this->aclBypassed;
    }
}
