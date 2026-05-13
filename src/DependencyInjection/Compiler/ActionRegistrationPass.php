<?php

declare(strict_types=1);

namespace Mosyca\Core\DependencyInjection\Compiler;

use Mosyca\Core\Action\ActionRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ActionRegistrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ActionRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(ActionRegistry::class);

        foreach (array_keys($container->findTaggedServiceIds('mosyca.action')) as $id) {
            $registry->addMethodCall('register', [new Reference($id)]);
        }
    }
}
