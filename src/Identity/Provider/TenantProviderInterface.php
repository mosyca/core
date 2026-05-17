<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Provider;

use Mosyca\Core\Identity\Dto\TenantDto;

/**
 * Tenant provider contract (ADR 1.5.1).
 *
 * All tenant identity retrieval in the Core MUST go through this interface.
 * The default implementation is YamlTenantProvider (YAML-backed, in-memory).
 * Plugins can replace it via Symfony DI alias (e.g., a Doctrine-backed provider).
 */
interface TenantProviderInterface
{
    /**
     * Returns the tenant DTO for the given slug, or null if not found.
     */
    public function getTenant(string $slug): ?TenantDto;

    /**
     * Returns a paginated list of all tenants.
     *
     * @return TenantDto[]
     */
    public function getTenants(int $offset = 0, int $limit = 50): array;
}
