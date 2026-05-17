<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Dto;

/**
 * Immutable User data-transfer object (ADR 1.5.3).
 *
 * Returned by UserProviderInterface. Never a Doctrine entity.
 */
readonly class UserDto
{
    /**
     * @param string[] $groups
     * @param string[] $allowedTenants
     */
    public function __construct(
        public string $id,
        public string $email,
        public ?string $displayName = null,
        public array $groups = [],
        public array $allowedTenants = [],
    ) {
    }
}
