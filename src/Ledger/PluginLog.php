<?php

declare(strict_types=1);

namespace Mosyca\Core\Ledger;

/**
 * Plugin Log — opt-in, per-plugin structured log stream.
 *
 * A plugin writes to its own log by returning:
 *   PluginResult::ok($data)->withLedger(level: 'info', payload: [...])
 *
 * The operator's clearance controls the minimum level (logLevel: info/warning/off).
 * A log entry is written only when:
 *   1. result->ledgerPayload is not null (plugin called ->withLedger())
 *   2. Operator's logLevel <= payload's level
 *
 * File: {logDir}/plugins/{plugin_name}.jsonl
 *
 * Each entry carries the request_id from the Access Log for correlation.
 *
 * Log levels (ascending):
 *   debug < info < warning < error
 *   off = never write
 */
class PluginLog
{
    /** Level priority map — higher = more severe. */
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'off' => \PHP_INT_MAX,
    ];

    public function __construct(private readonly string $logDir)
    {
    }

    /**
     * Write a plugin log entry if the operator's logLevel permits it.
     *
     * @param array<string, mixed> $payload Plugin-curated payload (no PII, no request args)
     */
    public function write(
        string $requestId,
        string $pluginName,
        string $entryLevel,
        array $payload,
        string $operatorLogLevel,
    ): void {
        if (!$this->levelPermits($operatorLogLevel, $entryLevel)) {
            return;
        }

        $entry = [
            'ts' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'request_id' => $requestId,
            'plugin' => $pluginName,
            'level' => $entryLevel,
            ...$payload,
        ];

        $line = json_encode($entry, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $line) {
            return;
        }

        $this->appendLine($pluginName, $line);
    }

    /**
     * Returns true if operator's minimum level permits writing an entry of $entryLevel.
     */
    private function levelPermits(string $operatorLogLevel, string $entryLevel): bool
    {
        $minPriority = self::LEVELS[$operatorLogLevel] ?? self::LEVELS['off'];
        $entryPriority = self::LEVELS[$entryLevel] ?? self::LEVELS['info'];

        return $entryPriority >= $minPriority;
    }

    private function appendLine(string $pluginName, string $line): void
    {
        // Plugin name may contain colons (connector:resource:action) — safe to use as filename
        // after sanitizing. Replace colons with underscores for compatibility.
        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $pluginName).'.jsonl';
        $dir = $this->logDir.'/plugins';

        $this->ensureDir($dir);

        @file_put_contents($dir.'/'.$filename, $line."\n", \FILE_APPEND | \LOCK_EX);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            // Best-effort — logging failures must never crash the request.
        }
    }
}
