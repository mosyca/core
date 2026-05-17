<?php

declare(strict_types=1);

namespace Mosyca\Core\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Mosyca Core — Symfony Configuration tree.
 *
 * Defines and validates the `mosyca` configuration key (ADR 1.5.1).
 *
 * ## Example mosyca.yaml
 *
 * ```yaml
 * mosyca:
 *   identity:
 *     tenants:
 *       demecan_gmbh:
 *         name: "Demecan GmbH"
 *         metadata: { region: "EU" }
 *     users:
 *       operator_roland:
 *         email: "r.urban@example.com"
 *         display_name: "Roland Urban"
 *         groups: ['admins']
 *         allowed_tenants: ['demecan_gmbh']
 *     groups:
 *       admins:
 *         roles: ['ROLE_MOSYCA_ADMIN']
 *         permissions: ['*']
 * ```
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('mosyca');
        $root = $tree->getRootNode();

        $root
            ->children()
                ->arrayNode('identity')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('tenants')
                            ->useAttributeAsKey('slug')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                                    ->arrayNode('metadata')
                                        ->scalarPrototype()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('users')
                            ->useAttributeAsKey('id')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('email')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('display_name')->defaultNull()->end()
                                    ->arrayNode('groups')
                                        ->scalarPrototype()->end()
                                    ->end()
                                    ->arrayNode('allowed_tenants')
                                        ->scalarPrototype()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('groups')
                            ->useAttributeAsKey('id')
                            ->arrayPrototype()
                                ->children()
                                    ->arrayNode('roles')
                                        ->scalarPrototype()->end()
                                    ->end()
                                    ->arrayNode('permissions')
                                        ->scalarPrototype()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $tree;
    }
}
