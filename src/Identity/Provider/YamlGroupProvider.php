<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Provider;

use Mosyca\Core\Identity\Dto\GroupDto;

/**
 * YAML-backed in-memory group provider (ADR 1.5.1).
 *
 * Receives the compiled `mosyca.identity.groups` container parameter via
 * constructor injection. Data is sourced from `mosyca.yaml` and compiled
 * into the container at boot.
 *
 * This is the default provider. Replace via DI alias to use a database-backed
 * implementation (e.g., from plugin-mosyca-admin-db).
 */
final class YamlGroupProvider implements GroupProviderInterface
{
    /**
     * @param array<string, array{roles?: string[], permissions?: string[]}> $groups
     */
    public function __construct(private readonly array $groups)
    {
    }

    public function getGroup(string $id): ?GroupDto
    {
        if (!isset($this->groups[$id])) {
            return null;
        }

        $data = $this->groups[$id];

        return new GroupDto(
            id: $id,
            roles: $data['roles'] ?? [],
            permissions: $data['permissions'] ?? [],
        );
    }

    public function getGroups(int $offset = 0, int $limit = 50): array
    {
        $dtos = array_map(
            static fn (string $id, array $data): GroupDto => new GroupDto(
                id: $id,
                roles: $data['roles'] ?? [],
                permissions: $data['permissions'] ?? [],
            ),
            array_keys($this->groups),
            array_values($this->groups),
        );

        return \array_slice($dtos, $offset, $limit);
    }
}
