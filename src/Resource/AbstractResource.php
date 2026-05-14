<?php

declare(strict_types=1);

namespace Mosyca\Core\Resource;

/**
 * Base class for all Mosyca Resources (ADR 2.1 — True-REST Protocol Adapter).
 *
 * A Resource is a pure protocol adapter: it declares how a domain entity's
 * operations are exposed via REST, CLI, and MCP. It contains no business logic.
 *
 * Resources are indexed in ResourceRegistry by their compound key
 * "{pluginNamespace}:{name}" (e.g. "shopware6:order", "core:system"),
 * which prevents collisions between plugins that expose identically-named domains.
 *
 * ## Channel mapping (generated automatically from getOperations()):
 *
 *   REST:  GET  /api/v1/{pluginNamespace}/{tenant}/{name}/{path}
 *   MCP:   flat tool  {pluginNamespace}_{name}_{operation}
 *   CLI:   bin/console {pluginNamespace}:{name}:{operation}
 *
 * @see ResourceRegistry
 */
abstract class AbstractResource
{
    /**
     * URL slug for this resource (e.g. 'order', 'system').
     *
     * Used as the {resource} path segment in REST routes and as the middle
     * segment in CLI commands ({pluginNamespace}:{name}:{operation}).
     */
    abstract public function getName(): string;

    /**
     * Plugin namespace this resource belongs to (e.g. 'shopware6', 'core').
     *
     * Combined with getName() to form the collision-free compound key.
     * Must match the plugin slug used in the REST base URL segment {plugin}.
     */
    abstract public function getPluginNamespace(): string;

    /**
     * Maps logical operation names to Action classes and their REST behaviour.
     *
     * Each entry defines three mandatory keys and one optional key:
     *   - 'action':        FQCN of the ActionInterface implementation (class-string)
     *   - 'method':        HTTP verb for the REST gateway (GET, POST, PUT, PATCH, DELETE)
     *   - 'path':          Path suffix appended to the resource base URL.
     *                      Use '' for the collection endpoint (e.g. POST /order).
     *                      Use '/{id}' for item endpoints (e.g. GET /order/123).
     *                      Use '/{id}/sub' for sub-resource paths.
     *   - 'openapiContext': (optional) Plain array forwarded verbatim to API Platform's
     *                      HttpOperation `openapiContext` parameter. Use this to enrich the
     *                      generated OpenAPI / Swagger documentation with summaries, response
     *                      schemas, examples, and security requirements — without losing the
     *                      dynamic routing provided by the gateway.
     *
     * Example (from connector-shopware6):
     * ```php
     * return [
     *     'read' => [
     *         'action' => ReadOrderAction::class,
     *         'method' => 'GET',
     *         'path'   => '/{id}',
     *         'openapiContext' => [
     *             'summary'     => 'Fetch a single Shopware 6 order by ID.',
     *             'description' => 'Returns full order detail including line items and totals.',
     *         ],
     *     ],
     *     'margin' => [
     *         'action' => GetMarginAction::class,
     *         'method' => 'GET',
     *         'path'   => '/{id}/margin',
     *     ],
     *     'create' => [
     *         'action' => CreateOrderAction::class,
     *         'method' => 'POST',
     *         'path'   => '',
     *     ],
     * ];
     * ```
     *
     * ADR 2.4: Path variables (e.g. {id}) are extracted by the REST gateway and
     * merged into $payload as flat key-value pairs before validation is run.
     * The Action itself never sees raw HTTP paths.
     *
     * @return array<string, array{action: class-string, method: string, path: string, openapiContext?: array<string, mixed>}>
     */
    abstract public function getOperations(): array;

    /**
     * Human-readable description of this resource domain.
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * Compound registry key: "{pluginNamespace}:{name}".
     *
     * Examples: "shopware6:order", "core:system", "eventim:event"
     *
     * This key is what ResourceRegistry uses for lookup and what the REST gateway
     * uses to resolve the correct Resource from a request URL.
     */
    final public function getKey(): string
    {
        return $this->getPluginNamespace().':'.$this->getName();
    }
}
