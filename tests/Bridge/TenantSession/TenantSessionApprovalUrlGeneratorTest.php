<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Bridge\TenantSession;

use Mosyca\Core\Bridge\TenantSession\TenantSessionApprovalUrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(TenantSessionApprovalUrlGenerator::class)]
final class TenantSessionApprovalUrlGeneratorTest extends TestCase
{
    private const string TEST_SECRET = 'oob-ca-test-uri-signer-secret';
    private const string ROUTE_NAME = 'mosyca_tenant_session_approve';
    private const string BASE_URL = 'https://example.com/api/v1/mcp/session/test-jti-value/approve';

    private UriSigner $uriSigner;

    protected function setUp(): void
    {
        $this->uriSigner = new UriSigner(self::TEST_SECRET);
    }

    #[Test]
    public function generatedUrlContainsJtiPathSegment(): void
    {
        $generator = $this->makeGenerator('test-jti-value');

        $url = $generator->generate('test-jti-value');

        self::assertStringContainsString('test-jti-value', $url);
    }

    #[Test]
    public function generatedUrlContainsHashSignature(): void
    {
        $generator = $this->makeGenerator('test-jti-value');

        $url = $generator->generate('test-jti-value');

        self::assertStringContainsString('_hash=', $url);
    }

    #[Test]
    public function generatedUrlContainsExpiry(): void
    {
        $generator = $this->makeGenerator('test-jti-value');

        $url = $generator->generate('test-jti-value');

        self::assertStringContainsString('_expiration=', $url);
    }

    #[Test]
    public function generatedUrlIsVerifiableByUriSigner(): void
    {
        $generator = $this->makeGenerator('test-jti-value');

        $url = $generator->generate('test-jti-value');
        $request = Request::create($url);

        self::assertTrue($this->uriSigner->checkRequest($request));
    }

    #[Test]
    public function tamperedUrlFailsUriSignerVerification(): void
    {
        $generator = $this->makeGenerator('test-jti-value');

        $url = $generator->generate('test-jti-value');
        $tampered = str_replace('test-jti-value', 'evil-jti', $url);
        $request = Request::create($tampered);

        self::assertFalse($this->uriSigner->checkRequest($request));
    }

    #[Test]
    public function customTtlIsReflectedInExpiryParam(): void
    {
        $ttl = 300;
        $before = time();

        $generator = $this->makeGenerator('test-jti-value', $ttl);
        $url = $generator->generate('test-jti-value');

        $after = time();

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
        $rawExpiration = $params['_expiration'] ?? null;
        $expiry = \is_string($rawExpiration) ? (int) $rawExpiration : 0;

        self::assertGreaterThanOrEqual($before + $ttl, $expiry);
        self::assertLessThanOrEqual($after + $ttl, $expiry);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeGenerator(string $jti, int $ttl = 600): TenantSessionApprovalUrlGenerator
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $urlGenerator
            ->method('generate')
            ->with(self::ROUTE_NAME, ['jti' => $jti], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn(self::BASE_URL);

        return new TenantSessionApprovalUrlGenerator($urlGenerator, $this->uriSigner, $ttl);
    }
}
