<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Http;

/**
 * Resolves the allowed base URI list for a tenant + integration dynamically.
 *
 * Implement this interface when the integration's base URL varies per tenant
 * and cannot be hardcoded in the static `$allowedUris` map passed to
 * {@see VaultAwareHttpClient}. A typical example is a multi-tenant Shopware 6
 * setup where each shop has its own domain
 * (e.g. `https://tenant-a.example.com`, `https://tenant-b.example.com`).
 *
 * ── When this is called ──────────────────────────────────────────────────────
 * `VaultAwareHttpClient` invokes `resolve()` only when the static
 * `$allowedUris[$integration]` list is **empty**. If the static list is
 * non-empty, it is used as-is and this resolver is never consulted.
 *
 * ── Rule V5 compliance ───────────────────────────────────────────────────────
 * Rule V5 requires that the target URI is validated against an operator-trusted
 * allowlist **before** the Authorization header is injected. When this resolver
 * is used, the vault payload is retrieved first (to supply the base_url), but
 * token injection still only occurs after URI validation. The base_url value
 * is operator-provisioned (vault-stored) and cannot be influenced by LLM input,
 * so the security guarantee is maintained.
 *
 * ── Constraints on implementations ──────────────────────────────────────────
 * Implementations MUST:
 * - Use ONLY non-secret, configuration-class payload keys (e.g. `base_url`).
 * - NEVER use `access_token`, `client_id`, or `client_secret` for URI resolution.
 * - Return an empty array if the payload contains no usable base URL.
 *   An empty return causes `UriNotAllowedException` (fail-closed behaviour).
 * - Be registered as a service; `VaultAwareHttpClient` receives it via constructor
 *   injection (see connector's `services.yaml`).
 *
 * ── Example implementation ───────────────────────────────────────────────────
 * <code>
 * final class ShopwareUriResolver implements VaultUriResolverInterface
 * {
 *     public function resolve(string $tenantId, string $integrationType, array $payload): array
 *     {
 *         if (!isset($payload['base_url']) || !\is_string($payload['base_url'])) {
 *             return [];
 *         }
 *         return [rtrim($payload['base_url'], '/').'/'];
 *     }
 * }
 * </code>
 *
 * @see VaultAwareHttpClient
 */
interface VaultUriResolverInterface
{
    /**
     * Return the list of allowed base URI prefixes for this tenant + integration.
     *
     * Returning an empty array causes `UriNotAllowedException` to be thrown
     * by {@see VaultAwareHttpClient} — fail-closed, same as an empty static list.
     *
     * @param array<string, mixed> $payload Decrypted vault payload
     *
     * @return string[] Allowed base URI prefixes
     */
    public function resolve(string $tenantId, string $integrationType, array $payload): array;
}
