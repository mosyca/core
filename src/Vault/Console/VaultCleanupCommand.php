<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Console;

use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Vault\Repository\VaultSecretRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Garbage-collect orphaned Vault secrets.
 *
 * An "orphaned" secret is one whose integration_type no longer corresponds to
 * any registered action in the ActionRegistry. This happens when a plugin is
 * uninstalled but its secrets are left behind in the Vault.
 *
 * ## Two modes
 *
 *   Dry-run (default): Lists orphaned secrets in a table. Nothing is deleted.
 *   Force (--force):   Permanently deletes all listed orphaned secrets.
 *
 * ## Safety guarantees
 *
 *   - If ActionRegistry contains no registered actions the command aborts
 *     immediately to prevent accidental mass deletion.
 *   - The --tenant option scopes both discovery and deletion to one tenant.
 *   - Credential payloads are never shown in output (Vault Rule V2).
 *
 * @see VaultSecretRepository::findOrphaned()
 */
#[AsCommand(
    name: 'mosyca:vault:cleanup',
    description: 'Detect and remove Vault secrets whose integration plugin is no longer installed.',
)]
final class VaultCleanupCommand extends Command
{
    public function __construct(
        private readonly ActionRegistry $actionRegistry,
        private readonly VaultSecretRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Actually delete orphaned secrets (without this flag the command runs in dry-run mode)',
            )
            ->addOption(
                'tenant',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit detection and deletion to a specific tenant slug (e.g. "production")',
            )
            ->setHelp(<<<'HELP'
                The <info>mosyca:vault:cleanup</info> command removes encrypted Vault secrets
                that belong to plugins which are no longer installed.

                <comment>Dry-run (default — safe to run any time):</comment>
                  <info>php bin/console mosyca:vault:cleanup</info>

                <comment>Scope to a single tenant:</comment>
                  <info>php bin/console mosyca:vault:cleanup --tenant=production</info>

                <comment>Delete orphaned secrets (irreversible):</comment>
                  <info>php bin/console mosyca:vault:cleanup --force</info>
                  <info>php bin/console mosyca:vault:cleanup --force --tenant=production</info>

                A secret is considered "orphaned" when its integration_type does not
                match the first segment of any action registered in ActionRegistry
                (e.g. "shopware6" from "shopware6:product:fetch").
                HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $force = (bool) $input->getOption('force');

        // Coerce --tenant='' (user typed --tenant with no value) to null.
        $tenantRaw = $input->getOption('tenant');
        $tenantId = \is_string($tenantRaw) && '' !== $tenantRaw ? $tenantRaw : null;

        // ── Step 1: derive active integration namespaces from ActionRegistry ──

        $activeTypes = $this->extractActiveIntegrationTypes();

        if ([] === $activeTypes) {
            $io->error([
                'The ActionRegistry contains no registered actions.',
                'Aborting to prevent accidental mass deletion of all Vault secrets.',
                'Ensure the Mosyca framework is properly bootstrapped before running this command.',
            ]);

            return Command::FAILURE;
        }

        // ── Step 2: find orphaned secrets ─────────────────────────────────────

        $orphaned = $this->repository->findOrphaned($activeTypes, $tenantId);

        $scopeLabel = null !== $tenantId ? \sprintf(' (tenant: %s)', $tenantId) : '';

        if ([] === $orphaned) {
            $io->success(\sprintf('Vault is clean%s. No orphaned secrets found.', $scopeLabel));

            return Command::SUCCESS;
        }

        // ── Step 3: display summary table ─────────────────────────────────────

        $rows = [];
        foreach ($orphaned as $secret) {
            $rows[] = [
                $secret->getTenantId(),
                $secret->getIntegrationType(),
                $secret->getUserId() ?? '—',
                $secret->getUpdatedAt()->format('Y-m-d H:i'),
            ];
        }

        $io->table(
            ['Tenant', 'Integration Type', 'User', 'Updated At'],
            $rows,
        );

        // ── Step 4: dry-run or force ───────────────────────────────────────────

        if (!$force) {
            $io->note(\sprintf(
                '%d orphaned secret(s) found%s. Run with --force to permanently delete them.',
                \count($orphaned),
                $scopeLabel,
            ));

            return Command::SUCCESS;
        }

        $this->repository->deleteMany($orphaned);

        $io->success(\sprintf(
            '%d orphaned secret(s) deleted%s.',
            \count($orphaned),
            $scopeLabel,
        ));

        return Command::SUCCESS;
    }

    /**
     * Extract unique integration namespaces from all registered actions.
     *
     * Action names follow the convention "{namespace}:{resource}:{action}".
     * The namespace (first segment) is the integration_type stored in Vault.
     *
     * @return string[]
     */
    private function extractActiveIntegrationTypes(): array
    {
        $types = [];
        foreach ($this->actionRegistry->all() as $action) {
            $types[explode(':', $action->getName(), 2)[0]] = true;
        }

        return array_keys($types);
    }
}
