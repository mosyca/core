<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Mosyca\Core\Gateway\Resource\PluginResource;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginRegistry;

/**
 * API Platform state provider for PluginResource.
 *
 * Handles both collection (GET /api/plugins) and item (GET /api/plugins/{name}).
 *
 * @implements ProviderInterface<PluginResource>
 */
final class PluginProvider implements ProviderInterface
{
    public function __construct(private readonly PluginRegistry $registry)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        // Item operation: GET /api/plugins/{name}
        if (isset($uriVariables['name'])) {
            $name = (string) $uriVariables['name'];

            if (!$this->registry->has($name)) {
                return null;
            }

            return $this->toResource($this->registry->get($name), full: true);
        }

        // Collection operation: GET /api/plugins
        $filters = $context['filters'] ?? [];
        $connector = isset($filters['connector']) ? (string) $filters['connector'] : null;
        $tag = isset($filters['tag']) ? (string) $filters['tag'] : null;
        $mutatingRaw = $filters['mutating'] ?? null;
        $mutating = null !== $mutatingRaw ? filter_var($mutatingRaw, \FILTER_VALIDATE_BOOLEAN) : null;

        return array_values(array_map(
            fn (PluginInterface $p): PluginResource => $this->toResource($p, full: false),
            $this->registry->filter(connector: $connector, tag: $tag, mutating: $mutating),
        ));
    }

    private function toResource(PluginInterface $plugin, bool $full): PluginResource
    {
        $resource = new PluginResource();
        $resource->name = $plugin->getName();
        $resource->description = $plugin->getDescription();
        $resource->tags = $plugin->getTags();
        $resource->mutating = $plugin->isMutating();
        $resource->defaultFormat = $plugin->getDefaultFormat();
        $resource->defaultTemplate = $plugin->getDefaultTemplate();
        $resource->connector = explode(':', $plugin->getName(), 2)[0];

        if ($full) {
            $resource->usage = $plugin->getUsage();
            $resource->parameters = $plugin->getParameters();
            $resource->requiredScopes = $plugin->getRequiredScopes();
            $resource->jsonSchema = $this->buildJsonSchema($plugin);
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    private function buildJsonSchema(PluginInterface $plugin): array
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
            if (isset($spec['example'])) {
                $prop['example'] = $spec['example'];
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
