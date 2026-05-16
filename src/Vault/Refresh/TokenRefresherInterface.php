<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Refresh;

/**
 * Contract for integration-specific access token refresh logic.
 *
 * Connector plugins implement this interface to handle the token lifecycle
 * for their specific OAuth / API credential scheme (e.g. Shopware 6 client-credentials,
 * Spotify authorization-code-flow, etc.).
 *
 * The refresher is responsible for:
 *   1. Calling the integration's token endpoint to obtain a new access token.
 *   2. Persisting the updated credentials back to the Vault via VaultManager::storeSecret().
 *
 * After refresh() returns, a call to VaultManager::retrieveSecret() for the same
 * (tenantId, integrationType, userId) MUST return a payload with a valid, non-expired token.
 *
 * DI tag: mosyca.vault.refresher  (auto-applied via #[AutoconfigureTag] or services.yaml)
 */
interface TokenRefresherInterface
{
    /**
     * Returns true if this refresher handles the given integration type.
     *
     * Example: a SpotifyTokenRefresher returns true only for 'spotify'.
     */
    public function supports(string $integrationType): bool;

    /**
     * Refresh the access token and persist the updated credentials to the Vault.
     *
     * This method is called by VaultAwareHttpClient when an HTTP 401 response is received.
     * It MUST NOT throw if the refresh succeeds. On failure it MUST throw a descriptive
     * exception (e.g. \RuntimeException) so the caller can surface an appropriate error.
     *
     * SECURITY: The implementation MUST NOT log the raw token value (Vault Rule V2).
     *
     * @throws \RuntimeException if the refresh request fails or the new token cannot be stored
     */
    public function refresh(string $tenantId, ?string $userId): void;
}
