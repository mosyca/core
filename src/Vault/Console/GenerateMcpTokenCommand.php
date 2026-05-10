<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Console;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Mosyca\Core\Vault\Entity\McpToken;
use Mosyca\Core\Vault\Repository\McpTokenRepository;
use Mosyca\Core\Vault\Repository\OperatorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mosyca:vault:generate-mcp-token',
    description: 'Generate a long-lived MCP Bearer token for an operator.',
)]
final class GenerateMcpTokenCommand extends Command
{
    public function __construct(
        private readonly OperatorRepository $operatorRepository,
        private readonly McpTokenRepository $mcpTokenRepository,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('operator', 'o', InputOption::VALUE_REQUIRED, 'Operator username')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Token label (e.g. "Claude Desktop – Laptop")', 'Claude Desktop')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Token lifetime in days', '90');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getOption('operator') ?? $io->ask('Operator username');

        if (!\is_string($username) || '' === $username) {
            $io->error('Operator username is required.');

            return Command::FAILURE;
        }

        $operator = $this->operatorRepository->findByUsername($username);

        if (null === $operator) {
            $io->error(\sprintf('Operator "%s" not found.', $username));

            return Command::FAILURE;
        }

        $ttlDays = (int) ($input->getOption('ttl') ?? 90);
        $name = (string) ($input->getOption('name') ?? 'Claude Desktop');
        $jti = bin2hex(random_bytes(16));
        $expiresAt = new \DateTimeImmutable("+{$ttlDays} days");

        // Generate JWT with custom claims
        $tokenString = $this->jwtManager->createFromPayload($operator, [
            'jti' => $jti,
            'exp' => $expiresAt->getTimestamp(),
        ]);

        // Store audit record
        $mcpToken = new McpToken($operator, $name, $jti, $expiresAt);
        $this->mcpTokenRepository->save($mcpToken, flush: true);

        $io->success(\sprintf(
            "MCP Token for operator \"%s\" (clearance: %s)\nLabel: %s\nExpires: %s\n\nToken:\n\n%s",
            $operator->getUsername(),
            $operator->getClearance(),
            $name,
            $expiresAt->format('Y-m-d'),
            $tokenString,
        ));

        $io->note('Add this token to your Claude Desktop config as MOSYCA_TOKEN.');

        return Command::SUCCESS;
    }
}
