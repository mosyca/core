<?php

declare(strict_types=1);

namespace Mosyca\Core\Console;

use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Context\ContextProvider;
use Mosyca\Core\Renderer\OutputRendererInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Implements CommandLoaderInterface so every registered plugin appears as a
 * lazy-loaded bin/console command with the plugin's name as the command name.
 *
 * Tagged console.command_loader in services.yaml so Symfony's Console
 * Application picks it up automatically.
 */
final class ConsoleAdapter implements CommandLoaderInterface
{
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly OutputRendererInterface $renderer,
        private readonly ContextProvider $contextProvider,
    ) {
    }

    public function get(string $name): Command
    {
        if (!$this->has($name)) {
            throw new CommandNotFoundException(\sprintf('Action command "%s" not found.', $name));
        }

        return $this->buildCommand($this->registry->get($name));
    }

    public function has(string $name): bool
    {
        return $this->registry->has($name);
    }

    /** @return string[] */
    public function getNames(): array
    {
        return array_keys($this->registry->all());
    }

    public function buildCommand(ActionInterface $action): Command
    {
        return new ActionCommand($action, $this->renderer, $this->contextProvider);
    }
}
