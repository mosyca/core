<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Builtin\Resource;

use Mosyca\Core\Action\Builtin\AssumeTenantAction;
use Mosyca\Core\Action\Builtin\EchoAction;
use Mosyca\Core\Action\Builtin\PingAction;
use Mosyca\Core\Resource\AbstractResource;

/**
 * Built-in system resource — exposes the core health-check actions.
 *
 * Compound key: "core:system"
 *
 * ## Channel exposure
 *
 *   REST:
 *     GET  /api/v1/core/{tenant}/system/ping          → PingAction
 *     POST /api/v1/core/{tenant}/system/echo          → EchoAction
 *     POST /api/v1/core/{tenant}/system/assume-tenant → AssumeTenantAction
 *
 *   MCP tools:
 *     core_system_ping
 *     core_system_echo
 *     core_system_assume_tenant
 *
 *   CLI commands:
 *     bin/console core:system:ping
 *     bin/console core:system:echo
 *     bin/console core:system:assume_tenant
 */
final class SystemResource extends AbstractResource
{
    public function getPluginNamespace(): string
    {
        return 'core';
    }

    public function getName(): string
    {
        return 'system';
    }

    public function getDescription(): string
    {
        return 'Core health-check and diagnostic operations.';
    }

    /**
     * @return array<string, array{action: class-string, method: string, path: string}>
     */
    public function getOperations(): array
    {
        return [
            'ping' => [
                'action' => PingAction::class,
                'method' => 'GET',
                'path' => '/ping',            // → GET  /api/v1/core/{tenant}/system/ping
            ],
            'echo' => [
                'action' => EchoAction::class,
                'method' => 'POST',
                'path' => '/echo',            // → POST /api/v1/core/{tenant}/system/echo
            ],
            'assume_tenant' => [
                'action' => AssumeTenantAction::class,
                'method' => 'POST',
                'path' => '/assume-tenant',   // → POST /api/v1/core/{tenant}/system/assume-tenant
            ],                                //   MCP: core_system_assume_tenant
        ];
    }
}
