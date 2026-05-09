<?php

declare(strict_types=1);

namespace Mosyca\Core\DependencyInjection\Compiler;

use Mosyca\Core\Console\ConsoleAdapter;
use Mosyca\Core\Console\PluginChainCommandLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Wraps Symfony's ContainerCommandLoader with a ChainCommandLoader that also
 * includes the ConsoleAdapter, so every registered plugin appears as a
 * bin/console command with the plugin's name.
 *
 * Runs at priority -10 — after AddConsoleCommandPass (priority 0) has already
 * created the console.command_loader service from #[AsCommand]-tagged services.
 */
final class PluginCommandLoaderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ConsoleAdapter::class)) {
            return;
        }

        if (!$container->has('console.command_loader')) {
            // No existing loader — promote ConsoleAdapter directly.
            $container->setAlias('console.command_loader', ConsoleAdapter::class)->setPublic(true);

            return;
        }

        // Move the existing loader aside, then chain it with ConsoleAdapter.
        $original = $container->getDefinition('console.command_loader');
        $container->setDefinition('console.command_loader.builtin', $original);

        $chain = new Definition(PluginChainCommandLoader::class, [
            new Reference('console.command_loader.builtin'),
            new Reference(ConsoleAdapter::class),
        ]);
        $chain->setPublic(true);

        $container->setDefinition('console.command_loader', $chain);
    }
}
