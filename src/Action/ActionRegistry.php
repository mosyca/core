<?php

declare(strict_types=1);

namespace Mosyca\Core\Action;

final class ActionRegistry
{
    /** @var array<string, ActionInterface> */
    private array $actions = [];

    public function register(ActionInterface $action): void
    {
        $this->actions[$action->getName()] = $action;
    }

    public function get(string $name): ActionInterface
    {
        if (!isset($this->actions[$name])) {
            throw new \InvalidArgumentException("Action '{$name}' not found in registry.");
        }

        return $this->actions[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    /** @return array<string, ActionInterface> */
    public function all(): array
    {
        return $this->actions;
    }

    /**
     * Find the first registered action whose implementation is the given class.
     *
     * Used by the True-REST gateway adapters: the factory stores the action's
     * class string in HttpOperation::extraProperties['mosyca_action'], and the
     * adapters resolve it back to a live ActionInterface instance here.
     *
     * @throws \InvalidArgumentException When no action of the given class is registered
     */
    public function getByClass(string $className): ActionInterface
    {
        foreach ($this->actions as $action) {
            if ($action instanceof $className) {
                return $action;
            }
        }

        throw new \InvalidArgumentException("No action of class '{$className}' found in registry.");
    }

    /**
     * Check whether an action of the given class is registered.
     *
     * Used by McpDiscoveryService to skip operations whose action class was not
     * wired into the DI container (e.g. Doctrine-dependent actions in non-Vault contexts).
     *
     * @param class-string $className
     */
    public function hasByClass(string $className): bool
    {
        foreach ($this->actions as $action) {
            if ($action instanceof $className) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a filtered subset of the registry.
     *
     * @param string|null $connector Filter by plugin_name prefix (e.g. 'shopware6' matches 'shopware6:*')
     * @param string|null $tag       Filter by tag
     * @param bool|null   $mutating  Filter by mutating status
     *
     * @return array<string, ActionInterface>
     */
    public function filter(?string $connector = null, ?string $tag = null, ?bool $mutating = null): array
    {
        return array_filter(
            $this->actions,
            static function (ActionInterface $action) use ($connector, $tag, $mutating): bool {
                if (null !== $connector && !str_starts_with($action->getName(), $connector.':')) {
                    return false;
                }
                if (null !== $tag && !\in_array($tag, $action->getTags(), true)) {
                    return false;
                }
                if (null !== $mutating && $action->isMutating() !== $mutating) {
                    return false;
                }

                return true;
            },
        );
    }
}
