<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Clearance;

/**
 * Defines what a clearance level can access.
 *
 * Built-in clearances: superadmin, admin, operator, readonly, automation, dev
 * Custom clearances: loaded from config/mosyca/clearances.yaml
 */
final class ClearanceDefinition
{
    /**
     * @param string[] $allowPatterns Glob patterns for allowed plugin names (e.g. ['*', 'core:*'])
     * @param string[] $denyPatterns  Glob patterns that override allow (e.g. ['shopware:*'])
     * @param string   $logLevel      Minimum Plugin Log level: debug|info|warning|error|off
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $allowAll = false,
        public readonly bool $allowMutating = false,
        public readonly array $allowPatterns = [],
        public readonly array $denyPatterns = [],
        public readonly string $logLevel = 'info',
    ) {
    }

    /**
     * Returns true if this clearance permits calling the given plugin.
     */
    public function permits(string $pluginName, bool $isMutating): bool
    {
        if ($isMutating && !$this->allowMutating) {
            return false;
        }

        if ($this->allowAll) {
            return true;
        }

        foreach ($this->denyPatterns as $pattern) {
            if (fnmatch($pattern, $pluginName)) {
                return false;
            }
        }

        foreach ($this->allowPatterns as $pattern) {
            if (fnmatch($pattern, $pluginName)) {
                return true;
            }
        }

        return false;
    }
}
