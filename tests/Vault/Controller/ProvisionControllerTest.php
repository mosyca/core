<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Controller;

use Mosyca\Core\Vault\Controller\ProvisionController;
use Mosyca\Core\Vault\VaultManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;

#[CoversClass(ProvisionController::class)]
final class ProvisionControllerTest extends TestCase
{
    private const string TEST_SECRET = 'test-signer-secret-for-unit-tests';
    private const string PROVISION_PATH = '/api/vault/provision';

    private UriSigner $uriSigner;

    protected function setUp(): void
    {
        $this->uriSigner = new UriSigner(self::TEST_SECRET);
    }

    #[Test]
    public function validSignedUrlWithValidJsonBodyReturns201(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::once())
            ->method('storeSecret')
            ->with('tenant-1', 'shopware6', ['api_key' => 'abc123'], null);

        $controller = new ProvisionController($vault, $this->uriSigner);
        $request = $this->makeSignedRequest(
            ['integration' => 'shopware6', 'tenant_id' => 'tenant-1'],
            (string) json_encode(['api_key' => 'abc123']),
        );

        $response = $controller($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['stored' => true], json_decode((string) $response->getContent(), true));
    }

    #[Test]
    public function invalidSignatureReturns403(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::never())->method('storeSecret');

        $controller = new ProvisionController($vault, $this->uriSigner);

        // Build URL with a different signer (different secret → invalid hash).
        $badSigner = new UriSigner('wrong-secret');
        $url = self::PROVISION_PATH.'?integration=shopware6&tenant_id=tenant-1';
        $signedUrl = $badSigner->sign('https://example.com'.$url, new \DateTimeImmutable('+1 hour'));

        $request = Request::create($signedUrl, 'POST', [], [], [], [], (string) json_encode(['k' => 'v']));

        $response = $controller($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function unsignedUrlReturns403(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::never())->method('storeSecret');

        $controller = new ProvisionController($vault, $this->uriSigner);
        $url = 'https://example.com'.self::PROVISION_PATH.'?integration=shopware6&tenant_id=tenant-1';
        $request = Request::create($url, 'POST', [], [], [], [], (string) json_encode(['k' => 'v']));

        $response = $controller($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function expiredSignedUrlReturns403(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::never())->method('storeSecret');

        $controller = new ProvisionController($vault, $this->uriSigner);

        // Sign with a past expiry.
        $url = 'https://example.com'.self::PROVISION_PATH.'?integration=shopware6&tenant_id=tenant-1';
        $signedUrl = $this->uriSigner->sign($url, new \DateTimeImmutable('-1 second'));
        $request = Request::create($signedUrl, 'POST', [], [], [], [], (string) json_encode(['k' => 'v']));

        $response = $controller($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function missingIntegrationParamReturns400(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::never())->method('storeSecret');

        $controller = new ProvisionController($vault, $this->uriSigner);
        // Only tenant_id — no integration.
        $request = $this->makeSignedRequest(
            ['tenant_id' => 'tenant-1'],
            (string) json_encode(['k' => 'v']),
        );

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('integration', (string) $response->getContent());
    }

    #[Test]
    public function missingTenantIdParamReturns400(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::never())->method('storeSecret');

        $controller = new ProvisionController($vault, $this->uriSigner);
        // Only integration — no tenant_id.
        $request = $this->makeSignedRequest(
            ['integration' => 'shopware6'],
            (string) json_encode(['k' => 'v']),
        );

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('tenant_id', (string) $response->getContent());
    }

    #[Test]
    public function nonJsonBodyReturns400(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::never())->method('storeSecret');

        $controller = new ProvisionController($vault, $this->uriSigner);
        $request = $this->makeSignedRequest(
            ['integration' => 'shopware6', 'tenant_id' => 'tenant-1'],
            'not-json-at-all',
        );

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function jsonArrayBodyReturns400(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::never())->method('storeSecret');

        $controller = new ProvisionController($vault, $this->uriSigner);
        // JSON array (not object) must be rejected.
        $request = $this->makeSignedRequest(
            ['integration' => 'shopware6', 'tenant_id' => 'tenant-1'],
            (string) json_encode(['val1', 'val2']),
        );

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function emptyUserIdIsNormalisedToNull(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::once())
            ->method('storeSecret')
            ->with('tenant-1', 'shopware6', ['k' => 'v'], null);

        $controller = new ProvisionController($vault, $this->uriSigner);
        $request = $this->makeSignedRequest(
            ['integration' => 'shopware6', 'tenant_id' => 'tenant-1', 'user_id' => ''],
            (string) json_encode(['k' => 'v']),
        );

        $response = $controller($request);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function nonEmptyUserIdIsPassedThrough(): void
    {
        $vault = $this->createMock(VaultManager::class);
        $vault->expects(self::once())
            ->method('storeSecret')
            ->with('tenant-1', 'shopware6', ['k' => 'v'], 'user-99');

        $controller = new ProvisionController($vault, $this->uriSigner);
        $request = $this->makeSignedRequest(
            ['integration' => 'shopware6', 'tenant_id' => 'tenant-1', 'user_id' => 'user-99'],
            (string) json_encode(['k' => 'v']),
        );

        $response = $controller($request);

        self::assertSame(201, $response->getStatusCode());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Create a signed POST request with the given query params and body.
     *
     * @param array<string, string> $params
     */
    private function makeSignedRequest(array $params, string $body): Request
    {
        $url = 'https://example.com'.self::PROVISION_PATH.'?'.http_build_query($params);
        $signedUrl = $this->uriSigner->sign($url, new \DateTimeImmutable('+1 hour'));

        return Request::create($signedUrl, 'POST', [], [], [], [], $body);
    }
}
