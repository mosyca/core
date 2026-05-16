<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Console;

use Mosyca\Core\Vault\VaultManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Securely provision a Vault secret from the server command line.
 *
 * SECURITY: The credential JSON payload is always read interactively from stdin —
 * it is NEVER accepted as a CLI argument or option to prevent shell history leaks.
 * (e.g. `history | grep vault` would never expose a credential).
 *
 * Usage:
 *   mosyca:vault:set bluevendsand shopware6
 *   mosyca:vault:set bluevendsand spotify --user-id=user-karim --expires-in-days=30
 */
#[AsCommand(
    name: 'mosyca:vault:set',
    description: 'Securely store or update an encrypted credential set in the Vault.',
)]
final class SetVaultSecretCommand extends Command
{
    public function __construct(
        private readonly VaultManager $vaultManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant_id', InputArgument::REQUIRED, 'The Mosyca tenant identifier (e.g. "bluevendsand")')
            ->addArgument('integration_type', InputArgument::REQUIRED, 'The integration/connector identifier (e.g. "shopware6", "spotify")')
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'Scope credential to a specific end-user (for OAuth flows). Omit for tenant-level M2M credentials.')
            ->addOption('expires-in-days', null, InputOption::VALUE_REQUIRED, 'Credential TTL in days. Omit for non-expiring credentials.')
            ->setHelp(<<<'HELP'
                The <info>mosyca:vault:set</info> command securely stores or updates an encrypted
                M2M credential set in the Vault for a given tenant and integration type.

                The JSON payload is always prompted interactively — it is never passed
                as a CLI argument to prevent shell history exposure.

                  <info>php bin/console mosyca:vault:set bluevendsand shopware6</info>

                You will be prompted to enter the JSON payload, for example:
                  <comment>{"client_id": "sw6.client.123", "client_secret": "s3cr3t"}</comment>

                To scope the credential to a specific user (OAuth):
                  <info>php bin/console mosyca:vault:set bluevendsand spotify --user-id=user-karim --expires-in-days=30</info>
                HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $tenantId */
        $tenantId = $input->getArgument('tenant_id');
        /** @var string $integrationType */
        $integrationType = $input->getArgument('integration_type');

        $userId = $input->getOption('user-id');
        $userId = \is_string($userId) && '' !== $userId ? $userId : null;

        $expiresInDays = $input->getOption('expires-in-days');
        $expiresAt = null;

        if (null !== $expiresInDays) {
            $days = filter_var($expiresInDays, \FILTER_VALIDATE_INT);
            if (false === $days || $days <= 0) {
                $io->error('--expires-in-days must be a positive integer (e.g. 30, 90, 365).');

                return Command::FAILURE;
            }

            $expiresAt = new \DateTimeImmutable(\sprintf('+%d days', $days));
        }

        $io->title(\sprintf('Vault — Set secret for "%s" / "%s"', $tenantId, $integrationType));

        if (null !== $userId) {
            $io->comment(\sprintf('Scoped to user: %s', $userId));
        }

        if (null !== $expiresAt) {
            $io->comment(\sprintf('Expires at: %s', $expiresAt->format('Y-m-d H:i:s')));
        }

        $io->caution([
            'The JSON payload you enter will be encrypted and stored in the Vault.',
            'It will NOT appear in bash/shell history (reading from stdin).',
            'Example: {"client_id": "abc", "client_secret": "xyz"}',
        ]);

        $rawPayload = $io->ask('Enter JSON payload');

        if (!\is_string($rawPayload) || '' === trim($rawPayload)) {
            $io->error('Payload cannot be empty.');

            return Command::FAILURE;
        }

        try {
            $decoded = json_decode($rawPayload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(\sprintf('Invalid JSON: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if (!\is_array($decoded)) {
            $io->error('Payload must be a JSON object (e.g. {"key": "value"}), not a scalar or array.');

            return Command::FAILURE;
        }

        if ([] === $decoded) {
            $io->warning('Payload is an empty JSON object {}. Storing it anyway — is this intentional?');
        }

        /* @var array<string, mixed> $decoded */
        $this->vaultManager->storeSecret(
            tenantId: $tenantId,
            integrationType: $integrationType,
            payload: $decoded,
            userId: $userId,
            expiresAt: $expiresAt,
        );

        $io->success(\sprintf(
            'Secret stored for tenant "%s" / integration "%s"%s.',
            $tenantId,
            $integrationType,
            null !== $userId ? \sprintf(' (user: %s)', $userId) : '',
        ));

        return Command::SUCCESS;
    }
}
