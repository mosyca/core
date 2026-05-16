# Quickstart: Build the Demo Plugin

This guide walks through every file in `mosyca/plugin-demo` in reading order. By the end you will
have a complete working plugin that calls `https://httpbin.org` — one public endpoint (no auth) and
one protected endpoint (Vault-backed Bearer token).

All code shown here is the actual, verified source from `plugin-demo/`. Run `composer require mosyca/plugin-demo`
to install it, or read the source at `https://github.com/mosyca/plugin-demo`.

---

## Step 1: composer.json

```json
{
    "name": "mosyca/plugin-demo",
    "type": "library",
    "require": {
        "php": ">=8.2",
        "mosyca/core": "^0.14"
    },
    "autoload": {
        "psr-4": { "Mosyca\\Demo\\": "src/" }
    }
}
```

**Key points:**
- `type: library` — not a Symfony application. No `config/`, no `public/`.
- Requires only `mosyca/core`. Symfony components come in transitively.
- The PSR-4 namespace `Mosyca\Demo\` maps to `src/`.

---

## Step 2: Bundle class (`src/DemoPlugin.php`)

```php
final class DemoPlugin extends Bundle
{
    public function getContainerExtension(): Extension
    {
        return new DemoExtension();
    }
}
```

**Why override `getContainerExtension()`?**
Symfony's default extension discovery strips a `Bundle` suffix from the class name. Since our class
is `DemoPlugin` (not `DemoBundle`), we return the extension explicitly. Connectors using the
conventional `FooBundle` naming can skip this override entirely.

Register in the host application's `config/bundles.php`:

```php
return [
    Mosyca\Core\MosycaCoreBundle::class => ['all' => true],
    Mosyca\Demo\DemoPlugin::class       => ['all' => true],
];
```

---

## Step 3: Extension (`src/DependencyInjection/DemoExtension.php`)

```php
final class DemoExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../config'));
        $loader->load('services.yaml');
    }
}
```

The extension has one job: load `services.yaml`. Nothing else belongs here unless you need
user-configurable parameters from the host application's `config/packages/your-plugin.yaml`.

---

## Step 4: Resource (`src/Resource/HttpBinResource.php`)

```php
final class HttpBinResource extends AbstractResource
{
    public function getPluginNamespace(): string { return 'demo'; }
    public function getName(): string           { return 'httpbin'; }

    public function getOperations(): array
    {
        return [
            'public' => ['action' => FetchPublicAction::class, 'method' => 'GET', 'path' => '/public'],
            'fetch'  => ['action' => FetchProtectedAction::class, 'method' => 'GET', 'path' => '/fetch'],
        ];
    }
}
```

The Resource maps operations to Actions. It generates the following routes automatically:

| Operation | REST | MCP tool | CLI |
|---|---|---|---|
| `public` | `GET /api/v1/demo/{tenant}/httpbin/public` | `demo_httpbin_public` | `demo:httpbin:public` |
| `fetch`  | `GET /api/v1/demo/{tenant}/httpbin/fetch`  | `demo_httpbin_fetch`  | `demo:httpbin:fetch`  |

---

## Step 5: Act 1 — FetchPublicAction (no auth)

Read this action first. It is the minimal template for any action.

```php
#[AsAction]
final class FetchPublicAction implements ActionInterface
{
    use ActionTrait; // provides defaults for optional methods

    public function __construct(private readonly HttpBinClient $client) {}

    public function getName(): string        { return 'demo:httpbin:public'; }
    public function getDescription(): string { return 'Fetches a public JSON sample from httpbin.org.'; }
    public function getUsage(): string       { return '...'; } // Markdown — Claude reads this
    public function getParameters(): array   { return []; }    // no input required
    public function isMutating(): bool       { return false; }

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        $data = $this->client->fetchPublic();
        return ActionResult::ok($data, 'Public data fetched from httpbin.org/json.');
    }
}
```

**Required methods** (no defaults):
`getName()`, `getDescription()`, `getUsage()`, `getParameters()`, `isMutating()`, `execute()`

**Methods provided by `ActionTrait`** (override only when needed):
`getRequiredScopes()`, `getTags()`, `getDefaultFormat()`, `getDefaultTemplate()`

**Always use `ActionTrait`** — it keeps your action forward-compatible with new optional capability
interfaces in future Mosyca releases.

---

## Step 6: Act 2 — FetchProtectedAction (Vault auth)

This action demonstrates the three-phase credential pattern. Compare it to `FetchPublicAction` —
the only real differences are the two constructor arguments and the `execute()` body.

```php
#[AsAction]
final class FetchProtectedAction implements ActionInterface
{
    use ActionTrait;

