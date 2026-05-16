# Vault Integration: Static API Token

This document covers the static credential path — the simplest integration type where a plugin
uses a single, long-lived API token per tenant.

Reference implementation: [`mosyca/plugin-demo`](https://github.com/mosyca/plugin-demo)
(uses `demo_api` integration type, `https://httpbin.org/bearer` as the target API)

For the OAuth 2.0 client_credentials path (M2M, tokens that expire and refresh), see [06_vault_oauth_client_credentials.md](06_vault_oauth_client_credentials.md).
For the OAuth 2.0 Authorization Code path (user redirect), see [05_oauth_flow.md](05_oauth_flow.md).

---

## How the Vault Works

The Vault stores encrypted per-tenant credentials. The encryption key (`MOSYCA_VAULT_MASTER_KEY`)
never leaves the server. Credentials are stored as JSON blobs encrypted with libsodium `secretbox`
(XSalsa20-Poly1305, random nonce per write).

```
Operator                      Mosyca Core                         Vault (DB)
   │                               │                                   │
   │  mosyca:vault:set demo_api t1 │                                   │
   │──────────────────────────────>│                                   │
   │  {"token": "sk-abc123"}       │  encrypt(json, masterKey)         │
   │                               │──────────────────────────────────>│
   │                               │                                   │ VaultSecret row
   │                               │                                   │ (encrypted blob)
```

---

## VaultManager API

```php
// Store credentials (called by SetVaultSecretCommand or OAuthCallbackHandler)
$vault->storeSecret(
    tenantId: 'tenant-1',
    integrationType: 'demo_api',    // must match VaultAwareHttpClient allowedUris key
    payload: ['token' => 'sk-abc123'],
    userId: null,                    // null = tenant-level (M2M); set for user-scoped tokens
    expiresAt: null,                 // null = never expires; set for OAuth tokens
);

// Retrieve credentials (called by VaultAwareHttpClient internally)
$payload = $vault->retrieveSecret('tenant-1', 'demo_api');
// $payload = ['token' => 'sk-abc123']
// throws SecretNotFoundException if no active (non-expired) secret exists
```

---

## The Pre-Flight Check Pattern

Actions that need authentication must check for credentials before delegating to the HTTP client.
This produces a clean `AUTH_REQUIRED` signal instead of an unhandled exception.

```php
public function execute(array $args, ExecutionContextInterface $context): ActionResult
{
    // Pre-flight: check credentials exist
    try {
        $this->vault->retrieveSecret($context->getTenantId(), 'demo_api');
    } catch (SecretNotFoundException) {
        // Vault Rule V2: authRequired() exposes ONLY the integration type.
        // No tenant ID, user ID, or error details in the response.
        return ActionResult::authRequired('demo_api');
    }

    // Proceed: VaultAwareHttpClient resolves the token internally
    $data = $this->client->fetchProtected($context->getTenantId(), $context->getUserId());

    return ActionResult::ok($data, 'Success.');
}
```

**What happens when `AUTH_REQUIRED` is returned:**

1. `McpRpcController` detects `errorCode: 'AUTH_REQUIRED'`
2. `ProvisioningLinkGenerator` creates a signed, time-limited provisioning URL
3. The URL is injected into `correctionHint` in the JSON-RPC error response
4. Claude receives: _"Use this link to store credentials for 'demo_api', then retry"_
5. The operator visits the URL → `ProvisionController` stores the credentials
6. The next tool call finds the credentials → returns `ok`

---

## VaultAwareHttpClient: the $allowedUris Contract

`VaultAwareHttpClient` implements Vault Rule V5: **URI allowlist enforced before any vault access**.

```php
// In services.yaml — the canonical per-connector pattern:
Mosyca\Core\Vault\Http\VaultAwareHttpClient $demoClient:
    class: Mosyca\Core\Vault\Http\VaultAwareHttpClient
    arguments:
        $inner: '@http_client'
        $vault: '@Mosyca\Core\Vault\VaultManager'
        $allowedUris:
            demo_api:
                - 'https://httpbin.org/'
        $refreshers: !tagged_iterator mosyca.vault.refresher
```

**Type:** `array<string, string[]>` — keyed by integration type, value is a list of allowed base URIs.

**Validation:** The client calls `str_starts_with($url, $allowedBaseUri)` for each URI in the list.
If none match, `UriNotAllowedException` is thrown — **before** the vault is accessed.

**Examples:**

| Integration | allowedUris | Allowed | Blocked |
|---|---|---|---|
| demo_api | `['https://httpbin.org/']` | `https://httpbin.org/bearer` | `https://evil.com/steal` |
| shopware6 | `['https://shop.acme.de/api/']` | `https://shop.acme.de/api/order` | `https://shop.other.de/` |

**Never use `['https://']` as a wildcard** — that allows token injection into any HTTPS server.

---

## How the Bearer Token is Extracted

`VaultAwareHttpClient` looks for the token under two keys, in order:

1. `access_token` (OAuth 2.0 standard)
2. `token` (generic fallback)

Store credentials accordingly:

```bash
# Static API key (stores as 'token' key):
bin/console mosyca:vault:set demo_api tenant-1
> {"token": "my-api-key-here"}

# OAuth access token (stores as 'access_token' key):
# Done automatically by OAuthCallbackHandlerInterface implementations
```

---

## Credential Provisioning Flow (CLI)

```bash
# Interactive — prompts for JSON on stdin (shell-history safe)
bin/console mosyca:vault:set {integration_type} {tenant_id}

# Examples:
bin/console mosyca:vault:set demo_api default
bin/console mosyca:vault:set demo_api tenant-acme
bin/console mosyca:vault:set shopware6 shop-berlin
```

The `mosyca:vault:set` command reads the JSON payload from stdin to avoid the token appearing
in shell history or process lists. It calls `VaultManager::storeSecret()` after parsing.

---

## Vault Rules Reference

| Rule | Requirement |
|---|---|
| V1 | All encryption/decryption via `SecretCipherInterface`. Never call `sodium_crypto_*` in business logic. |
| V2 | Secrets NEVER in `ActionResult`, JSON-RPC body, logs, or exception messages. |
| V3 | Unit tests use `DummySecretCipher` or a generated test key. NEVER `MOSYCA_VAULT_MASTER_KEY`. |
| V4 | Mandatory QA + Security review before adding Vault-touching code. |
| V5 | `VaultAwareHttpClient` validates URI against allowlist BEFORE any vault access. Deny-by-default. |

---

→ Next: [03_testing.md](03_testing.md)
