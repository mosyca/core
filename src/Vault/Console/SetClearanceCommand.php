<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Console;

use Mosyca\Core\Vault\Clearance\ClearanceRegistry;
use Mosyca\Core\Vault\Repository\OperatorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mosyca:vault:set-clearance',
    description: 'Change the clearance level of an existing operator.',
)]
final class SetClearanceCommand extends Command
{
    public function __construct(
        private readonly OperatorRepository $repository,
        private readonly ClearanceRegistry $clearanceRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Operator username')
            ->addArgument('clearance', InputArgument::REQUIRED, 'New clearance level');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = (string) $input->getArgument('username');
        $clearance = (string) $input->getArgument('clearance');

        if (!$this->clearanceRegistry->has($clearance)) {
            $io->error(\sprintf(
                'Unknown clearance "%s". Available: %s',
                $clearance,
                implode(', ', $this->clearanceRegistry->names()),
            ));

            return Command::FAILURE;
        }

        $operator = $this->repository->findByUsername($username);

        if (null === $operator) {
            $io->error(\sprintf('Operator "%s" not found.', $username));

            return Command::FAILURE;
        }

        $previous = $operator->getClearance();
        $operator->setClearance($clearance);
        $this->repository->save($operator, flush: true);

        $io->success(\sprintf(
            'Operator "%s": clearance changed from "%s" → "%s".',
            $username,
            $previous,
            $clearance,
        ));

        return Command::SUCCESS;
    }
}