    public function __construct(
        private readonly HttpBinClient $client,
        private readonly VaultManager $vault,   // added for Phase 1 pre-flight check
    ) {}

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        // ── Phase 1: Pre-flight credential check ─────────────────────────────
        // Catch SecretNotFoundException before making any HTTP call.
        // Return authRequired() — never propagate the exception.
        try {
            $this->vault->retrieveSecret($context->getTenantId(), 'demo_api');
        } catch (SecretNotFoundException) {
            return ActionResult::authRequired('demo_api');
        }

        // ── Phase 2: Delegate to the HTTP client ──────────────────────────────
        // HttpBinClient passes extra.vault options to VaultAwareHttpClient,
        // which injects the Authorization header automatically.
        $data = $this->client->fetchProtected($context->getTenantId(), $context->getUserId());

        // ── Phase 3: Strip credential reflections ─────────────────────────────
        // httpbin.org/bearer echoes the Bearer token in its response.
        // Remove it — ActionResult data must never contain credential values.
        unset($data['token']); // Vault Rule V2 + Security Rule SR2

        return ActionResult::ok($data, 'Protected resource fetched from httpbin.org/bearer.');
    }
}
```

**When `authRequired()` is returned:**
- `errorCode` is `'AUTH_REQUIRED'`
- The MCP bridge detects it and injects a signed provisioning URL into `correctionHint`
- The operator visits the URL and stores credentials out-of-band
- The next call finds the credentials and proceeds normally

---

## Step 7: HttpBinClient (`src/Client/HttpBinClient.php`)

```php
class HttpBinClient
{
    private const BASE_URL = 'https://httpbin.org';

    // $demoClient — name is load-bearing (see services.yaml)
    public function __construct(private readonly VaultAwareHttpClient $demoClient) {}

    public function fetchPublic(): array
    {
        // No extra.vault → pure passthrough, no vault access
        $response = $this->demoClient->request('GET', self::BASE_URL.'/json');
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function fetchProtected(string $tenantId, ?string $userId): array
    {
        $response = $this->demoClient->request('GET', self::BASE_URL.'/bearer', [
            'extra' => [
                'vault' => [
                    'integration' => 'demo_api', // must match allowedUris key + storeSecret type
                    'tenant_id'   => $tenantId,
                    'user_id'     => $userId,    // null for tenant-level M2M
                ],
            ],
        ]);
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
```

**VaultAwareHttpClient request lifecycle** (when `extra.vault` is present):
1. Validate URI against `allowedUris['demo_api']` — throws `UriNotAllowedException` if not listed
2. Call `VaultManager::retrieveSecret($tenantId, 'demo_api', $userId)` — decrypt the token
3. Inject `Authorization: Bearer <token>` into the request headers
4. Execute the request
5. On HTTP 401: call `TokenRefresherInterface::refresh()` if registered, then retry once

---

## Step 8: services.yaml (`src/config/services.yaml`)

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true   # enables auto-tagging via MosycaCoreBundle

    # Auto-register all src/ classes as services
    Mosyca\Demo\:
        resource: '../src/'
        exclude:
            - '../src/DemoPlugin.php'
            - '../src/DependencyInjection/'

    # Named autowiring alias — the canonical connector pattern for VaultAwareHttpClient
    # Variable name $demoClient must match HttpBinClient's constructor parameter name.
    Mosyca\Core\Vault\Http\VaultAwareHttpClient $demoClient:
        class: Mosyca\Core\Vault\Http\VaultAwareHttpClient
        arguments:
            $inner: '@http_client'
            $vault: '@Mosyca\Core\Vault\VaultManager'
            $allowedUris:
                demo_api:
                    - 'https://httpbin.org/'   # Vault Rule V5: minimum required URIs only
            $refreshers: !tagged_iterator mosyca.vault.refresher
```

**The named autowiring alias** is how connectors get a correctly-scoped `VaultAwareHttpClient`
without modifying any Core service. The pattern:

1. Define a service with key `FullyQualifiedClass $variableName`
2. Set `$allowedUris` to the minimum required base URIs for your integration
3. Declare `HttpBinClient(VaultAwareHttpClient $demoClient)` — variable name must match the key

Symfony resolves the dependency by matching type + variable name. If the name doesn't match,
it falls back to the Core's default (allowedUris: [] — rejects all URIs).

---

## Provision credentials and call the action

```bash
# Store credentials for the 'demo_api' integration, tenant 'default'
bin/console mosyca:vault:set demo_api default
# Prompts for JSON payload on stdin (shell-history safe):
# {"token": "your-bearer-token-here"}

# Test via CLI
bin/console demo:httpbin:fetch

# Test via MCP (after mosyca:vault:set)
# Claude tool call: demo_httpbin_fetch {}
```

---

→ Continue to [02_vault_static_token.md](02_vault_static_token.md) for the deep-dive on credential storage.
→ Continue to [03_testing.md](03_testing.md) for test patterns.
→ Continue to [04_dx_checklist.md](04_dx_checklist.md) to verify your plugin is complete.
