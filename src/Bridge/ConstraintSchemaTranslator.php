<?php

declare(strict_types=1);

namespace Mosyca\Core\Bridge;

/**
 * Translates ActionInterface::getParameters() into JSON Schema Draft-07 (V0.13).
 *
 * The parameters array returned by each Action describes its inputs with a simple
 * PHP structure (type, description, required, enum, default, example). This service
 * converts that structure into the JSON Schema format that MCP list_tools requires.
 *
 * Per ADR 3.4, every generated schema receives a mandatory `tenant` property injected
 * at the top level. The tenant parameter identifies the target Mosyca instance/session.
 * When a non-empty $tenantEnum is provided, the tenant field is constrained to the
 * declared values — preventing LLM hallucinations about available tenants.
 *
 * ## Type mapping
 *
 *   'string'            → { "type": "string" }
 *   'int' | 'integer'  → { "type": "integer" }
 *   'bool' | 'boolean' → { "type": "boolean" }
 *   'array'             → { "type": "array" }
 *   anything else       → { "type": "string" }  (safe fallback)
 *
 * @see McpDiscoveryService
 */
final class ConstraintSchemaTranslator
{
    /**
     * @param array<string, array<string, mixed>> $parameters From ActionInterface::getParameters()
     * @param string[]                            $tenantEnum Available tenant IDs (empty = no enum constraint)
     *
     * @return array<string, mixed> JSON Schema Draft-07 object schema
     */
    public function translate(array $parameters, array $tenantEnum = []): array
    {
        // Inject tenant first (ADR 3.4) — always required, always first.
        /** @var array<string, mixed> $tenantProperty */
        $tenantProperty = [
            'type' => 'string',
            'description' => 'The target Mosyca instance/session.',
        ];
        if ([] !== $tenantEnum) {
            $tenantProperty['enum'] = $tenantEnum;
        }

        /** @var array<string, array<string, mixed>> $properties */
        $properties = ['tenant' => $tenantProperty];
        /** @var string[] $required */
        $required = ['tenant'];

        foreach ($parameters as $name => $def) {
            /** @var array<string, mixed> $prop */
            $prop = ['type' => $this->mapType(\is_string($def['type'] ?? null) ? (string) $def['type'] : 'string')];

            if (isset($def['description']) && \is_string($def['description'])) {
                $prop['description'] = $def['description'];
            }

            $enumValue = $def['enum'] ?? null;
            if (\is_array($enumValue)) {
                $prop['enum'] = $enumValue;
            }

            if (\array_key_exists('default', $def) && null !== $def['default']) {
                $prop['default'] = $def['default'];
            }

            if (\array_key_exists('example', $def) && null !== $def['example']) {
                $prop['examples'] = [$def['example']];
            }

            $properties[$name] = $prop;

            if (($def['required'] ?? false) === true) {
                $required[] = $name;
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
