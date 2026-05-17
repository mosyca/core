<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Provider;

use Mosyca\Core\Identity\Dto\GroupDto;

/**
 * Group provider contract (ADR 1.5.1).
 *
 * All group identity retrieval in the Core MUST go through this interface.
 * The default implementation is YamlGroupProvider (YAML-backed, in-memory).
 * Plugins can replace it via Symfony DI alias (e.g., a Doctrine-backed provider).
 */
interface GroupProviderInterface
{
    /**
     * Returns the group DTO for the given id (slug), or null if not found.
     */
    public function getGroup(string $id): ?GroupDto;

    /**
     * Returns a paginated list of all groups.
     *
     * @return GroupDto[]
     */
    public function getGroups(int $offset = 0, int $limit = 50): array;
}
