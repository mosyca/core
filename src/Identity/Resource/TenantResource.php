<?php

declare(strict_types=1);

namespace Mosyca\Core\Identity\Resource;

use Mosyca\Core\Action\Identity\TenantListAction;
use Mosyca\Core\Action\Identity\TenantReadAction;
use Mosyca\Core\Resource\AbstractResource;

/**
 * Core read-only Tenant resource (ADR 1.5.2).
 *
 * Exposes only list and read operations. No mutations.
 *
 * ## Channel exposure
 *
 *   REST:
 *     GET /api/v1/mosyca/{tenant}/tenant       → TenantListAction
 *     GET /api/v1/mosyca/{tenant}/tenant/{id}  → TenantReadAction
 *
 *   MCP tools:
 *     mosyca_tenant_list
 *     mosyca_tenant_read
 *
 *   CLI commands:
 *     bin/console mosyca:tenant:list
 *     bin/console mosyca:tenant:read
 */
final class TenantResource extends AbstractResource
{
    public function getPluginNamespace(): string
    {
        return 'mosyca';
    }

    public function getName(): string
    {
        return 'tenant';
    }

    public function getDescription(): string
    {
        return 'Core read-only tenant identity operations.';
    }

    /**
     * @return array<string, array{action: class-string, method: string, path: string}>
     */
    public function getOperations(): array
    {
        return [
            'list' => [
                'action' => TenantListAction::class,
                'method' => 'GET',
                'path' => '',
            ],
            'read' => [
                'action' => TenantReadAction::class,
                'method' => 'GET',
                'path' => '/{id}',
            ],
        ];
    }
}
