<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Provider;

use Mosyca\Core\Identity\Dto\UserDto;

/**
 * YAML-backed in-memory user provider (ADR 1.5.1).
 *
 * Receives the compiled `mosyca.identity.users` container parameter via
 * constructor injection. Data is sourced from `mosyca.yaml` and compiled
 * into the container at boot.
 *
 * This is the default provider. Replace via DI alias to use a database-backed
 * implementation (e.g., from plugin-mosyca-admin-db).
 */
final class YamlUserProvider implements UserProviderInterface
{
    /**
     * @param array<string, array{email: string, display_name?: string|null, groups?: string[], allowed_tenants?: string[]}> $users
     */
    public function __construct(private readonly array $users)
    {
    }

    public function getUser(string $id): ?UserDto
    {
        if (!isset($this->users[$id])) {
            return null;
        }

        $data = $this->users[$id];

        return new UserDto(
            id: $id,
            email: $data['email'],
            displayName: $data['display_name'] ?? null,
            groups: $data['groups'] ?? [],
            allowedTenants: $data['allowed_tenants'] ?? [],
        );
    }

    public function getUsersByTenant(string $tenantSlug): array
    {
        $result = [];

        foreach ($this->users as $id => $data) {
            $allowedTenants = $data['allowed_tenants'] ?? [];
            if (\in_array($tenantSlug, $allowedTenants, true)) {
                $result[] = new UserDto(
                    id: $id,
                    email: $data['email'],
                    displayName: $data['display_name'] ?? null,
                    groups: $data['groups'] ?? [],
                    allowedTenants: $allowedTenants,
                );
            }
        }

        return $result;
    }
}
