<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Mosyca\Core\Gateway\Provider\McpToolProvider;

/**
 * MCP Tool Resource — simplified endpoint optimised for Bridge consumption.
 *
 * Routes:
 *   GET /api/mcp/tools   → list_tools MCP-formatted response
 *
 * The tool name uses underscores instead of colons/hyphens so it is a valid
 * MCP tool identifier: core:system:ping → core_system_ping.
 */
#[ApiResource(
    shortName: 'McpTool',
    description: 'MCP Tool list – plugins formatted for Claude MCP list_tools.',
    operations: [
        new GetCollection(
            uriTemplate: '/mcp/tools',
            description: 'List all plugins as MCP tools (list_tools format).',
            provider: McpToolProvider::class,
        ),
    ],
    formats: ['json'],
    paginationEnabled: false,
)]
final class McpToolResource
{
    /**
     * MCP tool name — colons and hyphens replaced with underscores.
     *
     * Example: core_system_ping
     */
    #[ApiProperty(identifier: true)]
    public string $name = '';

    #[ApiProperty(description: 'Short description for Claude list_tools.')]
    public string $description = '';

    /**
     * JSON Schema for MCP inputSchema.
     *
     * @var array<string, mixed>
     */
    #[ApiProperty(description: 'JSON Schema describing plugin parameters (MCP inputSchema).')]
    public array $inputSchema = [];
}
