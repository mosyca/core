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
 * Handles both collection (GET /api/plugins) and item
 * (GET /api/plugins/{connector}/{resource}/{action}).
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
        // Item operation: GET /api/plugins/{connector}/{resource}/{action}
        if (isset($uriVariables['connector'])) {
            $name = $this->nameFromVars($uriVariables);

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
        $parts = explode(':', $plugin->getName(), 3);

        $res = new PluginResource();
        $res->name = $plugin->getName();
        $res->connector = $parts[0];
        $res->resource = $parts[1] ?? '';
        $res->action = $parts[2] ?? '';
        $res->description = $plugin->getDescription();
        $res->tags = $plugin->getTags();
        $res->mutating = $plugin->isMutating();
        $res->defaultFormat = $plugin->getDefaultFormat();
        $res->defaultTemplate = $plugin->getDefaultTemplate();

        if ($full) {
            $res->usage = $plugin->getUsage();
            $res->parameters = $plugin->getParameters();
            $res->requiredScopes = $plugin->getRequiredScopes();
            $res->jsonSchema = $this->buildJsonSchema($plugin);
        }

        return $res;
    }

    /**
     * Reconstruct the canonical plugin name from URI variables.
     *
     * @param array<string, mixed> $uriVariables
     */
    private function nameFromVars(array $uriVariables): string
    {
        return ($uriVariables['connector'] ?? '')
            .':'.($uriVariables['resource'] ?? '')
            .':'.($uriVariables['action'] ?? '');
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
