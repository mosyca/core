<?php

declare(strict_types=1);

namespace Mosyca\Core\DependencyInjection\Compiler;

use Mosyca\Core\Resource\ResourceRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged "mosyca.resource" and wires them into ResourceRegistry.
 *
 * Works identically to ActionRegistrationPass.
 * Auto-tagging is set up in MosycaCoreExtension::load() via registerForAutoconfiguration(),
 * so any AbstractResource subclass registered as a service is tagged automatically.
 *
 * @see ResourceRegistry
 * @see \Mosyca\Core\DependencyInjection\MosycaCoreExtension::load()
 */
final class ResourceRegistrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ResourceRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(ResourceRegistry::class);

        foreach (array_keys($container->findTaggedServiceIds('mosyca.resource')) as $id) {
            $registry->addMethodCall('register', [new Reference($id)]);
        }
    }
}
