<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Controller;

use Mosyca\Core\Vault\Controller\OAuthCallbackController;
use Mosyca\Core\Vault\Provisioning\OAuthCallbackHandlerInterface;
use Mosyca\Core\Vault\Provisioning\OAuthStateEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(OAuthCallbackController::class)]
final class OAuthCallbackControllerTest extends TestCase
{
    private const string TEST_SECRET = 'test-hmac-secret-for-callback-tests';

    private OAuthStateEncoder $stateEncoder;

    protected function setUp(): void
    {
        $this->stateEncoder = new OAuthStateEncoder(self::TEST_SECRET);
    }

    #[Test]
    public function validCodeAndStateReturns200WithStoredTrue(): void
    {
        $state = $this->stateEncoder->encode('spotify', 'tenant-1', 'user-42');

        $handler = $this->createMock(OAuthCallbackHandlerInterface::class);
        $handler->method('supports')->with('spotify')->willReturn(true);
        $handler->expects(self::once())
            ->method('handleCallback')
            ->with($this->anything(), 'tenant-1', 'user-42');

        $controller = new OAuthCallbackController($this->stateEncoder, [$handler]);
        $request = $this->makeRequest(['code' => 'auth-code-xyz', 'state' => $state]);

        $response = $controller($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['stored' => true], json_decode((string) $response->getContent(), true));
    }

    #[Test]
    public function invalidStateReturns400(): void
    {
        $handler = $this->createMock(OAuthCallbackHandlerInterface::class);
        $handler->expects(self::never())->method('handleCallback');

        $controller = new OAuthCallbackController($this->stateEncoder, [$handler]);
        $request = $this->makeRequest(['code' => 'some-code', 'state' => 'tampered.state.value']);

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function expiredStateReturns400(): void
    {
        // Build an expired state manually (exp = past timestamp).
        $payload = json_encode([
            'integration' => 'spotify',
            'tenant_id' => 'tenant-1',
            'user_id' => null,
            'exp' => time() - 1,
        ], \JSON_THROW_ON_ERROR);

        $hmac = hash_hmac('sha256', $payload, self::TEST_SECRET);
        $expiredState = $this->base64urlEncode($payload).'.'.$this->base64urlEncode($hmac);

        $handler = $this->createMock(OAuthCallbackHandlerInterface::class);
        $handler->expects(self::never())->method('handleCallback');

        $controller = new OAuthCallbackController($this->stateEncoder, [$handler]);
        $request = $this->makeRequest(['code' => 'some-code', 'state' => $expiredState]);

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function missingCodeParamReturns400(): void
    {
        $state = $this->stateEncoder->encode('spotify', 'tenant-1', null);

        $handler = $this->createMock(OAuthCallbackHandlerInterface::class);
        $handler->expects(self::never())->method('handleCallback');

        $controller = new OAuthCallbackController($this->stateEncoder, [$handler]);
        $request = $this->makeRequest(['state' => $state]); // no code

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('code', (string) $response->getContent());
    }

    #[Test]
    public function missingStateParamReturns400(): void
    {
        $handler = $this->createMock(OAuthCallbackHandlerInterface::class);
        $handler->expects(self::never())->method('handleCallback');

        $controller = new OAuthCallbackController($this->stateEncoder, [$handler]);
        $request = $this->makeRequest(['code' => 'some-code']); // no state

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('state', (string) $response->getContent());
    }

    #[Test]
    public function oauthErrorParamReturns400(): void
    {
        $handler = $this->createMock(OAuthCallbackHandlerInterface::class);
        $handler->expects(self::never())->method('handleCallback');

        $controller = new OAuthCallbackController($this->stateEncoder, [$handler]);
        $request = $this->makeRequest(['error' => 'access_denied', 'state' => 'whatever']);

        $response = $controller($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function noHandlerForIntegrationReturns422(): void
    {
        $state = $this->stateEncoder->encode('unknown-integration', 'tenant-1', null);

        $handler = $this->createMock(OAuthCallbackHandlerInterface::class);
        $handler->method('supports')->willReturn(false);
        $handler->expects(self::never())->method('handleCallback');

        $controller = new OAuthCallbackController($this->stateEncoder, [$handler]);
        $request = $this->makeRequest(['code' => 'some-code', 'state' => $state]);

        $response = $controller($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function handlerThrowingReturns502(): void
    {
        $state = $this->stateEncoder->encode('spotify', 'tenant-1', null);

        $handler = $this->createMock(OAuthCallbackHandlerInterface::class);
        $handler->method('supports')->with('spotify')->willReturn(true);
        $handler->method('handleCallback')->willThrowException(new \RuntimeException('Token exchange failed'));

        $controller = new OAuthCallbackController($this->stateEncoder, [$handler]);
        $request = $this->makeRequest(['code' => 'some-code', 'state' => $state]);

        $response = $controller($request);

        self::assertSame(502, $response->getStatusCode());
        // Vault Rule V2: the exception message must NOT appear in the response.
        self::assertStringNotContainsString('Token exchange failed', (string) $response->getContent());
    }

    #[Test]
    public function firstMatchingHandlerWinsWhenMultipleHandlersRegistered(): void
    {
        $state = $this->stateEncoder->encode('spotify', 'tenant-1', null);

        $first = $this->createMock(OAuthCallbackHandlerInterface::class);
        $first->method('supports')->with('spotify')->willReturn(true);
        $first->expects(self::once())->method('handleCallback');

        $second = $this->createMock(OAuthCallbackHandlerInterface::class);
        $second->method('supports')->with('spotify')->willReturn(true);
        $second->expects(self::never())->method('handleCallback');

        $controller = new OAuthCallbackController($this->stateEncoder, [$first, $second]);
        $request = $this->makeRequest(['code' => 'some-code', 'state' => $state]);

        $response = $controller($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function nullUserIdFromStateIsPassedAsNullToHandler(): void
    {
        $state = $this->stateEncoder->encode('spotify', 'tenant-1', null);

        $handler = $this->createMock(OAuthCallbackHandlerInterface::class);
        $handler->method('supports')->with('spotify')->willReturn(true);
        $handler->expects(self::once())
            ->method('handleCallback')
            ->with($this->anything(), 'tenant-1', null);

        $controller = new OAuthCallbackController($this->stateEncoder, [$handler]);
        $request = $this->makeRequest(['code' => 'auth-code', 'state' => $state]);

        $controller($request);
    }

    #[Test]
    public function noHandlersRegisteredReturns422(): void
    {
        $state = $this->stateEncoder->encode('spotify', 'tenant-1', null);

        $controller = new OAuthCallbackController($this->stateEncoder, []);
        $request = $this->makeRequest(['code' => 'some-code', 'state' => $state]);

        $response = $controller($request);

        self::assertSame(422, $response->getStatusCode());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    private function makeRequest(array $params): Request
    {
        $url = 'https://example.com/api/vault/oauth/callback?'.http_build_query($params);

        return Request::create($url);
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
