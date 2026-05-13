<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Mosyca\Core\Gateway\Processor\PluginRunProcessor;
use Mosyca\Core\Gateway\Provider\PluginProvider;

/**
 * Plugin REST Resource.
 *
 * API Platform generates all endpoints, OpenAPI entries,
 * and Swagger UI entries automatically from this class.
 *
 * Plugin names follow the {connector}:{resource}:{action} convention.
 * The three segments are mapped to separate URL path segments to produce
 * clean, encoding-free URIs.
 *
 * Route pattern (V0.9+, ADR 1.5 compliant):
 *   GET  /api/v1/plugins                                              → list all plugins
 *   GET  /api/v1/{plugin_name}/{tenant}/{resource}/{action}           → plugin detail
 *   POST /api/v1/{plugin_name}/{tenant}/{resource}/{action}/run       → execute plugin
 *
 * Examples:
 *   POST /api/v1/core/default/system/ping/run           ← built-in, default tenant
 *   POST /api/v1/shopware/default/order/get-margin/run  ← shopware, single-tenant
 *   POST /api/v1/shopware/shop-berlin/order/get-margin/run ← shopware, multi-tenant
 *
 * URI variables:
 *   plugin_name  → first segment of the plugin identifier (e.g. core, shopware)
 *   tenant       → tenant identifier (e.g. default, shop-berlin)
 *   resource     → second segment (e.g. system, order)
 *   action       → third segment (e.g. ping, get-margin)
 *
 * Internal plugin name: {plugin_name}:{resource}:{action}
 */
#[ApiResource(
    shortName: 'Plugin',
    description: 'Mosyca Plugins – executable capabilities.',
    operations: [
        new GetCollection(
            uriTemplate: '/v1/plugins',
            description: 'List all registered plugins.',
            provider: PluginProvider::class,
        ),
        new Get(
            uriTemplate: '/v1/{plugin_name}/{tenant}/{resource}/{action}',
            description: 'Get plugin metadata and parameter schema.',
            provider: PluginProvider::class,
        ),
        new Post(
            uriTemplate: '/v1/{plugin_name}/{tenant}/{resource}/{action}/run',
            description: 'Execute a plugin. Body: {"args":{...},"_format":"json","_template":null}.',
            input: false,
            output: false,
            processor: PluginRunProcessor::class,
        ),
    ],
    formats: ['json'],
    paginationEnabled: false,
)]
final class PluginResource
{
    /**
     * Plugin name segment (first part of plugin_name:resource:action).
     *
     * Example: core, shopware, spotify
     */
    #[ApiProperty(identifier: true, description: 'Plugin name segment (e.g. core, shopware).')]
    public string $plugin_name = '';

    /**
     * Tenant identifier.
     *
     * Example: default, shop-berlin, shop-munich
     */
    #[ApiProperty(identifier: true, description: 'Tenant identifier (e.g. default, shop-berlin).')]
    public string $tenant = '';

    /**
     * Resource segment of the plugin name (second part of plugin_name:resource:action).
     *
     * Example: system, order, product
     */
    #[ApiProperty(identifier: true, description: 'Resource segment (e.g. system, order).')]
    public string $resource = '';

    /**
     * Action segment of the plugin name (third part of plugin_name:resource:action).
     *
     * Example: ping, get-margin, list
     */
    #[ApiProperty(identifier: true, description: 'Action segment (e.g. ping, get-margin).')]
    public string $action = '';

    /**
     * Full canonical plugin name — convenience field, not a URL identifier.
     *
     * Example: core:system:ping
     */
    #[ApiProperty(identifier: false, description: 'Full plugin name (plugin_name:resource:action).')]
    public string $name = '';

    #[ApiProperty(description: 'One-line description shown in Swagger UI and MCP list_tools.')]
    public string $description = '';

    #[ApiProperty(description: 'Full usage documentation (Markdown).')]
    public string $usage = '';

    /**
     * Parameter schema — also the MCP inputSchema.
     *
     * @var array<string, array<string, mixed>>
     */
    #[ApiProperty(description: 'Parameter definitions (name → {type, description, required, default, enum}).')]
    public array $parameters = [];

    /**
     * JSON Schema derived from parameters — ready for MCP list_tools response.
     *
     * @var array<string, mixed>
     */
    #[ApiProperty(description: 'JSON Schema for MCP inputSchema.')]
    public array $jsonSchema = [];

    /**
     * @var string[]
     */
    #[ApiProperty(description: 'Required OAuth scopes / API permissions.')]
    public array $requiredScopes = [];

    /**
     * @var string[]
     */
    #[ApiProperty(description: 'Tags for grouping and discovery.')]
    public array $tags = [];

    #[ApiProperty(description: 'true = writes data (side effects), false = read-only.')]
    public bool $mutating = false;

    #[ApiProperty(description: 'Default output format: json|yaml|raw|table|text|mcp.')]
    public string $defaultFormat = 'json';

    #[ApiProperty(description: 'Default Twig template name. null = generic default.')]
    public ?string $defaultTemplate = null;
}
