<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Http;

use Mosyca\Core\Vault\Exception\UriNotAllowedException;
use Mosyca\Core\Vault\Http\VaultAwareHttpClient;
use Mosyca\Core\Vault\Http\VaultUriResolverInterface;
use Mosyca\Core\Vault\Refresh\TokenRefresherInterface;
use Mosyca\Core\Vault\VaultManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @covers \Mosyca\Core\Vault\Http\VaultAwareHttpClient
 *
 * Uses Symfony MockHttpClient for fully synchronous, deterministic HTTP simulation
 * (QA Rule 2: Deterministic Isolation, QA Protocol §3.3: HTTP Lifecycle Mocking).
 * Uses createMock(VaultManager::class) + createMock(TokenRefresherInterface::class) for service isolation.
 */
final class VaultAwareHttpClientTest extends TestCase
{
    private const ALLOWED_URIS = [
        'shopware6' => ['https://api.shopware.example.com'],
        'spotify' => ['https://api.spotify.com'],
    ];

    /** @var VaultManager&MockObject */
    private VaultManager $vault;

    protected function setUp(): void
    {
        $this->vault = $this->createMock(VaultManager::class);
    }

    /**
     * @param array<string, string[]>|null $allowedUris
     */
    private function makeClient(
        MockHttpClient $inner,
        ?TokenRefresherInterface $refresher = null,
        ?array $allowedUris = null,
        ?VaultUriResolverInterface $uriResolver = null,
    ): VaultAwareHttpClient {
        return new VaultAwareHttpClient(
            $inner,
            $this->vault,
            $allowedUris ?? self::ALLOWED_URIS,
            null !== $refresher ? [$refresher] : [],
            $uriResolver,
        );
    }

    // ── passthrough (no vault context) ────────────────────────────────────────

