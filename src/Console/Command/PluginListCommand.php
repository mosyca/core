<?php

declare(strict_types=1);

namespace Mosyca\Core\Console\Command;

use Mosyca\Core\Plugin\PluginRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mosyca:plugin:list', description: 'List all registered Mosyca plugins')]
final class PluginListCommand extends Command
{
    public function __construct(private readonly PluginRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Filter plugins by tag');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plugins = $this->registry->all();

        $tagOpt = $input->getOption('tag');
        $tag = \is_string($tagOpt) ? $tagOpt : null;

        if (null !== $tag) {
            $plugins = array_filter(
                $plugins,
                static fn ($p): bool => \in_array($tag, $p->getTags(), true),
            );
        }

        if (empty($plugins)) {
            $io->warning(null !== $tag ? "No plugins found with tag '{$tag}'." : 'No plugins registered.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($plugins as $plugin) {
            $rows[] = [
                $plugin->getName(),
                $plugin->getDescription(),
                implode(', ', $plugin->getTags()),
                $plugin->isMutating() ? '<comment>write</comment>' : 'read',
                $plugin->getDefaultFormat(),
            ];
        }

        $io->table(['Name', 'Description', 'Tags', 'Mode', 'Format'], $rows);
        $io->text(\sprintf('%d plugin(s) registered.', \count($plugins)));

        return Command::SUCCESS;
    }
}
