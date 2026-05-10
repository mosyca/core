<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Console;

use Mosyca\Core\Vault\Clearance\ClearanceRegistry;
use Mosyca\Core\Vault\Entity\Operator;
use Mosyca\Core\Vault\Repository\OperatorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'mosyca:vault:create-operator',
    description: 'Create a new Mosyca operator with a clearance level.',
)]
final class CreateOperatorCommand extends Command
{
    public function __construct(
        private readonly OperatorRepository $repository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ClearanceRegistry $clearanceRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Operator username')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Operator password (prompted if omitted)')
            ->addOption('clearance', 'c', InputOption::VALUE_REQUIRED, 'Clearance level', 'operator');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getOption('username') ?? $io->ask('Username');
        $clearance = $input->getOption('clearance');
        $password = $input->getOption('password') ?? $io->askHidden('Password');

        if (!\is_string($username) || '' === $username) {
            $io->error('Username is required.');

            return Command::FAILURE;
        }
        if (!\is_string($password) || '' === $password) {
            $io->error('Password is required.');

            return Command::FAILURE;
        }
        if (!\is_string($clearance) || !$this->clearanceRegistry->has($clearance)) {
            $io->error(\sprintf(
                'Unknown clearance "%s". Available: %s',
                $clearance,
                implode(', ', $this->clearanceRegistry->names()),
            ));

            return Command::FAILURE;
        }

        if ($this->repository->usernameExists($username)) {
            $io->error(\sprintf('Operator "%s" already exists.', $username));

            return Command::FAILURE;
        }

        $operator = new Operator($username, $clearance);
        $operator->setPassword($this->hasher->hashPassword($operator, $password));

        $this->repository->save($operator, flush: true);

        $io->success(\sprintf('Operator "%s" created with clearance "%s".', $username, $clearance));

        return Command::SUCCESS;
    }
}
