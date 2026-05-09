<?php

declare(strict_types=1);

namespace Mosyca\Core\DependencyInjection\Compiler;

use Mosyca\Core\Plugin\PluginRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class PluginRegistrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(PluginRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(PluginRegistry::class);

        foreach (array_keys($container->findTaggedServiceIds('mosyca.plugin')) as $id) {
            $registry->addMethodCall('register', [new Reference($id)]);
        }
    }
}
