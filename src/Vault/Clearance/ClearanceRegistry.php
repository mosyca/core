<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Clearance;

use Symfony\Component\Yaml\Yaml;

/**
 * Provides the active set of clearance definitions.
 *
 * Built-in clearances are always present.
 * Custom clearances are loaded from config/mosyca/clearances.yaml
 * and can override built-in definitions.
 */
final class ClearanceRegistry
{
    /** @var array<string, ClearanceDefinition> */
    private array $definitions = [];

    public function __construct(private readonly ?string $customYamlPath = null)
    {
        $this->loadBuiltIns();
        $this->loadCustom();
    }

    public function get(string $name): ?ClearanceDefinition
    {
        return $this->definitions[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /** @return array<string, ClearanceDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->definitions);
    }

    private function loadBuiltIns(): void
    {
        $this->definitions = [
            'superadmin' => new ClearanceDefinition(
                name: 'superadmin',
                allowAll: true,
                allowMutating: true,
                logLevel: 'info',
            ),
            'admin' => new ClearanceDefinition(
                name: 'admin',
                allowMutating: true,
                allowPatterns: ['*'],
                logLevel: 'info',
            ),
            'operator' => new ClearanceDefinition(
                name: 'operator',
                allowMutating: false,
                allowPatterns: ['*'],
                logLevel: 'warning',
            ),
            'readonly' => new ClearanceDefinition(
                name: 'readonly',
                allowMutating: false,
                allowPatterns: ['*'],
                logLevel: 'off',
            ),
            'automation' => new ClearanceDefinition(
                name: 'automation',
                allowMutating: true,
                allowPatterns: ['*'],
                logLevel: 'warning',
            ),
            'dev' => new ClearanceDefinition(
                name: 'dev',
                allowAll: true,
                allowMutating: true,
                logLevel: 'debug',
            ),
            // GBAC: data bypass without admin rights.
            // Grants ROLE_MANAGER → isAclBypassed=true in ContextProvider.
            // Does NOT grant ROLE_ADMIN — data_manager cannot manage operators/clearances.
            'data_manager' => new ClearanceDefinition(
                name: 'data_manager',
                allowMutating: false,
                allowPatterns: ['*'],
                logLevel: 'info',
            ),
        ];
    }

    private function loadCustom(): void
    {
        if (null === $this->customYamlPath || !file_exists($this->customYamlPath)) {
            return;
        }

        /** @var array{clearances?: array<string, array<string, mixed>>} $data */
        $data = Yaml::parseFile($this->customYamlPath);

        foreach ($data['clearances'] ?? [] as $name => $config) {
            $this->definitions[$name] = new ClearanceDefinition(
                name: $name,
                allowAll: (bool) ($config['allow_all'] ?? false),
                allowMutating: (bool) ($config['allow_mutating'] ?? false),
                allowPatterns: (array) ($config['allow_patterns'] ?? []),
                denyPatterns: (array) ($config['deny_patterns'] ?? []),
                logLevel: \is_string($config['log_level'] ?? null) ? $config['log_level'] : 'info',
            );
        }
    }
}
