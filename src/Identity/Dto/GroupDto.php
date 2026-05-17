<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Dto;

/**
 * Immutable Group data-transfer object (ADR 1.5.3).
 *
 * Returned by GroupProviderInterface. Never a Doctrine entity.
 */
readonly class GroupDto
{
    /**
     * @param string[] $roles
     * @param string[] $permissions
     */
    public function __construct(
        public string $id,
        public array $roles = [],
        public array $permissions = [],
    ) {
    }
}
