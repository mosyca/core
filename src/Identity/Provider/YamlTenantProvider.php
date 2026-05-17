<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Provider;

use Mosyca\Core\Identity\Dto\TenantDto;

/**
 * YAML-backed in-memory tenant provider (ADR 1.5.1).
 *
 * Receives the compiled `mosyca.identity.tenants` container parameter via
 * constructor injection. Data is sourced from `mosyca.yaml` and compiled
 * into the container at boot — reads are pure array lookups (O(1)/O(n)).
 *
 * This is the default provider. Replace via DI alias to use a database-backed
 * implementation (e.g., from plugin-mosyca-admin-db).
 */
final class YamlTenantProvider implements TenantProviderInterface
{
    /**
     * @param array<string, array{name: string, metadata?: array<string, mixed>}> $tenants
     */
    public function __construct(private readonly array $tenants)
    {
    }

    public function getTenant(string $slug): ?TenantDto
    {
        if (!isset($this->tenants[$slug])) {
            return null;
        }

        return new TenantDto(
            slug: $slug,
            name: $this->tenants[$slug]['name'],
            metadata: $this->tenants[$slug]['metadata'] ?? [],
        );
    }

    public function getTenants(int $offset = 0, int $limit = 50): array
    {
        $dtos = array_map(
            static fn (string $slug, array $data): TenantDto => new TenantDto(
                slug: $slug,
                name: $data['name'],
                metadata: $data['metadata'] ?? [],
            ),
            array_keys($this->tenants),
            array_values($this->tenants),
        );

        return \array_slice($dtos, $offset, $limit);
    }
}
