<?php

declare(strict_types=1);

namespace Mosyca\Core\DependencyInjection;

use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Vault\Clearance\ClearanceRegistry;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class MosycaCoreExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');

        // Vault services require Doctrine ORM — only load when DoctrineBundle is present.
        if ($container->hasExtension('doctrine')) {
            $loader->load('services_vault.yaml');
        }

        // Global auto-tagging: any service (in any bundle or application namespace)
        // that implements PluginInterface gets the mosyca.plugin tag automatically.
        // This works across YAML files — unlike _instanceof which is file-scoped.
        $container->registerForAutoconfiguration(PluginInterface::class)
            ->addTag('mosyca.plugin');

        // ClearanceRegistry — optional custom YAML path from project config/mosyca/clearances.yaml
        $projectDir = $container->getParameter('kernel.project_dir');
        $clearancesYaml = \is_string($projectDir)
            ? $projectDir.'/config/mosyca/clearances.yaml'
            : null;

        $def = new Definition(ClearanceRegistry::class, [
            null !== $clearancesYaml && file_exists($clearancesYaml) ? $clearancesYaml : null,
        ]);
        $def->setPublic(false);
        $container->setDefinition(ClearanceRegistry::class, $def);
    }

    /**
     * Prepend Doctrine ORM entity mapping for Mosyca Core Vault entities.
     * Works only when DoctrineBundle is loaded (optional in non-Vault setups).
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'MosycaCore' => [
                        'type' => 'attribute',
                        'prefix' => 'Mosyca\\Core\\',
                        'dir' => \dirname(__DIR__),
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);
    }
}
