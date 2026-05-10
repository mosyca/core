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

    /**
     * Return a filtered subset of the registry.
     *
     * @param string|null $connector Filter by connector prefix (e.g. 'shopware6' matches 'shopware6:*')
     * @param string|null $tag       Filter by tag
     * @param bool|null   $mutating  Filter by mutating status
     *
     * @return array<string, PluginInterface>
     */
    public function filter(?string $connector = null, ?string $tag = null, ?bool $mutating = null): array
    {
        return array_filter(
            $this->plugins,
            static function (PluginInterface $plugin) use ($connector, $tag, $mutating): bool {
                if (null !== $connector && !str_starts_with($plugin->getName(), $connector.':')) {
                    return false;
                }
                if (null !== $tag && !\in_array($tag, $plugin->getTags(), true)) {
                    return false;
                }
                if (null !== $mutating && $plugin->isMutating() !== $mutating) {
                    return false;
                }

                return true;
            },
        );
    }
}
