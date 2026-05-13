<?php

declare(strict_types=1);

namespace Mosyca\Core\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Chains the framework's built-in ContainerCommandLoader with the
 * ConsoleAdapter so both regular commands and plugin commands are served
 * through a single CommandLoaderInterface.
 *
 * Used instead of the (unavailable) symfony/console ChainCommandLoader.
 */
final class ActionChainCommandLoader implements CommandLoaderInterface
{
    public function __construct(
        private readonly CommandLoaderInterface $builtin,
        private readonly ConsoleAdapter $plugins,
    ) {
    }

    public function get(string $name): Command
    {
        if ($this->plugins->has($name)) {
            return $this->plugins->get($name);
        }

        if ($this->builtin->has($name)) {
            return $this->builtin->get($name);
        }

        throw new CommandNotFoundException(\sprintf('Command "%s" not found.', $name));
    }

    public function has(string $name): bool
    {
        return $this->plugins->has($name) || $this->builtin->has($name);
    }

    /** @return string[] */
    public function getNames(): array
    {
        return array_values(array_unique(array_merge($this->builtin->getNames(), $this->plugins->getNames())));
    }
}
