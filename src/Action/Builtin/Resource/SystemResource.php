<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Builtin\Resource;

use Mosyca\Core\Action\Builtin\AssumeTenantAction;
use Mosyca\Core\Action\Builtin\EchoAction;
use Mosyca\Core\Action\Builtin\PingAction;
use Mosyca\Core\Resource\AbstractResource;

/**
 * Built-in system resource — exposes the mosyca framework health-check actions.
 *
 * Compound key: "mosyca:system"
 *
 * ## Channel exposure
 *
 *   REST:
 *     GET  /api/v1/mosyca/{tenant}/system/ping          → PingAction
 *     POST /api/v1/mosyca/{tenant}/system/echo          → EchoAction
 *     POST /api/v1/mosyca/{tenant}/system/assume-tenant → AssumeTenantAction
 *
 *   MCP tools:
 *     mosyca_system_ping
 *     mosyca_system_echo
 *     mosyca_system_assume_tenant
 *
 *   CLI commands:
 *     bin/console mosyca:system:ping
 *     bin/console mosyca:system:echo
 *     bin/console mosyca:system:assume_tenant
 */
final class SystemResource extends AbstractResource
{
    public function getPluginNamespace(): string
    {
        return 'mosyca';
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
                'path' => '/ping',            // → GET  /api/v1/mosyca/{tenant}/system/ping
            ],
            'echo' => [
                'action' => EchoAction::class,
                'method' => 'POST',
                'path' => '/echo',            // → POST /api/v1/mosyca/{tenant}/system/echo
            ],
            'assume_tenant' => [
                'action' => AssumeTenantAction::class,
                'method' => 'POST',
                'path' => '/assume-tenant',   // → POST /api/v1/mosyca/{tenant}/system/assume-tenant
            ],                                //   MCP: mosyca_system_assume_tenant
        ];
    }
}
