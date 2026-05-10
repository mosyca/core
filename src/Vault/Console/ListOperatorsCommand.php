<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Console;

use Mosyca\Core\Vault\Repository\OperatorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mosyca:vault:list-operators',
    description: 'List all Mosyca operators.',
)]
final class ListOperatorsCommand extends Command
{
    public function __construct(private readonly OperatorRepository $repository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $operators = $this->repository->findAll();

        if ([] === $operators) {
            $io->info('No operators found. Create one with mosyca:vault:create-operator.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($operators as $op) {
            $activeTokens = \count($op->getMcpTokens()->filter(
                static fn ($t) => $t->isActive()
            ));

            $rows[] = [
                $op->getId(),
                $op->getUsername(),
                $op->getClearance(),
                $op->getCreatedAt()->format('Y-m-d'),
                $activeTokens > 0 ? "{$activeTokens} active" : '—',
            ];
        }

        $io->table(
            ['ID', 'Username', 'Clearance', 'Created', 'MCP Tokens'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
