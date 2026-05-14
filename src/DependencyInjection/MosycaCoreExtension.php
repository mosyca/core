<?php

declare(strict_types=1);

namespace Mosyca\Core\DependencyInjection;

use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Depot\DepotInterface;
use Mosyca\Core\Depot\FilesystemDepot;
use Mosyca\Core\Gateway\Metadata\ResourceMetadataFactory;
use Mosyca\Core\Resource\AbstractResource;
use Mosyca\Core\Resource\ResourceRegistry;
use Mosyca\Core\Vault\Clearance\ClearanceRegistry;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class MosycaCoreExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');

        // Vault services require Doctrine ORM — only load when DoctrineBundle is registered.
        // NOTE: hasExtension() cannot be used here because load() receives a sub-container
        // (MergeExtensionConfigurationContainerBuilder) with no extensions registered.
        // kernel.bundles IS available because the sub-container inherits the parameter bag.
        $bundles = $container->getParameter('kernel.bundles');
        if (\is_array($bundles) && isset($bundles['DoctrineBundle'])) {
            $loader->load('services_vault.yaml');
        }

        // Global auto-tagging: any service (in any bundle or application namespace)
        // that implements ActionInterface gets the mosyca.action tag automatically.
        // This works across YAML files — unlike _instanceof which is file-scoped.
        $container->registerForAutoconfiguration(ActionInterface::class)
            ->addTag('mosyca.action');

        // Global auto-tagging: any AbstractResource subclass registered as a service
        // gets the mosyca.resource tag automatically (same cross-bundle scope as above).
        $container->registerForAutoconfiguration(AbstractResource::class)
            ->addTag('mosyca.resource');

        // V0.11: ResourceMetadataFactory decorator — only when ApiPlatformBundle is loaded.
        // The decorator requires api_platform.metadata.resource.metadata_collection_factory to
        // exist, which is only registered by ApiPlatformBundle. Registering unconditionally
        // causes a ServiceNotFoundException in test kernels that don't load API Platform.
        if (\is_array($bundles) && isset($bundles['ApiPlatformBundle'])) {
            $factoryDef = new Definition(ResourceMetadataFactory::class);
            $factoryDef->setAutowired(true);
            $factoryDef->setDecoratedService('api_platform.metadata.resource.metadata_collection_factory');
            $factoryDef->setArgument('$inner', new Reference(ResourceMetadataFactory::class.'.inner'));
            $factoryDef->setArgument('$registry', new Reference(ResourceRegistry::class));
            $container->setDefinition(ResourceMetadataFactory::class, $factoryDef);
        }

        // ClearanceRegistry — optional custom YAML path from project config/mosyca/clearances.yaml
        $projectDir = $container->getParameter('kernel.project_dir');
        \assert(\is_string($projectDir));

        $clearancesYaml = $projectDir.'/config/mosyca/clearances.yaml';
        $def = new Definition(ClearanceRegistry::class, [
            file_exists($clearancesYaml) ? $clearancesYaml : null,
        ]);
        $def->setPublic(false);
        $container->setDefinition(ClearanceRegistry::class, $def);

        // Depot — optional. Enabled when mosyca.depot_dir is set (defaults to var/depot).
        $depotDir = $projectDir.'/var/depot';
        $depotDef = new Definition(FilesystemDepot::class, [$depotDir]);
        $depotDef->setPublic(false);
        $container->setDefinition(FilesystemDepot::class, $depotDef);
        $container->setAlias(DepotInterface::class, FilesystemDepot::class);

        // Ledger log dir — defaults to var/log/mosyca
        $logDir = $projectDir.'/var/log/mosyca';
        $container->setParameter('mosyca.log_dir', $logDir);
        $container->setParameter('mosyca.depot_dir', $depotDir);
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
