# Vault Integration: OAuth 2.0 Client Credentials (M2M)

This document covers the **machine-to-machine (M2M) credential path** for integrations that use
OAuth 2.0 `client_credentials` grant — where there is no user redirect and no refresh token. The
server itself authenticates against the external API using a `client_id` + `client_secret` pair and
receives a short-lived `access_token`.

Reference implementation: [`mosyca/plugin-shopware6-demo`](https://github.com/mosyca/plugin-shopware6-demo)
(Shopware 6 Admin API, `shopware6` integration type)

For the static API key path, see [02_vault_static_token.md](02_vault_static_token.md).
For the OAuth 2.0 Authorization Code (user redirect) path, see [05_oauth_flow.md](05_oauth_flow.md).

---

## How It Differs from the Static Token Path

| Aspect | Static token | Client credentials |
|---|---|---|
| Vault payload | `{"token": "sk-abc123"}` | `{"base_url":"…","client_id":"…","client_secret":"…"}` |
| Access token in vault? | Always present from provisioning | Added by `TokenRefresherInterface` on first use |
| Token lifetime | Indefinite | Short-lived (`expires_in`, typically 600–3600 s) |
| URI allowlist | Static (DI compile-time) | **Dynamic** — derived from `base_url` in the vault payload |
| Refresh | Never needed | On HTTP 401 AND eagerly on first action call |

---

## Credential Payload Structure

**Provisioned by the operator** (`mosyca:vault:set shopware6 {tenant}` — reads JSON from stdin):

```json
{
    "base_url":      "https://my-shop.example.com",
    "client_id":     "SWUA...",
    "client_secret": "..."
}
```

**After first token exchange** (written by `ShopwareTokenRefresher`, in-place upsert):

```json
{
    "base_url":      "https://my-shop.example.com",
    "client_id":     "SWUA...",
    "client_secret": "...",
    "access_token":  "<short-lived token>",
    "expires_at":    "2025-06-01T12:00:00+00:00"
}
```

The original keys are **always preserved**. `storeSecret` merges the new token on top using
`array_merge($existing, ['access_token' => …, 'expires_at' => …])`.

---

## The Dynamic URI Challenge

`VaultAwareHttpClient.$allowedUris` is a compile-time static DI map. For multi-tenant connectors,
each tenant has a different shop domain — there is no single URI to hardcode.

**Core V0.15** introduced `VaultUriResolverInterface` to solve this:

```
Static path (allowedUris non-empty)    →  URI check BEFORE vault access (unchanged)
Dynamic path (allowedUris empty        →  vault accessed first to retrieve base_url,
             + resolver present)           resolver derives allowed prefix,
                                           URI validated against derived prefix,
                                           then Bearer token injected

No allowedUris AND no resolver         →  UriNotAllowedException (fail-closed)
```

**Vault Rule V5 compliance:** V5 mandates URI validation *before Bearer token injection*, not
before vault access. In the dynamic path, the vault is accessed to retrieve `base_url` — an
operator-provisioned configuration value that an LLM cannot influence. Token injection still only
occurs after the URI has been validated against the resolver's output.

---

## VaultUriResolverInterface

```php
// core/src/Vault/Http/VaultUriResolverInterface.php
interface VaultUriResolverInterface
{
    /**
     * Return allowed base URI prefixes for this tenant + integration.
     *
     * Security Rule SR3: MUST only read non-secret, config-class keys.
     * MUST NOT use 'access_token', 'client_id', or 'client_secret'.
     *
     * @param  array<string, mixed> $payload  Decrypted vault payload
     * @return string[]                        Allowed URI prefixes (same format as $allowedUris values)
     */
    public function resolve(string $tenantId, string $integrationType, array $payload): array;
}
```

### Reference Implementation: `ShopwareUriResolver`

```php
class ShopwareUriResolver implements VaultUriResolverInterface
{
    public function resolve(string $tenantId, string $integrationType, array $payload): array
    {
        // SR3: only access 'base_url' — never access_token, client_id, client_secret.
        if (!isset($payload['base_url']) || !\is_string($payload['base_url']) || '' === $payload['base_url']) {
            return []; // fail-closed: no base_url → no allowed URIs → UriNotAllowedException
        }

        // Normalize: ensure trailing slash so str_starts_with() can't be tricked by
        // a URL like 'https://my-shop.example.com.evil.com/'.
        return [rtrim($payload['base_url'], '/').'/'];
    }
}
```

**SR3 Compliance:**
- Only reads `payload['base_url']`
- Never reads `access_token`, `client_id`, or `client_secret`
- Returns empty list on any invalid or missing base_url (fail-closed — `UriNotAllowedException` thrown by client)

---

## services.yaml Wiring

```yaml
# src/config/services.yaml

# Named service binding: Symfony matches both the type AND the variable name
# ($shopware6Client) when injecting into ShopwareAdminClient's constructor.
Mosyca\Core\Vault\Http\VaultAwareHttpClient $shopware6Client:
    class: Mosyca\Core\Vault\Http\VaultAwareHttpClient
    arguments:
        $inner:       '@http_client'
        $vault:       '@Mosyca\Core\Vault\VaultManager'
        $allowedUris: {}          # empty → dynamic resolver path is used
        $uriResolver: '@Mosyca\Shopware6Demo\Vault\ShopwareUriResolver'
        $refreshers:  !tagged_iterator mosyca.vault.refresher

# ShopwareTokenRefresher is auto-tagged 'mosyca.vault.refresher' via autoconfigure: true
# ShopwareUriResolver is discovered via PSR-4 and registered automatically
```

---

## ShopwareTokenRefresher: Reference Implementation

The refresher exchanges `client_id` + `client_secret` for a fresh `access_token` and writes it
back to the vault. It is called in two scenarios:

1. **Eagerly** by the action's pre-flight check when no `access_token` exists in the payload yet
   (first run after operator provisioning).
2. **On-demand** by `VaultAwareHttpClient` when the Shopware API returns HTTP 401.

```php
class ShopwareTokenRefresher implements TokenRefresherInterface
{
    // SR4: inject a PLAIN HttpClientInterface — NOT VaultAwareHttpClient.
    // The token endpoint authenticates via client_id/secret in the POST body, not Bearer.
    // Using VaultAwareHttpClient here would create a circular DI dependency.
    public function __construct(
        private readonly HttpClientInterface $httpClient,  // plain, not VaultAwareHttpClient
        private readonly VaultManager $vault,
    ) {}

    public function supports(string $integrationType): bool
    {
        return 'shopware6' === $integrationType;
    }

    public function refresh(string $tenantId, ?string $userId): void
    {
        // Retrieve current credentials from the vault.
        $payload   = $this->vault->retrieveSecret($tenantId, 'shopware6', $userId);
        $baseUrl   = $payload['base_url'];    // operator-provisioned
        $clientId  = $payload['client_id'];
        $clientSecret = $payload['client_secret'];

        // POST /api/oauth/token — OAuth 2.0 client_credentials grant.
        // base_url is operator-provisioned (from the Vault) — not user-supplied.
        $response = $this->httpClient->request('POST', rtrim($baseUrl, '/').'/api/oauth/token', [
            'json' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ],
        ]);

        // SR5: exception message MUST NOT include client_secret or access_token values.
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf(
                'Shopware 6 token exchange failed for "%s" (HTTP %d). Verify credentials in the Vault.',
                $tenantId, $response->getStatusCode()
            ));
        }

        $tokenData   = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $accessToken = $tokenData['access_token'];
        $expiresIn   = $tokenData['expires_in'] ?? 600;

        // Write back — upsert: preserves base_url, client_id, client_secret.
        // 60-second clock-skew buffer applied to expires_in.
        $expiresAt = (new \DateTimeImmutable())->modify('+'.($expiresIn - 60).' seconds');

        $this->vault->storeSecret($tenantId, 'shopware6', array_merge($payload, [
            'access_token' => $accessToken,
            'expires_at'   => $expiresAt->format(\DateTimeInterface::ATOM),
        ]), $userId);

        // SR5: $payload now contains access_token — never log it or include it in exceptions.
    }
}
```

### Security Rules Applied

| Rule | Requirement | Applied where |
|---|---|---|
| SR3 | `VaultUriResolverInterface` implementations MUST only read non-secret config keys (`base_url`). Never `access_token`, `client_id`, `client_secret`. | `ShopwareUriResolver::resolve()` |
| SR4 | `ShopwareTokenRefresher` MUST use a plain `HttpClientInterface`, NOT `VaultAwareHttpClient`, for the token endpoint POST. | Constructor `$httpClient` |
| SR5 | Exception messages in the refresher MUST NOT include `client_secret` or `access_token` values. Expose only the HTTP status code. | `refresh()` error branch |

---

## The Three-Step OAuth Pre-Flight Pattern

Every action that uses this credential type must implement the following pre-flight sequence before
delegating to the HTTP client:

```php
public function execute(array $args, ExecutionContextInterface $context): ActionResult
{
    $tenantId = $context->getTenantId();
    $userId   = $context->getUserId();

    // ── Step 1: check that credentials have been provisioned ────────────────
    try {
        $payload = $this->vault->retrieveSecret($tenantId, 'shopware6', $userId);
    } catch (SecretNotFoundException) {
        // Operator has not provisioned credentials yet.
        // Vault Rule V2: authRequired() exposes only the integration type.
        return ActionResult::authRequired('shopware6');
    }

    // ── Step 2: eager token exchange on first use ────────────────────────────
    // The operator provisions only client_id + client_secret (no access_token).
    // On the very first action call, exchange the credentials for a token.
    if (!isset($payload['access_token']) || !\is_string($payload['access_token'])) {
        $this->tokenRefresher->refresh($tenantId, $userId);

        // Re-retrieve after refresh — the refresher wrote the new access_token.
        try {
            $payload = $this->vault->retrieveSecret($tenantId, 'shopware6', $userId);
        } catch (SecretNotFoundException) {
            return ActionResult::authRequired('shopware6');
        }
    }

    // ── Step 3: extract base_url and delegate ───────────────────────────────
    // Pass base_url explicitly — avoids a second vault call inside the client.
    $baseUrl = \is_string($payload['base_url'] ?? null) ? (string) $payload['base_url'] : '';

    if ('' === $baseUrl) {
        return ActionResult::failure('Vault payload is missing base_url.', 'CONFIG_ERROR');
    }

    // VaultAwareHttpClient resolves the URI dynamically via ShopwareUriResolver
    // and injects the Bearer token from payload['access_token'] automatically.
    $data = $this->client->getVersion($tenantId, $baseUrl);

    // SR2: strip credential keys before returning — even if the API echoes them back.
    unset($data['access_token'], $data['client_id'], $data['client_secret'], $data['expires_at']);

    return ActionResult::ok($data, 'Shopware 6 version retrieved.');
}
```

**Why this order matters:**
- Step 1 catches the no-credentials case cleanly before any HTTP call is attempted.
- Step 2 resolves the chicken-and-egg problem: `VaultAwareHttpClient` expects an `access_token`
  in the vault before injecting the Bearer header, but the token only exists after the first
  exchange. The action drives the first exchange; subsequent calls (and 401 retries) are
  handled by `VaultAwareHttpClient` automatically.
- Step 3 passes `base_url` explicitly to avoid a second vault round-trip inside the client.

---

## The Full Token Lifecycle

```
Operator provisions:
  vault.storeSecret('shopware6', {base_url, client_id, client_secret})
       │
       ▼
First action call:
  execute() → vault.retrieveSecret() → payload has no access_token
            → tokenRefresher.refresh() → POST /api/oauth/token → 200
            → vault.storeSecret(…, {…, access_token, expires_at})
            → vault.retrieveSecret() → payload now has access_token
            → VaultAwareHttpClient injects Bearer header
            → GET /api/_info/version → 200
       │
       ▼
Subsequent calls (token valid):
  execute() → vault.retrieveSecret() → access_token present
            → VaultAwareHttpClient injects Bearer header
            → API call → 200
       │
       ▼
Token expiry / rejection (HTTP 401):
  VaultAwareHttpClient receives 401
  → findRefresher('shopware6') → ShopwareTokenRefresher
  → tokenRefresher.refresh() → POST /api/oauth/token → 200
  → vault.storeSecret(…, {…, new access_token, new expires_at})
  → VaultAwareHttpClient retries original request with new token
  → 200 returned to action
```

---

## Testing Checklist

Every plugin using this pattern must include the following test coverage:

### TokenRefresher (unit)
- `testSupportsShopware6()` — verifies `supports('shopware6')` returns `true`
- `testSendsCorrectTokenExchangeRequest()` — verifies POST method, URL, and body shape
- `testStoresAccessTokenAndExpiresAtInVault()` — verifies `storeSecret` called with correct keys
- `testOriginalCredentialKeysPreservedAfterRefresh()` — verifies upsert (base_url etc. intact)
- `testThrowsOnHttpErrorWithoutCredentialValues()` — SR5: message contains HTTP status, not secret
- `testThrowsWhenAccessTokenMissingFromResponse()` — handles malformed token response

### Action (unit)
- `testReturnsAuthRequiredWhenNoCredentials()` — `SecretNotFoundException` → `AUTH_REQUIRED`
- `testEagerRefreshCalledWhenNoAccessToken()` — Step 2 calls refresher, re-retrieves payload
- `testDefaultParametersUsedWhenArgsEmpty()` — parameter defaults applied correctly
- `testResultDataNeverContainsCredentialKeys()` — **SR2 mandatory**: assert no `access_token`, `client_id`, `client_secret`, `expires_at` in `ActionResult::data`

### Client (unit)
- `testGetVersionInjectsAuthHeaderAndDecodesResponse()` — Bearer token present in request
- `testOutOfScopeUriIsRejected()` — **SR1 mandatory**: `https://evil.example.com` → `UriNotAllowedException`
- `testUriFromDifferentShopIsRejected()` — different-tenant domain also blocked

### Integration (full chain)
- `testFullChainFromProvisioningToSuccessfulApiCall()` — real `SodiumSecretCipher`, mocked repo,
  mocked HTTP; verifies end-to-end: provision → eager refresh → API call → token retrievable from vault
- `testReturnsAuthRequiredWhenVaultIsEmpty()` — no credentials → `AUTH_REQUIRED`
- `testVaultAwareHttpClientCallsRefresherOn401()` — 401 response → refresh → retry (2 HTTP calls)

**Vault Rule V3:** Integration tests MUST use a randomly generated test key, never `MOSYCA_VAULT_MASTER_KEY`:

```php
$testKey = bin2hex(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$cipher  = new SodiumSecretCipher($testKey);
```

**Reference capture pattern** in integration tests — use `function() use (&$stored)`, NOT
`fn() => $stored`. Arrow functions capture by value at definition time; a subsequent `storeSecret()`
call would not be visible to the `findByContext()` callback.

```php
// ✅ Correct — reference capture, sees later writes
$this->repository->method('findByContext')
    ->willReturnCallback(static function () use (&$stored): ?VaultSecret {
        return $stored;
    });

// ❌ Wrong — value capture, always returns the original null
$this->repository->method('findByContext')
    ->willReturnCallback(fn() => $stored);
```

---

→ Back to [05_oauth_flow.md](05_oauth_flow.md) (Authorization Code flow — user redirect)
→ Back to [00_overview.md](00_overview.md)
