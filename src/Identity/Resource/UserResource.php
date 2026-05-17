<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Resource;

use Mosyca\Core\Action\Identity\UserListAction;
use Mosyca\Core\Action\Identity\UserReadAction;
use Mosyca\Core\Resource\AbstractResource;

/**
 * Core read-only User resource (ADR 1.5.2).
 *
 * Exposes only list and read operations. No mutations.
 *
 * ## Channel exposure
 *
 *   REST:
 *     GET /api/v1/mosyca/{tenant}/user       → UserListAction
 *     GET /api/v1/mosyca/{tenant}/user/{id}  → UserReadAction
 *
 *   MCP tools:
 *     mosyca_user_list
 *     mosyca_user_read
 *
 *   CLI commands:
 *     bin/console mosyca:user:list
 *     bin/console mosyca:user:read
 */
final class UserResource extends AbstractResource
{
    public function getPluginNamespace(): string
    {
        return 'mosyca';
    }

    public function getName(): string
    {
        return 'user';
    }

    public function getDescription(): string
    {
        return 'Core read-only user identity operations.';
    }

    /**
     * @return array<string, array{action: class-string, method: string, path: string}>
     */
    public function getOperations(): array
    {
        return [
            'list' => [
                'action' => UserListAction::class,
                'method' => 'GET',
                'path' => '',
            ],
            'read' => [
                'action' => UserReadAction::class,
                'method' => 'GET',
                'path' => '/{id}',
            ],
        ];
    }
}
