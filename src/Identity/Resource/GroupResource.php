<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Resource;

use Mosyca\Core\Action\Identity\GroupListAction;
use Mosyca\Core\Action\Identity\GroupReadAction;
use Mosyca\Core\Resource\AbstractResource;

/**
 * Core read-only Group resource (ADR 1.5.2).
 *
 * Exposes only list and read operations. No mutations.
 *
 * ## Channel exposure
 *
 *   REST:
 *     GET /api/v1/mosyca/{tenant}/group       → GroupListAction
 *     GET /api/v1/mosyca/{tenant}/group/{id}  → GroupReadAction
 *
 *   MCP tools:
 *     mosyca_group_list
 *     mosyca_group_read
 *
 *   CLI commands:
 *     bin/console mosyca:group:list
 *     bin/console mosyca:group:read
 */
final class GroupResource extends AbstractResource
{
    public function getPluginNamespace(): string
    {
        return 'mosyca';
    }

    public function getName(): string
    {
        return 'group';
    }

    public function getDescription(): string
    {
        return 'Core read-only group identity operations.';
    }

    /**
     * @return array<string, array{action: class-string, method: string, path: string}>
     */
    public function getOperations(): array
    {
        return [
            'list' => [
                'action' => GroupListAction::class,
                'method' => 'GET',
                'path' => '',
            ],
            'read' => [
                'action' => GroupReadAction::class,
                'method' => 'GET',
                'path' => '/{id}',
            ],
        ];
    }
}
