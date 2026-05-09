<?php

declare(strict_types=1);

namespace Mosyca\Core\DependencyInjection;

use Mosyca\Core\Plugin\PluginInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class MosycaCoreExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');

        // Global auto-tagging: any service (in any bundle or application namespace)
        // that implements PluginInterface gets the mosyca.plugin tag automatically.
        // This works across YAML files — unlike _instanceof which is file-scoped.
        $container->registerForAutoconfiguration(PluginInterface::class)
            ->addTag('mosyca.plugin');
    }
}
