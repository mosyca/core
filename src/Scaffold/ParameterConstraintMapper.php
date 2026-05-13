<?php

declare(strict_types=1);

namespace Mosyca\Core\Scaffold;

/**
 * Maps an OpenAPI parameter schema to a Symfony Validator constraint code snippet.
 *
 * The returned string is valid PHP source code for embedding inside the
 * `fields` array of an `Assert\Collection` in a generated scaffold action stub.
 *
 * Required field with type + range:
 *   [new Assert\NotBlank(), new Assert\Type('integer'), new Assert\Range(min: 2000, max: 2099)]
 *
 * Optional field with enum:
 *   new Assert\Optional([new Assert\Type('string'), new Assert\Choice(['a', 'b'])])
 *
 * Optional field with a single constraint:
 *   new Assert\Optional(new Assert\Type('boolean'))
 */
final class ParameterConstraintMapper
{
    /**
     * Map one OpenAPI parameter schema to its constraint code snippet.
     *
     * @param array<string, mixed> $schema   OpenAPI parameter schema (type, format, minimum, enum, …)
     * @param bool                 $required Whether the parameter is required in the request
     *
     * @return string PHP source snippet for use as the value in an Assert\Collection fields entry
     */
    public function mapField(array $schema, bool $required): string
    {
        $parts = $this->buildParts($schema, $required);

        if (!$required) {
            return match (\count($parts)) {
                0 => "new Assert\\Optional(new Assert\\Type('string'))",
                1 => 'new Assert\\Optional('.$parts[0].')',
                default => "new Assert\\Optional([\n                    ".implode(",\n                    ", $parts).",\n                ])",
            };
        }

        return match (\count($parts)) {
            0 => "new Assert\\Type('string')",
            1 => $parts[0],
            default => '['.implode(', ', $parts).']',
        };
    }

    /**
     * Build the list of individual constraint code snippets for one parameter.
     *
     * @param array<string, mixed> $schema
     *
     * @return string[]
     */
    private function buildParts(array $schema, bool $required): array
    {
        $parts = [];

        // NotBlank is always first for required fields
        if ($required) {
            $parts[] = 'new Assert\\NotBlank()';
        }

        // Core type constraint
        $type = isset($schema['type']) && \is_string($schema['type']) ? $schema['type'] : 'string';
        $phpType = match ($type) {
            'integer' => 'integer',
            'number' => 'float',
            'boolean' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
        $parts[] = "new Assert\\Type('{$phpType}')";

        // Enum constraint (string choices)
        if (isset($schema['enum']) && \is_array($schema['enum'])) {
            $choices = implode(', ', array_map(
                static fn (mixed $v): string => "'".addslashes((string) $v)."'",
                $schema['enum'],
            ));
            $parts[] = "new Assert\\Choice([{$choices}])";
        }

        // Format-based constraints
        if (isset($schema['format']) && \is_string($schema['format'])) {
            $formatConstraint = match ($schema['format']) {
                'uuid' => 'new Assert\\Uuid()',
                'date' => 'new Assert\\Date()',
                'date-time' => 'new Assert\\DateTime()',
                default => null,
            };
            if (null !== $formatConstraint) {
                $parts[] = $formatConstraint;
            }
        }

        // Numeric range (minimum / maximum)
        if (isset($schema['minimum']) || isset($schema['maximum'])) {
            $args = [];
            if (isset($schema['minimum'])) {
                $args[] = 'min: '.$this->numericLiteral($schema['minimum']);
            }
            if (isset($schema['maximum'])) {
                $args[] = 'max: '.$this->numericLiteral($schema['maximum']);
            }
            $parts[] = 'new Assert\\Range('.implode(', ', $args).')';
        }

        // String length (minLength / maxLength)
        if (isset($schema['minLength']) || isset($schema['maxLength'])) {
            $args = [];
            if (isset($schema['minLength'])) {
                $args[] = 'min: '.(int) $schema['minLength'];
            }
            if (isset($schema['maxLength'])) {
                $args[] = 'max: '.(int) $schema['maxLength'];
            }
            $parts[] = 'new Assert\\Length('.implode(', ', $args).')';
        }

        // Regex pattern
        if (isset($schema['pattern']) && \is_string($schema['pattern'])) {
            $escaped = addslashes($schema['pattern']);
            $parts[] = "new Assert\\Regex(pattern: '/{$escaped}/')";
        }

        return $parts;
    }

    private function numericLiteral(mixed $value): string
    {
        if (\is_float($value)) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
