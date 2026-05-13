<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Action\ActionResult;

/**
 * Produces human-readable plain text optimised for Claude (MCP context).
 *
 * Format:
 *   ✅ summary line
 *
 *   Key:                 value
 *   ...
 *
 *   Links:
 *     /api/resource
 */
final class McpRenderer
{
    public function render(ActionResult $result): string
    {
        $prefix = $result->success ? '✅' : '❌';
        $lines = ["{$prefix} {$result->summary}", ''];

        if (\is_array($result->data) && !empty($result->data)) {
            foreach ($result->data as $key => $value) {
                $label = ucwords(str_replace('_', ' ', (string) $key));
                $lines[] = \sprintf('%-22s %s', $label.':', $this->formatValue($value));
            }
            $lines[] = '';
        }

        if (!empty($result->embedded)) {
            foreach ($result->embedded as $rel => $embedded) {
                if (\is_array($embedded)) {
                    $label = ucwords((string) $rel);
                    $parts = array_filter(
                        array_values($embedded),
                        static fn (mixed $v): bool => null !== $v && '' !== $v,
                    );
                    $lines[] = \sprintf('%-22s %s', $label.':', implode(' · ', array_map('strval', $parts)));
                }
            }
            $lines[] = '';
        }

        if (!empty($result->links)) {
            $lines[] = 'Links:';
            foreach ($result->links as $href) {
                $lines[] = '  '.$href;
            }
        }

        return trim(implode("\n", $lines));
    }

    private function formatValue(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (null === $value) {
            return '—';
        }
        if (\is_array($value)) {
            return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }

        return (string) $value;
    }
}
