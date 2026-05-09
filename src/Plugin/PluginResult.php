<?php

declare(strict_types=1);

namespace Mosyca\Core\Plugin;

/**
 * Unified output object for all Mosyca plugins.
 *
 * The Renderer converts this into the requested output format:
 * json, yaml, raw, table, text (twig), mcp.
 */
final class PluginResult
{
    private function __construct(
        public readonly bool $success,
        public readonly mixed $data,
        public readonly string $summary,
        /** @var array<string, string> */
        public readonly array $links = [],
        /** @var array<string, mixed> */
        public readonly array $embedded = [],
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Successful result.
     *
     * @param mixed  $data    The result data (array, object, scalar)
     * @param string $summary One-line human readable summary.
     *                        Claude reads this as the primary response text.
     *                        Example: "Order #1234: margin 47.30€ (23.5%)"
     */
    public static function ok(mixed $data, string $summary): self
    {
        return new self(
            success: true,
            data: $data,
            summary: $summary,
        );
    }

    /**
     * Error result.
     *
     * Use for business logic errors — never throw exceptions for expected cases.
     *
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): self
    {
        return new self(
            success: false,
            data: $context,
            summary: $message,
        );
    }

    /**
     * Add HATEOAS links.
     *
     * @param array<string, string> $links ['self' => '/api/...', 'order' => '/api/...']
     */
    public function withLinks(array $links): self
    {
        return new self(
            success: $this->success,
            data: $this->data,
            summary: $this->summary,
            links: $links,
            embedded: $this->embedded,
            metadata: $this->metadata,
        );
    }

    /**
     * Add embedded related objects.
     *
     * @param array<string, mixed> $embedded ['order' => $order->toArray(), ...]
     */
    public function withEmbedded(array $embedded): self
    {
        return new self(
            success: $this->success,
            data: $this->data,
            summary: $this->summary,
            links: $this->links,
            embedded: $embedded,
            metadata: $this->metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'success' => $this->success,
            'summary' => $this->summary,
            'data' => $this->data,
        ];

        if (!empty($this->links)) {
            $result['_links'] = $this->links;
        }

        if (!empty($this->embedded)) {
            $result['_embedded'] = $this->embedded;
        }

        if (!empty($this->metadata)) {
            $result['_meta'] = $this->metadata;
        }

        return $result;
    }
}
