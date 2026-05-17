<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Dto;

/**
 * Immutable Tenant data-transfer object (ADR 1.5.3).
 *
 * Returned by TenantProviderInterface. Never a Doctrine entity.
 */
readonly class TenantDto
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $metadata = [],
    ) {
    }
}
