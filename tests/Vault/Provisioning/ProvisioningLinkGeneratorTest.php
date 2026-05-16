<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Provisioning;

use Mosyca\Core\Vault\Provisioning\ProvisioningLinkGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(ProvisioningLinkGenerator::class)]
final class ProvisioningLinkGeneratorTest extends TestCase
{
    private const string TEST_SECRET = 'test-uri-signer-secret';
    private const string ROUTE_NAME = 'mosyca_vault_provision';
    private const string BASE_URL = 'https://example.com/api/vault/provision';

    private UriSigner $uriSigner;

    protected function setUp(): void
    {
        $this->uriSigner = new UriSigner(self::TEST_SECRET);
    }

    #[Test]
    public function generatedUrlContainsIntegrationParam(): void
    {
        $generator = $this->makeGenerator(['integration' => 'shopware6', 'tenant_id' => 'tenant-1']);

        $url = $generator->generate('shopware6', 'tenant-1');

        self::assertStringContainsString('integration=shopware6', $url);
    }

    #[Test]
    public function generatedUrlContainsTenantIdParam(): void
    {
        $generator = $this->makeGenerator(['integration' => 'spotify', 'tenant_id' => 'acme']);

        $url = $generator->generate('spotify', 'acme');

        self::assertStringContainsString('tenant_id=acme', $url);
    }

    #[Test]
    public function generatedUrlContainsHashSignature(): void
    {
        $generator = $this->makeGenerator(['integration' => 'shopware6', 'tenant_id' => 'tenant-1']);

        $url = $generator->generate('shopware6', 'tenant-1');

        self::assertStringContainsString('_hash=', $url);
    }

    #[Test]
    public function generatedUrlContainsExpiry(): void
    {
        $generator = $this->makeGenerator(['integration' => 'shopware6', 'tenant_id' => 'tenant-1']);

        $url = $generator->generate('shopware6', 'tenant-1');

        self::assertStringContainsString('_expiration=', $url);
    }

    #[Test]
    public function generatedUrlIsVerifiableByUriSigner(): void
    {
        $generator = $this->makeGenerator(['integration' => 'shopware6', 'tenant_id' => 'tenant-1']);

        $url = $generator->generate('shopware6', 'tenant-1');
        $request = Request::create($url);

        self::assertTrue($this->uriSigner->checkRequest($request));
    }

    #[Test]
    public function nullUserIdIsAbsentFromUrl(): void
    {
        $generator = $this->makeGenerator(['integration' => 'shopware6', 'tenant_id' => 'tenant-1']);

        $url = $generator->generate('shopware6', 'tenant-1', null);

        self::assertStringNotContainsString('user_id', $url);
    }

    #[Test]
    public function nonNullUserIdIsIncludedInUrl(): void
    {
        $generator = $this->makeGenerator([
            'integration' => 'shopware6',
            'tenant_id' => 'tenant-1',
            'user_id' => 'user-42',
        ]);

        $url = $generator->generate('shopware6', 'tenant-1', 'user-42');

        self::assertStringContainsString('user_id=user-42', $url);
    }

    #[Test]
    public function urlWithUserIdIsVerifiableByUriSigner(): void
    {
        $generator = $this->makeGenerator([
            'integration' => 'shopware6',
            'tenant_id' => 'tenant-1',
            'user_id' => 'user-42',
        ]);

        $url = $generator->generate('shopware6', 'tenant-1', 'user-42');
        $request = Request::create($url);

        self::assertTrue($this->uriSigner->checkRequest($request));
    }

    #[Test]
    public function customTtlIsReflectedInExpiryParam(): void
    {
        $ttl = 7_200; // 2 hours
        $before = time();

        $generator = $this->makeGenerator(['integration' => 'shopware6', 'tenant_id' => 'tenant-1']);
        $url = $generator->generate('shopware6', 'tenant-1', null, $ttl);

        $after = time();

        // Parse _expiration from URL.
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
        $expiry = (int) ($params['_expiration'] ?? 0);

        self::assertGreaterThanOrEqual($before + $ttl, $expiry);
        self::assertLessThanOrEqual($after + $ttl, $expiry);
    }

    #[Test]
    public function tamperedUrlFailsUriSignerVerification(): void
    {
        $generator = $this->makeGenerator(['integration' => 'shopware6', 'tenant_id' => 'tenant-1']);

        $url = $generator->generate('shopware6', 'tenant-1');
        $tampered = str_replace('tenant_id=tenant-1', 'tenant_id=evil-tenant', $url);
        $request = Request::create($tampered);

        self::assertFalse($this->uriSigner->checkRequest($request));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a ProvisioningLinkGenerator with a mock UrlGeneratorInterface that
     * returns a predictable base URL with the given params appended as a query string.
     *
     * @param array<string, string> $expectedParams
     */
    private function makeGenerator(array $expectedParams): ProvisioningLinkGenerator
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $urlGenerator
            ->method('generate')
            ->with(self::ROUTE_NAME, $expectedParams, UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn(self::BASE_URL.'?'.http_build_query($expectedParams));

        return new ProvisioningLinkGenerator($urlGenerator, $this->uriSigner);
    }
}
