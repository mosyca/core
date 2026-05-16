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
 * ── Request lifecycle (when vault context is present) ────────────────────────
 *
 * Static-allowlist path (the standard case):
 *   1. Validate URI against the static $allowedUris list (Rule V5 — BEFORE vault access).
 *   2. Retrieve encrypted credentials from Vault and extract Bearer token.
 *   3. Inject `Authorization: Bearer <token>` header.
 *   4. Execute request via inner client.
 *   5. On HTTP 401: call matching TokenRefresherInterface, re-fetch token, retry ONCE.
 *
 * Dynamic-allowlist path (when $allowedUris[integration] is empty + $uriResolver set):
 *   1. Retrieve encrypted credentials from Vault (payload contains operator-provisioned base_url).
 *   2. Call VaultUriResolverInterface::resolve() to derive the allowed URI list from the payload.
 *   3. Validate the target URI against the resolved list (Rule V5 intent maintained — see below).
 *   4. Extract Bearer token from payload and inject `Authorization: Bearer <token>` header.
 *   5. Execute request via inner client.
 *   6. On HTTP 401: same retry logic as above.
 *
 * ── Rule V5 compliance in the dynamic path ───────────────────────────────────
 * Rule V5 states: "validate the target URI against an integration-specific
 * allowlist before injecting any Authorization header." In the dynamic path, the
 * Vault IS accessed before the URI check — but only to retrieve the
 * operator-provisioned `base_url`. Token injection still only occurs after URI
 * validation. The `base_url` comes from the Vault (trusted, operator-set) and
 * cannot be influenced by LLM input, so the security guarantee is maintained.
 *
 * ── Security notes ───────────────────────────────────────────────────────────
 * - Token values MUST NEVER appear in exception messages or logs (Vault Rule V2).
 * - Retry is exactly once — no infinite loop risk.
 * - Authorization header is REPLACED (not appended) to prevent caller-supplied bypass.
 */
final class VaultAwareHttpClient implements HttpClientInterface
{
    /** @var TokenRefresherInterface[] */
    private readonly array $refreshers;

    /**
     * @param array<string, string[]>           $allowedUris Allowlist: integration name → list of allowed base URIs.
     *                                                       Set to an empty map (`[]`) when using $uriResolver.
     * @param iterable<TokenRefresherInterface> $refreshers  Token refreshers (tagged service collection)
     * @param VaultUriResolverInterface|null    $uriResolver Optional dynamic URI resolver — used when the
     *                                                       static $allowedUris list for an integration is empty
     */
    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly VaultManager $vault,
        private readonly array $allowedUris,
        iterable $refreshers = [],
        private readonly ?VaultUriResolverInterface $uriResolver = null,
    ) {
        /** @var TokenRefresherInterface[] $materialized */
        $materialized = [...$refreshers];
        $this->refreshers = $materialized;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws UriNotAllowedException    if the target URI is not in the allowlist (static or dynamic)
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

        // ── URI allowlist enforcement (Rule V5) ──────────────────────────────
        //
        // If a static allowlist is configured for this integration, validate the
        // URI BEFORE accessing the Vault — blocked URIs never trigger decryption.
        //
        // If the static list is empty AND a VaultUriResolverInterface is registered,
        // retrieve the payload first (to get the operator-provisioned base_url), then
        // derive the allowed list from the resolver, then validate the URI.
        //
        // If neither is configured → fail-closed: throw UriNotAllowedException
        // without any vault access.
        $staticAllowed = $this->allowedUris[$integration] ?? [];

        if ([] !== $staticAllowed) {
            // ── Static-allowlist path ─────────────────────────────────────────
            // Classic Rule V5: URI validated before vault access.
            $this->assertUriAllowed($url, $integration, $staticAllowed);
            $payload = $this->vault->retrieveSecret($tenantId, $integration, $userId);
        } elseif (null !== $this->uriResolver) {
            // ── Dynamic-allowlist path ────────────────────────────────────────
            // Vault retrieved first so the resolver can extract the base_url.
            // Token injection still only occurs AFTER URI validation (see class docblock).
            $payload = $this->vault->retrieveSecret($tenantId, $integration, $userId);
            $resolvedAllowed = $this->uriResolver->resolve($tenantId, $integration, $payload);
            $this->assertUriAllowed($url, $integration, $resolvedAllowed);
        } else {
            // ── No allowlist, no resolver → fail-closed ───────────────────────
            throw UriNotAllowedException::forUri($url, $integration);
        }

        // Retrieve token and inject Authorization header.
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
            $this->uriResolver,
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
     * Assert the target URI starts with one of the given allowed base URI prefixes.
     *
     * Fail-closed: empty list → always throws.
     *
     * @param string[] $allowed
     *
     * @throws UriNotAllowedException
     */
    private function assertUriAllowed(string $url, string $integration, array $allowed): void
    {
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
