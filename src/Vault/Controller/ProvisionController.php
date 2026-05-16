<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Controller;

use Mosyca\Core\Vault\VaultManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Write-only out-of-band credential provisioning endpoint.
 *
 * POST /api/vault/provision
 *
 * Receives integration credentials from a human operator and stores them encrypted in the Vault.
 * The URL must be cryptographically signed by ProvisioningLinkGenerator (UriSigner + kernel.secret).
 *
 * Context (integration, tenant_id, user_id) is embedded in the signed URL as query parameters —
 * they are never supplied by the caller in the request body.
 * The request body must be a JSON object containing the integration-specific credential payload.
 *
 * SECURITY:
 * - No #[CurrentUser] required — the signed URL IS the authorisation credential (Vault Rule V5).
 * - Unsigned or expired URLs → 403 Forbidden. The Vault is never accessed on rejection.
 * - The endpoint MUST only be served over HTTPS in production.
 * - Credentials are never returned in any response (Vault Rule V2).
 * - GET requests are rejected at the router level (405 Method Not Allowed).
 */
#[Route('/api/vault/provision', name: 'mosyca_vault_provision', methods: ['POST'])]
final class ProvisionController
{
    public function __construct(
        private readonly VaultManager $vault,
        private readonly UriSigner $uriSigner,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // Step 1: Verify signed URL — covers all query parameters and the embedded expiry.
        // The Vault is NOT accessed when the signature is invalid (Vault Rule V5 pattern).
        if (!$this->uriSigner->checkRequest($request)) {
            return new JsonResponse(
                ['error' => 'Invalid or expired provisioning link.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        // Step 2: Extract context from signed query parameters.
        $integration = $request->query->getString('integration');
        $tenantId = $request->query->getString('tenant_id');
        $userId = $request->query->getString('user_id') ?: null;

        if ('' === $integration) {
            return new JsonResponse(
                ['error' => '"integration" query parameter is required.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ('' === $tenantId) {
            return new JsonResponse(
                ['error' => '"tenant_id" query parameter is required.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Step 3: Decode and validate the credential payload from the request body.
        // Must be a JSON object ({...}), not a JSON array ([...]) or scalar.
        // json_decode without $assoc returns stdClass for objects and array for JSON arrays,
        // allowing us to distinguish them — PHP cannot otherwise tell '{}' from '[]'
        // when decoded associatively (both yield an empty array).
        $content = $request->getContent();
        $decoded = json_decode($content);

        if (!\is_object($decoded)) {
            return new JsonResponse(
                ['error' => 'Request body must be a JSON object.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true);

        // Step 4: Store credentials — encrypted at rest via SodiumSecretCipher.
        $this->vault->storeSecret($tenantId, $integration, $payload, $userId);

        return new JsonResponse(['stored' => true], Response::HTTP_CREATED);
    }
}
