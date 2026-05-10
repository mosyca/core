<?php

declare(strict_types=1);

namespace Mosyca\Core\Depot\Console;

use Mosyca\Core\Depot\DepotInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * bin/console mosyca:depot:clear [--older-than=<seconds>] [--key=<key>].
 *
 * Purges depot entries. Without --older-than, purges only expired entries.
 */
#[AsCommand(
    name: 'mosyca:depot:clear',
    description: 'Purge Depot cache entries',
)]
final class DepotClearCommand extends Command
{
    public function __construct(private readonly ?DepotInterface $depot = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Purge entries written more than N seconds ago')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Delete a specific depot key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->depot) {
            $io->warning('Depot is not configured. Set mosyca.depot_dir to enable it.');

            return Command::SUCCESS;
        }

        $key = $input->getOption('key');
        if (\is_string($key) && '' !== $key) {
            $this->depot->delete($key);
            $io->success(\sprintf('Deleted depot key: %s', $key));

            return Command::SUCCESS;
        }

        $olderThan = $input->getOption('older-than');
        $maxAge = is_numeric($olderThan) ? (int) $olderThan : 0;

        $deleted = $this->depot->purgeOlderThan($maxAge);

        if (0 === $maxAge) {
            $io->success(\sprintf('Purged %d expired depot entries.', $deleted));
        } else {
            $io->success(\sprintf('Purged %d depot entries older than %d seconds.', $deleted, $maxAge));
        }

        return Command::SUCCESS;
    }
}
