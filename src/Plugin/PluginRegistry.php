<?php

declare(strict_types=1);

namespace Mosyca\Core\Plugin;

final class PluginRegistry
{
    /** @var array<string, PluginInterface> */
    private array $plugins = [];

    public function register(PluginInterface $plugin): void
    {
        $this->plugins[$plugin->getName()] = $plugin;
    }

    public function get(string $name): PluginInterface
    {
        if (!isset($this->plugins[$name])) {
            throw new \InvalidArgumentException("Plugin '{$name}' not found in registry.");
        }

        return $this->plugins[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    /** @return array<string, PluginInterface> */
    public function all(): array
    {
        return $this->plugins;
    }
}
