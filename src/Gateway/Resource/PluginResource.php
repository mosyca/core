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
 * clean, encoding-free URIs:
 *
 * Routes:
 *   GET  /api/plugins                                  → list all plugins
 *   GET  /api/plugins/{connector}/{resource}/{action}  → plugin detail + parameter schema
 *   POST /api/plugins/{connector}/{resource}/{action}/run → execute plugin
 */
#[ApiResource(
    shortName: 'Plugin',
    description: 'Mosyca Plugins – executable capabilities.',
    operations: [
        new GetCollection(
            uriTemplate: '/plugins',
            description: 'List all registered plugins.',
            provider: PluginProvider::class,
        ),
        new Get(
            uriTemplate: '/plugins/{connector}/{resource}/{action}',
            description: 'Get plugin metadata and parameter schema.',
            provider: PluginProvider::class,
        ),
        new Post(
            uriTemplate: '/plugins/{connector}/{resource}/{action}/run',
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
     * Connector segment of the plugin name (first part of connector:resource:action).
     *
     * Example: core, shopware, spotify
     */
    #[ApiProperty(identifier: true, description: 'Connector segment (e.g. core, shopware).')]
    public string $connector = '';

    /**
     * Resource segment of the plugin name (second part of connector:resource:action).
     *
     * Example: system, order, product
     */
    #[ApiProperty(identifier: true, description: 'Resource segment (e.g. system, order).')]
    public string $resource = '';

    /**
     * Action segment of the plugin name (third part of connector:resource:action).
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
    #[ApiProperty(identifier: false, description: 'Full plugin name (connector:resource:action).')]
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
