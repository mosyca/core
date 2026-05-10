<?php

declare(strict_types=1);

namespace Mosyca\Core\Depot;

/**
 * Filesystem-backed Depot implementation.
 *
 * Storage layout:
 *   {depotDir}/{operator}/{connector}/{plugin}/{hash}
 *
 * Each file is a JSON blob:
 *   { "data": {...}, "expires_at": 1234567890, "written_at": 1234567890 }
 *
 * The operator is always part of the key — there is no cross-operator cache.
 * No filesystem-level account separation exists; a shared key would be a
 * potential data leak even for "public" data.
 *
 * V0.8: Plain PHP, no Flysystem dependency.
 */
final class FilesystemDepot implements DepotInterface
{
    public function __construct(private readonly string $depotDir)
    {
    }

    public function get(string $key): ?array
    {
        $path = $this->keyToPath($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (false === $raw) {
            return null;
        }

        /** @var array{data: mixed, expires_at: int, written_at: int}|null $entry */
        $entry = json_decode($raw, true);
        if (!\is_array($entry)) {
            return null;
        }

        if ($entry['expires_at'] < time()) {
            @unlink($path);

            return null;
        }

        return \is_array($entry['data']) ? $entry['data'] : null;
    }

    public function set(string $key, array $data, int $ttl): void
    {
        $path = $this->keyToPath($key);
        $dir = \dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Depot: could not create directory "%s".', $dir));
        }

        $entry = json_encode([
            'data' => $data,
            'expires_at' => time() + $ttl,
            'written_at' => time(),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        if (false === $entry) {
            throw new \RuntimeException('Depot: could not JSON-encode result data.');
        }

        // Atomic write via temp file + rename
        $tmp = $path.'.'.uniqid('', true).'.tmp';
        if (false === @file_put_contents($tmp, $entry)) {
            throw new \RuntimeException(\sprintf('Depot: could not write temp file "%s".', $tmp));
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(\sprintf('Depot: could not rename temp file to "%s".', $path));
        }
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }

    public function delete(string $key): void
    {
        $path = $this->keyToPath($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function listKeys(string $operator): array
    {
        $operatorDir = $this->depotDir.'/'.$this->sanitizeSegment($operator);
        if (!is_dir($operatorDir)) {
            return [];
        }

        $keys = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($operatorDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (str_ends_with($file->getFilename(), '.tmp')) {
                continue;
            }

            // Check TTL — skip expired
            $raw = @file_get_contents($file->getPathname());
            if (false === $raw) {
                continue;
            }
            /** @var array{expires_at?: int}|null $entry */
            $entry = json_decode($raw, true);
            if (!\is_array($entry) || ($entry['expires_at'] ?? 0) < time()) {
                continue;
            }

            // Key = relative path from depotDir without leading slash
            $keys[] = ltrim(str_replace($this->depotDir, '', $file->getPathname()), '/\\');
        }

        sort($keys);

        return $keys;
    }

    public function purgeOlderThan(int $maxAgeSeconds): int
    {
        if (!is_dir($this->depotDir)) {
            return 0;
        }

        $count = 0;
        $cutoff = time() - $maxAgeSeconds;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->depotDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (str_ends_with($file->getFilename(), '.tmp')) {
                continue;
            }

            $raw = @file_get_contents($file->getPathname());
            if (false === $raw) {
                continue;
            }

            /** @var array{written_at?: int, expires_at?: int}|null $entry */
            $entry = json_decode($raw, true);
            $writtenAt = \is_array($entry) ? ($entry['written_at'] ?? 0) : 0;

            if ($writtenAt < $cutoff || ((\is_array($entry) ? ($entry['expires_at'] ?? 0) : 0) < time())) {
                if (@unlink($file->getPathname())) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    public function buildKey(string $operator, string $connector, string $plugin, mixed $args): string
    {
        $normalized = self::normalizeArgs($args);
        $hash = substr(md5(json_encode($normalized) ?: ''), 0, 12);

        return implode('/', [
            self::sanitizeSegment($operator),
            self::sanitizeSegment($connector),
            self::sanitizeSegment($plugin),
            $hash,
        ]);
    }

    private function keyToPath(string $key): string
    {
        // Prevent path traversal: key segments must not contain dots or slashes
        $safe = preg_replace('/[^a-zA-Z0-9_\-\/]/', '_', $key);

        return $this->depotDir.'/'.$safe;
    }

    private static function sanitizeSegment(string $segment): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $segment) ?? '_';
    }

    /**
     * Sort args recursively so hash is order-independent.
     */
    private static function normalizeArgs(mixed $args): mixed
    {
        if (\is_array($args)) {
            ksort($args);

            return array_map(static fn ($v) => self::normalizeArgs($v), $args);
        }

        return $args;
    }
}
