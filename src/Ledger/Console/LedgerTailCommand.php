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
 * bin/console mosyca:ledger:tail [--plugin=<name>] [--lines=<n>] [--follow].
 *
 * Shows the last N lines of the Access Log or a Plugin Log.
 * With --follow, polls for new entries every second (Ctrl+C to stop).
 */
#[AsCommand(
    name: 'mosyca:ledger:tail',
    description: 'Tail the Access Log or a Plugin Log',
)]
final class LedgerTailCommand extends Command
{
    public function __construct(private readonly string $logDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name — tails that plugin\'s log instead of the access log')
            ->addOption('lines', 'n', InputOption::VALUE_REQUIRED, 'Number of lines to show initially', '20')
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Poll for new lines (Ctrl+C to stop)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginName = $input->getOption('plugin');
        $lines = max(1, (int) ($input->getOption('lines') ?? 20));
        $follow = (bool) $input->getOption('follow');

        if (\is_string($pluginName) && '' !== $pluginName) {
            $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $pluginName).'.jsonl';
            $filePath = $this->logDir.'/plugins/'.$filename;
            $label = 'Plugin Log: '.$pluginName;
        } else {
            $filePath = $this->logDir.'/access.jsonl';
            $label = 'Access Log';
        }

        if (!is_file($filePath)) {
            $io->warning(\sprintf('%s — file not found: %s', $label, $filePath));

            return Command::SUCCESS;
        }

        $io->title($label.' → '.$filePath);

        $this->printTail($output, $filePath, $lines);

        if ($follow) {
            $output->writeln('<comment>Following… (Ctrl+C to stop)</comment>');
            $offset = filesize($filePath) ?: 0;

            /* @phpstan-ignore-next-line */
            while (true) {
                clearstatcache(true, $filePath);
                $currentSize = filesize($filePath) ?: 0;

                if ($currentSize > $offset) {
                    $fh = fopen($filePath, 'r');
                    if ($fh) {
                        fseek($fh, $offset);
                        while (!feof($fh)) {
                            $line = fgets($fh);
                            if (\is_string($line) && '' !== trim($line)) {
                                $output->writeln($this->colorizeLine(trim($line)));
                            }
                        }
                        $offset = ftell($fh) ?: $currentSize;
                        fclose($fh);
                    }
                }

                usleep(500_000); // poll every 500 ms
            }
        }

        return Command::SUCCESS;
    }

    private function printTail(OutputInterface $output, string $filePath, int $lines): void
    {
        $fh = fopen($filePath, 'r');
        if (!$fh) {
            return;
        }

        // Collect last N lines
        $buffer = [];
        while (!feof($fh)) {
            $line = fgets($fh);
            if (\is_string($line) && '' !== trim($line)) {
                $buffer[] = trim($line);
                if (\count($buffer) > $lines) {
                    array_shift($buffer);
                }
            }
        }
        fclose($fh);

        foreach ($buffer as $line) {
            $output->writeln($this->colorizeLine($line));
        }
    }

    /**
     * Add terminal colors for success/error/warning levels.
     */
    private function colorizeLine(string $jsonLine): string
    {
        $entry = json_decode($jsonLine, true);
        if (!\is_array($entry)) {
            return $jsonLine;
        }

        $success = $entry['success'] ?? true;
        $errorCode = $entry['error_code'] ?? null;
        $level = $entry['level'] ?? null;

        if ('error' === $level || 'warning' === $errorCode || (!$success && null !== $errorCode)) {
            return '<comment>'.$jsonLine.'</comment>';
        }

        if (false === $success || null !== $errorCode) {
            return '<error>'.$jsonLine.'</error>';
        }

        return $jsonLine;
    }
}
