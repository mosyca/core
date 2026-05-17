<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\Builtin\PingAction;
use Mosyca\Core\Context\ExecutionContext;

$registry = new ActionRegistry();
$registry->register(new PingAction());

// Build a minimal CLI context for standalone script usage.
$context = new ExecutionContext(
    tenantId: 'default',
    userId: null,
    actingUserId: null,
    delegated: false,
    authenticated: false,
    aclBypassed: false,
);

$result = $registry->get('mosyca:system:ping')->execute([], $context);

echo $result->summary.\PHP_EOL;
