<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Action\ActionResult;

/**
 * Converts ActionResult into a plain array with HAL-style HATEOAS structure.
 *
 * Transforms raw link strings ['self' => '/api/...']
 * into HAL objects       ['self' => ['href' => '/api/...']].
 */
final class Normalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(ActionResult $result): array
    {
        $normalized = [
            'success' => $result->success,
            'summary' => $result->summary,
            'data' => $result->data,
        ];

        if (!empty($result->links)) {
            $normalized['_links'] = array_map(
                static fn (string $href): array => ['href' => $href],
                $result->links,
            );
        }

        if (!empty($result->embedded)) {
            $normalized['_embedded'] = $result->embedded;
        }

        if (!empty($result->metadata)) {
            $normalized['_meta'] = $result->metadata;
        }

        return $normalized;
    }
}
