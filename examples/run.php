<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Mosyca\Core\Context\ExecutionContext;
use Mosyca\Core\Plugin\Builtin\PingPlugin;
use Mosyca\Core\Plugin\PluginRegistry;

$registry = new PluginRegistry();
$registry->register(new PingPlugin());

// Build a minimal CLI context for standalone script usage.
$context = new ExecutionContext(
    tenantId: 'default',
    userId: null,
    actingUserId: null,
    delegated: false,
    authenticated: false,
    aclBypassed: false,
);

$result = $registry->get('core:system:ping')->execute([], $context);

echo $result->summary.\PHP_EOL;
