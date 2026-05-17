<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Provider;

use Mosyca\Core\Identity\Dto\UserDto;

/**
 * User provider contract (ADR 1.5.1).
 *
 * All user identity retrieval in the Core MUST go through this interface.
 * The default implementation is YamlUserProvider (YAML-backed, in-memory).
 * Plugins can replace it via Symfony DI alias (e.g., a Doctrine-backed provider).
 */
interface UserProviderInterface
{
    /**
     * Returns the user DTO for the given id (slug), or null if not found.
     */
    public function getUser(string $id): ?UserDto;

    /**
     * Returns all users that belong to the given tenant slug.
     *
     * @return UserDto[]
     */
    public function getUsersByTenant(string $tenantSlug): array;
}
