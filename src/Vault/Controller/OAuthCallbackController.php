<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Controller;

use Mosyca\Core\Vault\Provisioning\OAuthCallbackHandlerInterface;
use Mosyca\Core\Vault\Provisioning\OAuthStateEncoder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Generic OAuth authorization-code callback endpoint.
 *
 * GET /api/vault/oauth/callback
 *
 * Receives `code` and `state` query parameters from the OAuth provider after the operator
 * completes the authorization flow. Verifies the `state` CSRF token (OAuthStateEncoder),
 * dispatches to the matching OAuthCallbackHandlerInterface, and stores the resulting
 * credentials in the Vault via the handler.
 *
 * SECURITY:
 * - `state` is HMAC-SHA256 signed (OAuthStateEncoder with %kernel.secret%).
 *   Tampered or expired states → 400 — the authorization code is never consumed.
 * - The authorization `code` MUST NOT appear in response bodies, logs, or exception
 *   messages (Vault Rule V2). Generic error messages only.
 * - POST requests are rejected at the router level (405 Method Not Allowed).
 */
#[Route('/api/vault/oauth/callback', name: 'mosyca_vault_oauth_callback', methods: ['GET'])]
final class OAuthCallbackController
{
    /** @var OAuthCallbackHandlerInterface[] */
    private readonly array $handlers;

    /**
     * @param iterable<OAuthCallbackHandlerInterface> $handlers Tagged handler collection
     */
    public function __construct(
        private readonly OAuthStateEncoder $stateEncoder,
        iterable $handlers = [],
    ) {
        /** @var OAuthCallbackHandlerInterface[] $materialized */
        $materialized = [...$handlers];
        $this->handlers = $materialized;
    }

    public function __invoke(Request $request): JsonResponse
    {
        // OAuth error from provider (e.g. user denied access).
        if ('' !== $request->query->getString('error')) {
            return new JsonResponse(
                ['error' => 'OAuth authorisation was denied or failed.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $code = $request->query->getString('code');
        $state = $request->query->getString('state');

        if ('' === $code) {
            return new JsonResponse(
                ['error' => '"code" query parameter is required.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ('' === $state) {
            return new JsonResponse(
                ['error' => '"state" query parameter is required.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Verify CSRF state — tampered or expired → 400.
        // 400 is used (not 403) to avoid revealing whether the endpoint exists for this integration.
        try {
            $ctx = $this->stateEncoder->decode($state);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(
                ['error' => 'Invalid or expired OAuth state parameter.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $integration = $ctx['integration'];
        $tenantId = $ctx['tenant_id'];
        $userId = $ctx['user_id'];

        // Find the integration-specific handler.
        $handler = $this->findHandler($integration);

        if (null === $handler) {
            return new JsonResponse(
                ['error' => \sprintf('No OAuth callback handler registered for "%s".', $integration)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Delegate to the handler. On failure, return a generic error (Vault Rule V2).
        try {
            $handler->handleCallback($code, $tenantId, $userId);
        } catch (\Throwable) {
            return new JsonResponse(
                ['error' => 'OAuth token exchange failed. Check integration configuration.'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new JsonResponse(['stored' => true], Response::HTTP_OK);
    }

    private function findHandler(string $integration): ?OAuthCallbackHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($integration)) {
                return $handler;
            }
        }

        return null;
    }
}
