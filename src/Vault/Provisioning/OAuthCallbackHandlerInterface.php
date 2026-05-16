<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Provisioning;

/**
 * Contract for integration-specific OAuth authorization-code exchange.
 *
 * Connector plugins implement this interface to complete the OAuth callback flow:
 *   1. Call the integration's token endpoint with the authorization code.
 *   2. Persist the resulting token payload to the Vault via VaultManager::storeSecret().
 *
 * After handleCallback() returns successfully, a call to VaultManager::retrieveSecret()
 * for the same (tenantId, integrationType, userId) MUST return a valid, non-expired payload.
 *
 * DI tag: mosyca.vault.oauth_handler  (applied via #[AutoconfigureTag] or services.yaml)
 *
 * SECURITY: Implementations MUST NOT log the authorization code or the resulting token
 * value (Vault Rule V2). Use generic error messages when throwing exceptions — no code
 * or token value may appear in exception messages.
 */
interface OAuthCallbackHandlerInterface
{
    /**
     * Returns true if this handler manages the given integration type.
     *
     * Example: a SpotifyOAuthCallbackHandler returns true only for 'spotify'.
     */
    public function supports(string $integration): bool;

    /**
     * Exchange the authorization code for tokens and persist to the Vault.
     *
     * This method is called by OAuthCallbackController after CSRF state verification.
     * It MUST NOT throw if the exchange succeeds. On failure it MUST throw a descriptive
     * exception (e.g. \RuntimeException) so the caller can surface a generic error.
     *
     * SECURITY: The $code value MUST NOT appear in any exception message (Vault Rule V2).
     *
     * @throws \RuntimeException if the token exchange fails or the credentials cannot be stored
     */
    public function handleCallback(string $code, string $tenantId, ?string $userId): void;
}
