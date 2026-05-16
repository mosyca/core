# Vault Integration: OAuth 2.0 Flows

Mosyca supports two distinct OAuth 2.0 patterns. Choose the one that matches your target API.

---

## Pattern A — Client Credentials (M2M) ✅ Available

**When to use:** Server-to-server integrations where no user is involved. The client authenticates
directly with `client_id` + `client_secret` and receives a short-lived `access_token`.

**Examples:** Shopware 6 Admin API, payment processors, B2B data feeds.

**Full documentation:** [06_vault_oauth_client_credentials.md](06_vault_oauth_client_credentials.md)

**Reference implementation:** [`mosyca/plugin-shopware6-demo`](https://github.com/mosyca/plugin-shopware6-demo)

Key concepts covered in that doc:
- Dynamic URI allowlist via `VaultUriResolverInterface` (Core V0.15)
- `ShopwareTokenRefresher` reference implementation (`TokenRefresherInterface`)
- The three-step pre-flight pattern (credential check → eager exchange → delegate)
- Security rules SR3, SR4, SR5

---

## Pattern B — Authorization Code (User Redirect) 🔜 Pending Phase 2

**When to use:** User-scoped integrations where the API owner must grant access via a browser
redirect. The authorization server redirects back with a `code` that the server exchanges for
`access_token` + `refresh_token`.

**Examples:** Spotify, Shopify (OAuth app), Google APIs.

> **Status: Pending Phase 2**
>
> This section will be written after `mosyca/plugin-shopify-demo` is complete and verified.
> The Shopify demo will implement the full Authorization Code flow via
> `OAuthCallbackHandlerInterface` and `TokenRefresherInterface`.

### What Will Be Covered

- Implementing `OAuthCallbackHandlerInterface` (exchange auth code → access token → storeSecret)
- Implementing `TokenRefresherInterface` (refresh expired tokens via the integration's refresh endpoint)
- Generating the OAuth authorization URL from an action
- Security requirement: pinning `redirect_uri` server-side (never from HTTP request parameters)
- Security requirement: delegating `state` verification to `OAuthStateEncoder` (already done
  by `OAuthCallbackController` before `handleCallback()` is called — do not re-verify)
- Testing the OAuth callback with `MockHttpClient` and a real `OAuthStateEncoder`

### Interface Signatures (Reference)

```php
// Implement this when your plugin uses OAuth 2.0 Authorization Code flow
interface OAuthCallbackHandlerInterface
{
    public function supports(string $integration): bool;

    // Called by OAuthCallbackController AFTER state verification.
    // The $code is a pre-verified OAuth authorization code.
    // Call VaultManager::storeSecret() with the exchanged token.
    // Vault Rule V2: never log the code or the resulting token.
    public function handleCallback(string $code, string $tenantId, ?string $userId): void;
}

// Implement this when your integration's access tokens expire
interface TokenRefresherInterface
{
    public function supports(string $integrationType): bool;

    // Called by VaultAwareHttpClient on HTTP 401.
    // After return: VaultManager::retrieveSecret() must return valid, non-expired credentials.
    public function refresh(string $tenantId, ?string $userId): void;
}
```

See the Phase 2 roadmap entry for `mosyca/plugin-shopify-demo`.
