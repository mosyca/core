<?php

declare(strict_types=1);

namespace Mosyca\Core\Depot;

/**
 * Depot — per-operator filesystem store for plugin results.
 *
 * Allows Claude (or routines) to avoid redundant API calls across sessions.
 * Not a database — a TTL-aware key/value file store.
 *
 * Access model (double opt-in, enforced in ActionRunProcessor):
 *   Layer 1: ActionResult declares eligibility via ->withDepot(ttl: N)
 *   Layer 2: Caller activates per call via {"depot": true} in request body
 *
 * Scaffold actions are permanently excluded, enforced in ActionRunProcessor.
 */
interface DepotInterface
{
    /**
     * Fetch a cached result.
     *
     * Returns null if the key does not exist or has expired.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * Store a result with a TTL.
     *
     * @param array<string, mixed> $data
     */
    public function set(string $key, array $data, int $ttl): void;

    /**
     * Check whether a valid (non-expired) entry exists.
     */
    public function has(string $key): bool;

    /**
     * Remove a single depot entry.
     */
    public function delete(string $key): void;

    /**
     * List all depot keys for an operator.
     *
     * Returns key paths relative to the depot root, scoped to this operator.
     *
     * @return list<string>
     */
    public function listKeys(string $operator): array;

    /**
     * Delete all entries older than $maxAgeSeconds.
     *
     * @return int Number of entries deleted
     */
    public function purgeOlderThan(int $maxAgeSeconds): int;

    /**
     * Build the depot key for a plugin call.
     *
     * Format: {operator}/{connector}/{plugin}/{hash}
     * The operator is always part of the key — no cross-operator shared cache.
     */
    public function buildKey(string $operator, string $connector, string $plugin, mixed $args): string;
}
