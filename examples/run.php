<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Mosyca\Core\Examples\PingPlugin;
use Mosyca\Core\Plugin\PluginRegistry;

$registry = new PluginRegistry();
$registry->register(new PingPlugin());

$result = $registry->get('core:system:ping')->execute([]);

echo $result->summary.\PHP_EOL;
