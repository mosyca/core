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
