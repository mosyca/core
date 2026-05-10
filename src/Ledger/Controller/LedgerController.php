<?php

declare(strict_types=1);

namespace Mosyca\Core\Ledger\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * REST API for Ledger (read-only log access).
 *
 * GET /api/ledger/access                          — access log, paginated
 * GET /api/ledger/access?operator=alice           — filter by operator
 * GET /api/ledger/access?error_code=timeout       — filter by error category
 * GET /api/ledger/access?since=2026-05-01         — filter by date
 * GET /api/ledger/plugin/{name}                   — plugin log for one plugin
 * GET /api/ledger/plugin/{name}?request_id=...    — single call by correlation ID
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/api/ledger', name: 'mosyca_ledger_')]
final class LedgerController extends AbstractController
{
    public function __construct(private readonly string $logDir)
    {
    }

    #[Route('/access', name: 'access', methods: ['GET'])]
    public function access(Request $request): JsonResponse
    {
        $filePath = $this->logDir.'/access.jsonl';
        $entries = $this->readJsonl($filePath);

        $operator = $request->query->get('operator');
        $errorCode = $request->query->get('error_code');
        $since = $request->query->get('since');
        $limit = max(1, (int) $request->query->get('limit', '100'));
        $page = max(1, (int) $request->query->get('page', '1'));

        if (\is_string($operator) && '' !== $operator) {
            $entries = array_filter($entries, static fn ($e) => ($e['operator'] ?? '') === $operator);
        }

        if (\is_string($errorCode) && '' !== $errorCode) {
            $entries = array_filter($entries, static fn ($e) => ($e['error_code'] ?? null) === $errorCode);
        }

        if (\is_string($since) && '' !== $since) {
            $sinceTs = strtotime($since);
            if (false !== $sinceTs) {
                $entries = array_filter(
                    $entries,
                    static fn ($e) => \is_string($e['ts'] ?? null) && strtotime($e['ts']) >= $sinceTs,
                );
            }
        }

        $entries = array_values($entries);
        $total = \count($entries);
        $entries = \array_slice($entries, ($page - 1) * $limit, $limit);

        return $this->json([
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'entries' => $entries,
        ]);
    }

    #[Route('/plugin/{name}', name: 'plugin', methods: ['GET'], requirements: ['name' => '[a-zA-Z0-9_\-:]+'])]
    public function plugin(string $name, Request $request): JsonResponse
    {
        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name).'.jsonl';
        $filePath = $this->logDir.'/plugins/'.$filename;

        $entries = $this->readJsonl($filePath);

        $requestId = $request->query->get('request_id');
        $limit = max(1, (int) $request->query->get('limit', '100'));
        $page = max(1, (int) $request->query->get('page', '1'));

        if (\is_string($requestId) && '' !== $requestId) {
            $entries = array_filter($entries, static fn ($e) => ($e['request_id'] ?? '') === $requestId);
        }

        $entries = array_values($entries);
        $total = \count($entries);
        $entries = \array_slice($entries, ($page - 1) * $limit, $limit);

        return $this->json([
            'plugin' => $name,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'entries' => $entries,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonl(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }

        $fh = fopen($filePath, 'r');
        if (!$fh) {
            return [];
        }

        $entries = [];
        while (!feof($fh)) {
            $line = fgets($fh);
            if (!\is_string($line) || '' === trim($line)) {
                continue;
            }

            /** @var array<string, mixed>|null $entry */
            $entry = json_decode(trim($line), true);
            if (\is_array($entry)) {
                $entries[] = $entry;
            }
        }
        fclose($fh);

        return $entries;
    }
}
