<?php

declare(strict_types=1);

namespace Mosyca\Core\Ledger;

/**
 * Access Log — always-on, arg-free audit trail.
 *
 * Written by ActionRunProcessor on every action execution, regardless of
 * result or action configuration. Never contains request arguments or result data.
 *
 * File: {logDir}/access.jsonl
 *
 * Schema (fixed — no request-arg fields ever present):
 *   ts          ISO 8601 with timezone
 *   request_id  UUID v4 — correlates with Action Log
 *   operator    Operator username
 *   clearance   Clearance level at time of call
 *   tenant_id   Tenant identifier (V0.9+, null for legacy entries)
 *   action      Full action name e.g. "shopware:order:get-revenue"
 *   duration_ms Wall time from dispatch to result
 *   success     bool
 *   error_code  Error category or null (never a message, never PII)
 *   http_status HTTP response code (Gateway only)
 *
 * error_code vocabulary:
 *   null         Success
 *   timeout      Outbound API call timed out
 *   auth_error   Outbound authentication failed
 *   api_error    Remote API returned 4xx/5xx
 *   action_error Unhandled exception or logic error inside action run()
 *   acl_denied   Clearance blocked this action call
 *   not_found    Action or resource not found
 */
class AccessLog
{
    private bool $dirEnsured = false;

    public function __construct(private readonly string $logDir)
    {
    }

    /**
     * Write one access log entry.
     *
     * @param array{
     *   ts: string,
     *   request_id: string,
     *   operator: string,
     *   clearance: string,
     *   tenant_id: string,
     *   action: string,
     *   duration_ms: int,
     *   success: bool,
     *   error_code: string|null,
     *   http_status: int,
     * } $entry
     */
    public function write(array $entry): void
    {
        $line = json_encode($entry, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $line) {
            return; // Silently skip on encode failure — access log must never crash the request
        }

        $this->appendLine($line);
    }

    private function appendLine(string $line): void
    {
        if (!$this->dirEnsured) {
            $this->ensureDir($this->logDir);
            $this->dirEnsured = true;
        }

        @file_put_contents($this->logDir.'/access.jsonl', $line."\n", \FILE_APPEND | \LOCK_EX);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            // Best-effort — if we cannot create the dir, writes will silently fail.
            // We must not crash the request over a logging failure.
        }
    }
}
