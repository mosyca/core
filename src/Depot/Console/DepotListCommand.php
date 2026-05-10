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
 * bin/console mosyca:depot:list [--operator=<name>].
 *
 * Lists all valid (non-expired) depot keys, optionally filtered to one operator.
 */
#[AsCommand(
    name: 'mosyca:depot:list',
    description: 'List Depot cache entries',
)]
final class DepotListCommand extends Command
{
    public function __construct(private readonly ?DepotInterface $depot = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('operator', 'o', InputOption::VALUE_REQUIRED, 'Filter by operator username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->depot) {
            $io->warning('Depot is not configured. Set mosyca.depot_dir to enable it.');

            return Command::SUCCESS;
        }

        $operator = $input->getOption('operator');

        if (\is_string($operator) && '' !== $operator) {
            $keys = $this->depot->listKeys($operator);
            $io->title(\sprintf('Depot — operator: %s (%d entries)', $operator, \count($keys)));
        } else {
            // List all operators by trying well-known names — CLI is admin context
            $keys = $this->depot->listKeys('');
            $io->title(\sprintf('Depot — all entries (%d)', \count($keys)));
        }

        if (empty($keys)) {
            $io->info('No depot entries found.');

            return Command::SUCCESS;
        }

        $io->listing($keys);

        return Command::SUCCESS;
    }
}
