<?php

declare(strict_types=1);

namespace Mosyca\Core\Resource;

/**
 * Service registry for all Mosyca Resources.
 *
 * Resources are indexed by their compound key "{pluginNamespace}:{name}"
 * (e.g. "shopware6:order", "core:system"), which guarantees uniqueness across
 * plugins that may expose domains with the same local name (e.g. both
 * shopware6 and eventim could have an "order" resource).
 *
 * Populated at compile time via ResourceRegistrationPass (tag: mosyca.resource).
 * The registry is declared public so it can be fetched from the container
 * directly in tests and in the REST gateway.
 *
 * ## Indexing example
 *
 *   shopware6:order  → OrderResource (connector-shopware6)
 *   eventim:order    → EventimOrderResource (connector-eventim)
 *   core:system      → SystemResource (core)
 *
 * @see ResourceRegistrationPass
 * @see AbstractResource::getKey()
 */
final class ResourceRegistry
{
    /** @var array<string, AbstractResource> */
    private array $resources = [];

    /**
     * Register a resource in the registry.
     *
     * Called by ResourceRegistrationPass during container compilation.
     * If two resources share the same compound key, the last one registered wins.
     */
    public function register(AbstractResource $resource): void
    {
        $this->resources[$resource->getKey()] = $resource;
    }

    /**
     * Retrieve a resource by its compound key.
     *
     * @throws \InvalidArgumentException When no resource matches the given key
     */
    public function get(string $key): AbstractResource
    {
        if (!isset($this->resources[$key])) {
            throw new \InvalidArgumentException("Resource '{$key}' not found in registry.");
        }

        return $this->resources[$key];
    }

    /**
     * Check whether a resource is registered for the given compound key.
     */
    public function has(string $key): bool
    {
        return isset($this->resources[$key]);
    }

    /**
     * Return all registered resources, keyed by their compound key.
     *
     * @return array<string, AbstractResource>
     */
    public function all(): array
    {
        return $this->resources;
    }

    /**
     * Return all resources belonging to a given plugin namespace.
     *
     * Useful for the REST gateway to resolve which resources are available
     * under a specific plugin's URL prefix (e.g. all "shopware6:*" resources).
     *
     * @return array<string, AbstractResource>
     */
    public function forNamespace(string $pluginNamespace): array
    {
        return array_filter(
            $this->resources,
            static fn (AbstractResource $r): bool => $r->getPluginNamespace() === $pluginNamespace,
        );
    }
}
