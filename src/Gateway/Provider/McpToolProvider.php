<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Mosyca\Core\Gateway\Resource\McpToolResource;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginRegistry;

/**
 * API Platform state provider for McpToolResource.
 *
 * Returns every plugin as an MCP tool (list_tools format).
 *
 * @implements ProviderInterface<McpToolResource>
 */
final class McpToolProvider implements ProviderInterface
{
    public function __construct(private readonly PluginRegistry $registry)
    {
    }

    /** @return list<McpToolResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_values(array_map(
            fn (PluginInterface $p): McpToolResource => $this->toTool($p),
            $this->registry->all(),
        ));
    }

    private function toTool(PluginInterface $plugin): McpToolResource
    {
        $tool = new McpToolResource();
        $tool->name = str_replace([':'], ['_'], $plugin->getName());
        $tool->description = $plugin->getDescription();
        $tool->inputSchema = $this->buildSchema($plugin);

        return $tool;
    }

    /** @return array<string, mixed> */
    private function buildSchema(PluginInterface $plugin): array
    {
        $properties = [];
        $required = [];

        foreach ($plugin->getParameters() as $paramName => $spec) {
            $prop = ['type' => $this->mapType($spec['type'] ?? 'string')];

            if (isset($spec['description'])) {
                $prop['description'] = (string) $spec['description'];
            }
            if (\array_key_exists('default', $spec)) {
                $prop['default'] = $spec['default'];
            }
            if (!empty($spec['enum'])) {
                $prop['enum'] = $spec['enum'];
            }

            $properties[$paramName] = $prop;

            if ($spec['required'] ?? false) {
                $required[] = $paramName;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    private function mapType(string $type): string
    {
        return match ($type) {
            'integer', 'int' => 'integer',
            'boolean', 'bool' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }
}
