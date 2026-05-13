<?php

declare(strict_types=1);

namespace Mosyca\Core\Scaffold;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and parses an OpenAPI 3.x specification from a URL or local file path.
 *
 * Supports JSON and YAML formats, auto-detected by content prefix.
 * Uses only PHP built-ins for HTTP — no extra HTTP-client package required.
 */
final class OpenApiSpecLoader
{
    /**
     * Load a spec from an HTTP(S) URL or a local file path.
     *
     * @return array<string, mixed> Parsed OpenAPI spec
     *
     * @throws \RuntimeException When the source cannot be fetched or parsed
     */
    public function load(string $source, ?string $authHeader = null): array
    {
        $content = $this->fetch($source, $authHeader);

        return $this->parse($content, $source);
    }

    private function fetch(string $source, ?string $authHeader): string
    {
        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            return $this->fetchHttp($source, $authHeader);
        }

        if (!file_exists($source)) {
            throw new \RuntimeException("OpenAPI spec file not found: {$source}");
        }

        $content = file_get_contents($source);
        if (false === $content) {
            throw new \RuntimeException("Cannot read OpenAPI spec file: {$source}");
        }

        return $content;
    }

    private function fetchHttp(string $url, ?string $authHeader): string
    {
        $headers = ['User-Agent: Mosyca-Scaffold/1.0'];
        if (null !== $authHeader) {
            $headers[] = 'Authorization: '.$authHeader;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
        ]);

        $content = file_get_contents($url, false, $context);
        if (false === $content) {
            throw new \RuntimeException("Cannot fetch OpenAPI spec from: {$url}");
        }

        // Check for HTTP error status in response headers
        /** @var string[]|null $responseHeaders */
        $responseHeaders = $http_response_header ?? null;  // @phpstan-ignore-line (PHP magic variable)
        if (\is_array($responseHeaders) && \count($responseHeaders) > 0) {
            $statusLine = $responseHeaders[0] ?? '';
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $m) && (int) $m[1] >= 400) {
                throw new \RuntimeException("HTTP {$m[1]} fetching OpenAPI spec from: {$url}");
            }
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $content, string $source): array
    {
        $trimmed = ltrim($content);

        // JSON detection: starts with '{' or '['
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $data = json_decode($content, true);
            if (!\is_array($data)) {
                throw new \RuntimeException('Invalid JSON in OpenAPI spec from '.$source.': '.json_last_error_msg());
            }

            return $data;
        }

        // Assume YAML
        try {
            $data = Yaml::parse($content);
            if (!\is_array($data)) {
                throw new \RuntimeException("OpenAPI spec from '{$source}' did not parse to an array.");
            }

            return $data;
        } catch (ParseException $e) {
            throw new \RuntimeException("Cannot parse OpenAPI spec from '{$source}': ".$e->getMessage(), 0, $e);
        }
    }
}