    public function testPassthroughWhenNoVaultContext(): void
    {
        $inner = new MockHttpClient(new MockResponse('{"data":"ok"}', ['http_code' => 200]));
        $client = $this->makeClient($inner);

        $this->vault->expects(self::never())->method('retrieveSecret');

        $response = $client->request('GET', 'https://some.uri/path');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $inner->getRequestsCount());
    }

    public function testPassthroughWhenExtraIsNotArray(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $client = $this->makeClient($inner);

        $this->vault->expects(self::never())->method('retrieveSecret');

        $client->request('GET', 'https://some.uri/path', ['extra' => 'string-not-array']);

        self::assertSame(1, $inner->getRequestsCount());
    }

    public function testPassthroughWhenVaultKeyIsNotArray(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $client = $this->makeClient($inner);

        $this->vault->expects(self::never())->method('retrieveSecret');

        $client->request('GET', 'https://some.uri/path', ['extra' => ['vault' => 'not-an-array']]);

        self::assertSame(1, $inner->getRequestsCount());
    }

    // ── token injection ───────────────────────────────────────────────────────

    public function testInjectsAccessTokenHeader(): void
    {
        $capturedAuth = null;
        $inner = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$capturedAuth): MockResponse {
                $capturedAuth = self::extractAuthHeader($options);

                return new MockResponse('{}', ['http_code' => 200]);
            },
        );

        $this->vault->method('retrieveSecret')->willReturn(['access_token' => 'tok-abc-123']);
        $client = $this->makeClient($inner);

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        self::assertSame('Bearer tok-abc-123', $capturedAuth);
    }

    public function testFallsBackToTokenKeyWhenNoAccessToken(): void
    {
        $capturedAuth = null;
        $inner = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$capturedAuth): MockResponse {
                $capturedAuth = self::extractAuthHeader($options);

                return new MockResponse('{}', ['http_code' => 200]);
            },
        );

        $this->vault->method('retrieveSecret')->willReturn(['token' => 'fallback-token']);
        $client = $this->makeClient($inner);

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        self::assertSame('Bearer fallback-token', $capturedAuth);
    }

    public function testReplacesExistingAuthorizationHeader(): void
    {
        // The decorator must REPLACE a pre-existing Authorization header to prevent bypass attempts.
        $capturedAuth = null;
        $inner = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$capturedAuth): MockResponse {
                $capturedAuth = self::extractAuthHeader($options);

                return new MockResponse('{}', ['http_code' => 200]);
            },
        );

        $this->vault->method('retrieveSecret')->willReturn(['access_token' => 'vault-token']);
        $client = $this->makeClient($inner);

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'headers' => ['Authorization' => 'Bearer attacker-supplied-token'],
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        self::assertSame('Bearer vault-token', $capturedAuth);
    }

    public function testThrowsWhenPayloadHasNoTokenKey(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $this->vault->method('retrieveSecret')->willReturn(['some_other_key' => 'value']);
        $client = $this->makeClient($inner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/access_token.*token/');

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);
    }

    public function testPassesTenantAndUserIdToVaultManager(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

        $this->vault
            ->expects(self::once())
            ->method('retrieveSecret')
            ->with('bluevendsand', 'shopware6', 'user-karim')
            ->willReturn(['access_token' => 'tok']);

        $client = $this->makeClient($inner);

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => [
                'vault' => [
                    'integration' => 'shopware6',
                    'tenant_id' => 'bluevendsand',
                    'user_id' => 'user-karim',
                ],
            ],
        ]);
    }

    public function testNullUserIdPassedForTenantLevelCredentials(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

        $this->vault
            ->expects(self::once())
            ->method('retrieveSecret')
            ->with('tenant-1', 'shopware6', null)
            ->willReturn(['access_token' => 'tok']);

        $client = $this->makeClient($inner);

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);
    }

    // ── URI allowlist enforcement ─────────────────────────────────────────────

    public function testAllowsRequestToAllowedBaseUri(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $this->vault->method('retrieveSecret')->willReturn(['access_token' => 'tok']);
        $client = $this->makeClient($inner);

        // URL with extra path after the allowed base — must be permitted.
        $response = $client->request('GET', 'https://api.shopware.example.com/v1/orders/123', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testThrowsForBlockedUri(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $this->vault->expects(self::never())->method('retrieveSecret');
        $client = $this->makeClient($inner);

        $this->expectException(UriNotAllowedException::class);
        $this->expectExceptionMessageMatches('/shopware6/');

        // An attacker-controlled URL — must be blocked before vault access.
        $client->request('GET', 'https://attacker.example.com/steal-token', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);
    }

    public function testThrowsWhenIntegrationNotInAllowlist(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $this->vault->expects(self::never())->method('retrieveSecret');
        $client = $this->makeClient($inner);

        $this->expectException(UriNotAllowedException::class);

        // 'firebase' is not configured in the allowlist at all → fail-closed.
        $client->request('GET', 'https://firebase.googleapis.com/something', [
            'extra' => ['vault' => ['integration' => 'firebase', 'tenant_id' => 'tenant-1']],
        ]);
    }

    public function testThrowsWhenAllowlistIsEmpty(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $this->vault->expects(self::never())->method('retrieveSecret');

        // Explicit empty allowlist for shopware6 → fail-closed.
        $client = new VaultAwareHttpClient($inner, $this->vault, ['shopware6' => []]);

        $this->expectException(UriNotAllowedException::class);

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);
    }

    public function testVaultIsNotAccessedWhenUriBlocked(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

        // The vault must never be consulted when the URI is blocked (Rule V5).
        $this->vault->expects(self::never())->method('retrieveSecret');
        $client = $this->makeClient($inner);

        try {
            $client->request('GET', 'https://evil.example.com/steal', [
                'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
            ]);
        } catch (UriNotAllowedException) {
            // expected
        }
    }

    // ── 401 retry logic ───────────────────────────────────────────────────────

    public function testRetries401WithRefreshedToken(): void
    {
        $capturedTokens = [];
        $inner = new MockHttpClient([
            new MockResponse('{"error":"unauthorized"}', ['http_code' => 401]),
            static function (string $method, string $url, array $options) use (&$capturedTokens): MockResponse {
                $capturedTokens[] = self::extractAuthHeader($options);

                return new MockResponse('{"data":"ok"}', ['http_code' => 200]);
            },
        ]);

        $this->vault
            ->method('retrieveSecret')
            ->willReturnOnConsecutiveCalls(
                ['access_token' => 'old-token'],
                ['access_token' => 'refreshed-token'],
            );

        $refresher = $this->createMock(TokenRefresherInterface::class);
        $refresher->method('supports')->with('shopware6')->willReturn(true);
        $refresher->expects(self::once())->method('refresh')->with('tenant-1', null);

        $client = $this->makeClient($inner, $refresher);

        $response = $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $inner->getRequestsCount());
        self::assertSame(['Bearer refreshed-token'], $capturedTokens);
    }

    public function testCallsRefreshWithCorrectUserId(): void
    {
        $inner = new MockHttpClient([
            new MockResponse('', ['http_code' => 401]),
            new MockResponse('{}', ['http_code' => 200]),
        ]);

        $this->vault->method('retrieveSecret')->willReturn(['access_token' => 'tok']);

        $refresher = $this->createMock(TokenRefresherInterface::class);
        $refresher->method('supports')->willReturn(true);
        $refresher->expects(self::once())->method('refresh')->with('tenant-1', 'user-karim');

        $client = $this->makeClient($inner, $refresher);

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => [
                'vault' => [
                    'integration' => 'shopware6',
                    'tenant_id' => 'tenant-1',
                    'user_id' => 'user-karim',
                ],
            ],
        ]);
    }

    public function testReturns401DirectlyWhenNoRefresherSupportsIntegration(): void
    {
        $inner = new MockHttpClient(new MockResponse('{"error":"unauthorized"}', ['http_code' => 401]));

        $this->vault->method('retrieveSecret')->willReturn(['access_token' => 'tok']);

        $refresher = $this->createMock(TokenRefresherInterface::class);
        $refresher->method('supports')->willReturn(false); // does NOT support shopware6

        $client = $this->makeClient($inner, $refresher);

        $response = $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        // 401 returned as-is — no retry attempted.
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(1, $inner->getRequestsCount());
    }

    public function testReturns401DirectlyWhenNoRefreshersRegistered(): void
    {
        $inner = new MockHttpClient(new MockResponse('', ['http_code' => 401]));
        $this->vault->method('retrieveSecret')->willReturn(['access_token' => 'tok']);

        // No refreshers at all.
        $client = new VaultAwareHttpClient($inner, $this->vault, self::ALLOWED_URIS, []);

        $response = $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(1, $inner->getRequestsCount());
    }

    public function testRetryHappensExactlyOnce(): void
    {
        // Even if the retry also returns 401, we stop after one retry.
        $inner = new MockHttpClient([
            new MockResponse('', ['http_code' => 401]),
            new MockResponse('', ['http_code' => 401]), // second 401 — must NOT trigger a third request
        ]);

        $this->vault->method('retrieveSecret')->willReturn(['access_token' => 'tok']);

        $refresher = $this->createMock(TokenRefresherInterface::class);
        $refresher->method('supports')->willReturn(true);
        $refresher->expects(self::once())->method('refresh'); // called once

        $client = $this->makeClient($inner, $refresher);

        $response = $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        // Two requests total (original + one retry).
        self::assertSame(2, $inner->getRequestsCount());
        self::assertSame(401, $response->getStatusCode());
    }

    // ── validation / misconfig ────────────────────────────────────────────────

    public function testThrowsOnMissingIntegrationKey(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $client = $this->makeClient($inner);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/integration/');

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['tenant_id' => 'tenant-1']], // missing 'integration'
        ]);
    }

    public function testThrowsOnMissingTenantIdKey(): void
    {
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $client = $this->makeClient($inner);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tenant_id/');

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6']], // missing 'tenant_id'
        ]);
    }

    // ── dynamic URI resolver ──────────────────────────────────────────────────

    public function testDynamicResolverUsedWhenStaticListIsEmpty(): void
    {
        // When $allowedUris[integration] is empty but a resolver is registered,
        // the resolver's result is used as the effective allowlist.
        $capturedAuth = null;
        $inner = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$capturedAuth): MockResponse {
                $capturedAuth = self::extractAuthHeader($options);

                return new MockResponse('{}', ['http_code' => 200]);
            },
        );

        $this->vault->method('retrieveSecret')
            ->willReturn(['base_url' => 'https://my-shop.example.com', 'access_token' => 'shop-token']);

        $resolver = $this->createMock(VaultUriResolverInterface::class);
        $resolver->method('resolve')->willReturn(['https://my-shop.example.com/']);

        // Static allowlist is empty — resolver must supply the URI.
        $client = $this->makeClient($inner, allowedUris: [], uriResolver: $resolver);

        $response = $client->request('GET', 'https://my-shop.example.com/api/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Bearer shop-token', $capturedAuth);
    }

    public function testDynamicResolverBlocksNonMatchingUri(): void
    {
        // The resolver returns a real shop's base URI.
        // An attacker-controlled URL that doesn't start with it must be rejected.
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

        $this->vault->method('retrieveSecret')
            ->willReturn(['base_url' => 'https://my-shop.example.com', 'access_token' => 'tok']);

        $resolver = $this->createMock(VaultUriResolverInterface::class);
        $resolver->method('resolve')->willReturn(['https://my-shop.example.com/']);

        $client = $this->makeClient($inner, allowedUris: [], uriResolver: $resolver);

        $this->expectException(UriNotAllowedException::class);

        $client->request('GET', 'https://evil.example.com/steal-tokens', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);
    }

    public function testDynamicResolverReturnsEmptyListCausesException(): void
    {
        // If the resolver cannot determine a valid base_url (e.g. payload missing 'base_url'),
        // it returns [] → fail-closed: UriNotAllowedException thrown.
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

        $this->vault->method('retrieveSecret')
            ->willReturn(['client_id' => 'id', 'client_secret' => 'secret']); // no base_url

        $resolver = $this->createMock(VaultUriResolverInterface::class);
        $resolver->method('resolve')->willReturn([]); // no usable URI

        $client = $this->makeClient($inner, allowedUris: [], uriResolver: $resolver);

        $this->expectException(UriNotAllowedException::class);

        $client->request('GET', 'https://my-shop.example.com/api/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);
    }

    public function testDynamicResolverNotCalledWhenStaticListIsPresent(): void
    {
        // The resolver is registered but the static allowlist has entries.
        // The resolver MUST NOT be called — static list takes precedence.
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $this->vault->method('retrieveSecret')->willReturn(['access_token' => 'tok']);

        $resolver = $this->createMock(VaultUriResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        // Non-empty static allowlist → resolver never consulted.
        $client = $this->makeClient($inner, uriResolver: $resolver);

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);
    }

    public function testVaultAccessedBeforeUriCheckInDynamicPath(): void
    {
        // In the dynamic path, the Vault IS accessed (to retrieve base_url from payload)
        // before the URI validation. This is intentional and Rule-V5-compliant.
        // Contrast with the static path where vault is never accessed for blocked URIs.
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

        // Vault MUST be accessed once (to supply payload to the resolver).
        $this->vault->expects(self::once())->method('retrieveSecret')
            ->willReturn(['base_url' => 'https://real-shop.example.com', 'access_token' => 'tok']);

        $resolver = $this->createMock(VaultUriResolverInterface::class);
        // Resolver returns a different URI than the request URL → blocked.
        $resolver->method('resolve')->willReturn(['https://real-shop.example.com/']);

        $client = $this->makeClient($inner, allowedUris: [], uriResolver: $resolver);

        try {
            $client->request('GET', 'https://evil.example.com/steal', [
                'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
            ]);
        } catch (UriNotAllowedException) {
            // expected — the important assertion is that vault WAS accessed (see expects above)
        }
    }

    public function testDynamicResolverReceivesCorrectPayloadAndContext(): void
    {
        // The resolver receives the exact vault payload and the correct tenant context.
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

        $storedPayload = ['base_url' => 'https://my-shop.example.com', 'access_token' => 'tok'];
        $this->vault->method('retrieveSecret')->willReturn($storedPayload);

        $resolver = $this->createMock(VaultUriResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('tenant-acme', 'shopware6', $storedPayload)
            ->willReturn(['https://my-shop.example.com/']);

        $client = $this->makeClient($inner, allowedUris: [], uriResolver: $resolver);

        $client->request('GET', 'https://my-shop.example.com/api/product', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-acme']],
        ]);
    }

    // ── withOptions ───────────────────────────────────────────────────────────

    public function testWithOptionsReturnsNewInstance(): void
    {
        $inner = new MockHttpClient();
        $client = $this->makeClient($inner);

        $newClient = $client->withOptions(['timeout' => 30]);

        self::assertNotSame($client, $newClient);
        self::assertInstanceOf(VaultAwareHttpClient::class, $newClient);
    }

    public function testWithOptionsPreservesVaultBehavior(): void
    {
        $capturedAuth = null;
        $inner = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$capturedAuth): MockResponse {
                $capturedAuth = self::extractAuthHeader($options);

                return new MockResponse('{}', ['http_code' => 200]);
            },
        );

        $this->vault->method('retrieveSecret')->willReturn(['access_token' => 'tok-from-withOptions']);

        $client = $this->makeClient($inner)->withOptions([]);

        $client->request('GET', 'https://api.shopware.example.com/orders', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);

        self::assertSame('Bearer tok-from-withOptions', $capturedAuth);
    }

    public function testWithOptionsPreservesDynamicResolver(): void
    {
        // withOptions() must carry the VaultUriResolverInterface through to the new instance.
        $inner = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));
        $this->vault->method('retrieveSecret')
            ->willReturn(['base_url' => 'https://my-shop.example.com', 'access_token' => 'tok']);

        $resolver = $this->createMock(VaultUriResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(['https://my-shop.example.com/']);

        $client = $this->makeClient($inner, allowedUris: [], uriResolver: $resolver)->withOptions([]);

        $client->request('GET', 'https://my-shop.example.com/api/product', [
            'extra' => ['vault' => ['integration' => 'shopware6', 'tenant_id' => 'tenant-1']],
        ]);
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * Extract the Authorization header value from MockHttpClient-normalized request options.
     *
     * After MockHttpClient::prepareRequest() (via HttpClientTrait), headers are stored as a
     * flat numeric array of "Name: value" strings, e.g.:
     *   ['Authorization: Bearer tok', 'Accept: *\/*']
     *
     * @param array<string, mixed> $options
     */
    private static function extractAuthHeader(array $options): ?string
    {
        foreach ($options['headers'] ?? [] as $header) {
            if (!\is_string($header)) {
                continue;
            }

            if (0 === stripos($header, 'authorization:')) {
                // Strip "Authorization: " prefix (case-insensitive, variable spacing after colon).
                return ltrim(substr($header, \strlen('authorization:')));
            }
        }

        return null;
    }
}
