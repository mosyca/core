<?php

declare(strict_types=1);

namespace Mosyca\Core\Console\Command;

use Mosyca\Core\Action\ActionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mosyca:action:list', description: 'List all registered Mosyca actions')]
final class ActionListCommand extends Command
{
    public function __construct(private readonly ActionRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Filter actions by tag');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $actions = $this->registry->all();

        $tagOpt = $input->getOption('tag');
        $tag = \is_string($tagOpt) ? $tagOpt : null;

        if (null !== $tag) {
            $actions = array_filter(
                $actions,
                static fn ($a): bool => \in_array($tag, $a->getTags(), true),
            );
        }

        if (empty($actions)) {
            $io->warning(null !== $tag ? "No actions found with tag '{$tag}'." : 'No actions registered.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($actions as $action) {
            $rows[] = [
                $action->getName(),
                $action->getDescription(),
                implode(', ', $action->getTags()),
                $action->isMutating() ? '<comment>write</comment>' : 'read',
                $action->getDefaultFormat(),
            ];
        }

        $io->table(['Name', 'Description', 'Tags', 'Mode', 'Format'], $rows);
        $io->text(\sprintf('%d action(s) registered.', \count($actions)));

        return Command::SUCCESS;
    }
}
