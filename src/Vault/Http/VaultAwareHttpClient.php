<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Http;

use Mosyca\Core\Vault\Exception\UriNotAllowedException;
use Mosyca\Core\Vault\Refresh\TokenRefresherInterface;
use Mosyca\Core\Vault\VaultManager;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * HTTP client decorator that provides transparent Vault-backed token injection.
 *
 * Usage in connector actions / services:
 * <code>
 *     $response = $this->httpClient->request('GET', 'https://api.shopware.com/v1/orders', [
 *         'extra' => [
 *             'vault' => [
 *                 'integration' => 'shopware6',    // required
 *                 'tenant_id'   => 'bluevendsand', // required
 *                 'user_id'     => null,            // optional — null for tenant-level M2M
 *             ],
 *         ],
 *     ]);
 * </code>
 *
 * When `extra.vault` is absent, the request is passed through unchanged (no overhead).
 *
 * Request lifecycle (when vault context is present):
 *   1. Validate URI against integration-specific allowlist (Rule V5 — BEFORE vault access).
 *   2. Retrieve encrypted credentials from Vault and extract Bearer token.
 *   3. Inject `Authorization: Bearer <token>` header.
 *   4. Execute request via inner client (evaluates response headers, triggering HTTP).
 *   5. On HTTP 401: call matching TokenRefresherInterface, re-fetch token, retry ONCE.
 *
 * SECURITY:
 *   - Allowlist check happens BEFORE vault access — blocked URIs never trigger decryption.
 *   - Token values MUST NEVER appear in exception messages or logs (Vault Rule V2).
 *   - Retry is exactly once — no infinite loop risk.
 *   - Authorization header is REPLACED (not appended) to prevent caller-supplied bypass.
 */
final class VaultAwareHttpClient implements HttpClientInterface
{
    /** @var TokenRefresherInterface[] */
    private readonly array $refreshers;

    /**
     * @param array<string, string[]>           $allowedUris Allowlist: integration name → list of allowed base URIs
     * @param iterable<TokenRefresherInterface> $refreshers  Token refreshers (tagged service collection)
     */
    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly VaultManager $vault,
        private readonly array $allowedUris,
        iterable $refreshers = [],
    ) {
        /** @var TokenRefresherInterface[] $materialized */
        $materialized = [...$refreshers];
        $this->refreshers = $materialized;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws UriNotAllowedException    if the target URI is not in the integration's allowlist (Rule V5)
     * @throws \InvalidArgumentException if 'integration' or 'tenant_id' is missing from the vault context
     * @throws \RuntimeException         if the vault payload contains no recognisable token key
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $vaultContext = $this->extractVaultContext($options);

        if (null === $vaultContext) {
            // No vault context — pure passthrough, zero overhead.
            return $this->inner->request($method, $url, $options);
        }

        $integration = \is_string($vaultContext['integration'] ?? null) ? (string) $vaultContext['integration'] : '';
        $tenantId = \is_string($vaultContext['tenant_id'] ?? null) ? (string) $vaultContext['tenant_id'] : '';
        $userId = \is_string($vaultContext['user_id'] ?? null) && '' !== ($vaultContext['user_id'])
            ? (string) $vaultContext['user_id']
            : null;

        if ('' === $integration) {
            throw new \InvalidArgumentException('Vault context "integration" key must be a non-empty string.');
        }

        if ('' === $tenantId) {
            throw new \InvalidArgumentException('Vault context "tenant_id" key must be a non-empty string.');
        }

        // Security Rule V5: validate URI BEFORE retrieving any token from the Vault.
        // If the URI is not in the allowlist, we never touch the vault.
        $this->assertUriAllowed($url, $integration);

        // Retrieve token and inject Authorization header.
        $payload = $this->vault->retrieveSecret($tenantId, $integration, $userId);
        $token = $this->extractBearerToken($payload);
        $requestOptions = $this->withAuthHeader($options, $token);

        // Execute the request. getStatusCode() evaluates the lazy response (HTTP headers received).
        $response = $this->inner->request($method, $url, $requestOptions);

        // On 401: find a matching refresher, refresh the token, and retry exactly once.
        if (401 === $response->getStatusCode()) {
            $refresher = $this->findRefresher($integration);

            if (null !== $refresher) {
                $refresher->refresh($tenantId, $userId);

                // Re-fetch updated credentials from Vault after refresh.
                $newPayload = $this->vault->retrieveSecret($tenantId, $integration, $userId);
                $newToken = $this->extractBearerToken($newPayload);
                $retryOptions = $this->withAuthHeader($options, $newToken);

                // Retry exactly once — even if this returns 401 again, we stop here.
                return $this->inner->request($method, $url, $retryOptions);
            }
        }

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self(
            $this->inner->withOptions($options),
            $this->vault,
            $this->allowedUris,
            $this->refreshers,
        );
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * Extract the vault context from the request options, or return null if absent.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function extractVaultContext(array $options): ?array
    {
        $extra = $options['extra'] ?? null;
        if (!\is_array($extra)) {
            return null;
        }

        $vault = $extra['vault'] ?? null;
        if (!\is_array($vault)) {
            return null;
        }

        /* @var array<string, mixed> $vault */
        return $vault;
    }

    /**
     * Assert the target URI starts with one of the allowed base URIs for the integration.
     *
     * Fail-closed: if the integration has no entry (or an empty list) → throws.
     *
     * @throws UriNotAllowedException
     */
    private function assertUriAllowed(string $url, string $integration): void
    {
        $allowed = $this->allowedUris[$integration] ?? [];

        foreach ($allowed as $baseUri) {
            if (str_starts_with($url, $baseUri)) {
                return;
            }
        }

        throw UriNotAllowedException::forUri($url, $integration);
    }

    /**
     * Extract a Bearer token from the decrypted credential payload.
     *
     * Tries 'access_token' first (OAuth 2.0 standard), then 'token' (generic fallback).
     *
     * SECURITY: Exception message MUST NOT include the token value (Vault Rule V2).
     *
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException if neither 'access_token' nor 'token' key is present
     */
    private function extractBearerToken(array $payload): string
    {
        $token = $payload['access_token'] ?? $payload['token'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new \RuntimeException('Vault payload does not contain a valid "access_token" or "token" key. Ensure the credential was stored with the correct structure.');
        }

        return $token;
    }

    /**
     * Return a copy of $options with the Authorization header set (replacing any existing value).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function withAuthHeader(array $options, string $token): array
    {
        /** @var array<string, string|string[]> $existing */
        $existing = \is_array($options['headers'] ?? null) ? (array) $options['headers'] : [];
        $options['headers'] = array_merge($existing, ['Authorization' => 'Bearer '.$token]);

        return $options;
    }

    private function findRefresher(string $integration): ?TokenRefresherInterface
    {
        foreach ($this->refreshers as $refresher) {
            if ($refresher->supports($integration)) {
                return $refresher;
            }
        }

        return null;
    }
}
