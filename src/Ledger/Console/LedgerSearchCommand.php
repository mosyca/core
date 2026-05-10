<?php

declare(strict_types=1);

namespace Mosyca\Core\Ledger\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * bin/console mosyca:ledger:search [options].
 *
 * Searches the Access Log with optional filters.
 *
 * Examples:
 *   mosyca:ledger:search --operator=alice
 *   mosyca:ledger:search --error-code=timeout --since=-1h
 *   mosyca:ledger:search --plugin=shopware:order:get-revenue --since=2026-05-01
 */
#[AsCommand(
    name: 'mosyca:ledger:search',
    description: 'Search the Access Log',
)]
final class LedgerSearchCommand extends Command
{
    public function __construct(private readonly string $logDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('operator', 'o', InputOption::VALUE_REQUIRED, 'Filter by operator username')
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Filter by plugin name (exact or glob)')
            ->addOption('error-code', null, InputOption::VALUE_REQUIRED, 'Filter by error_code (e.g. timeout, acl_denied)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only entries since this date/time (ISO 8601 or -1h/-30m relative)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max entries to show', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filePath = $this->logDir.'/access.jsonl';
        if (!is_file($filePath)) {
            $io->warning('Access log not found: '.$filePath);

            return Command::SUCCESS;
        }

        $filterOperator = $input->getOption('operator');
        $filterPlugin = $input->getOption('plugin');
        $filterErrorCode = $input->getOption('error-code');
        $filterSince = $input->getOption('since');
        $limit = max(1, (int) ($input->getOption('limit') ?? 50));

        $since = $this->parseDateTime($filterSince);

        $fh = fopen($filePath, 'r');
        if (!$fh) {
            $io->error('Cannot open access log: '.$filePath);

            return Command::FAILURE;
        }

        $results = [];
        while (!feof($fh)) {
            $line = fgets($fh);
            if (!\is_string($line) || '' === trim($line)) {
                continue;
            }

            /** @var array<string, mixed>|null $entry */
            $entry = json_decode(trim($line), true);
            if (!\is_array($entry)) {
                continue;
            }

            if (\is_string($filterOperator) && '' !== $filterOperator && ($entry['operator'] ?? '') !== $filterOperator) {
                continue;
            }

            if (\is_string($filterPlugin) && '' !== $filterPlugin) {
                $entryPlugin = (string) ($entry['plugin'] ?? '');
                if (!fnmatch($filterPlugin, $entryPlugin) && $entryPlugin !== $filterPlugin) {
                    continue;
                }
            }

            if (\is_string($filterErrorCode) && '' !== $filterErrorCode && ($entry['error_code'] ?? null) !== $filterErrorCode) {
                continue;
            }

            if (null !== $since) {
                $entryTs = \is_string($entry['ts'] ?? null) ? strtotime($entry['ts']) : false;
                if (false === $entryTs || $entryTs < $since) {
                    continue;
                }
            }

            $results[] = $entry;
        }
        fclose($fh);

        // Show last N results
        $results = \array_slice($results, -$limit);

        if (empty($results)) {
            $io->info('No matching entries found.');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Access Log — %d result(s)', \count($results)));

        $io->table(
            ['ts', 'operator', 'plugin', 'ms', 'ok', 'error_code', 'status'],
            array_map(static fn ($e) => [
                $e['ts'] ?? '-',
                $e['operator'] ?? '-',
                $e['plugin'] ?? '-',
                $e['duration_ms'] ?? '-',
                ($e['success'] ?? false) ? '✓' : '✗',
                $e['error_code'] ?? '',
                $e['http_status'] ?? '-',
            ], $results),
        );

        return Command::SUCCESS;
    }

    /**
     * Parse --since value: ISO 8601 date or relative (-1h, -30m, -2d).
     */
    private function parseDateTime(mixed $value): ?int
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        // Relative format: -1h, -30m, -2d
        if (preg_match('/^-(\d+)(h|m|d)$/', $value, $matches)) {
            $amount = (int) $matches[1];
            $unit = $matches[2];

            if ('h' === $unit) {
                return time() - $amount * 3600;
            }
            if ('m' === $unit) {
                return time() - $amount * 60;
            }

            // 'd'
            return time() - $amount * 86400;
        }

        $ts = strtotime($value);

        return false !== $ts ? $ts : null;
    }
}
