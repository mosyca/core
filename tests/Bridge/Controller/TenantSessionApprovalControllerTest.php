<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Bridge\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mosyca\Core\Bridge\Controller\TenantSessionApprovalController;
use Mosyca\Core\Bridge\TenantSession\TenantSession;
use Mosyca\Core\Bridge\TenantSession\TenantSessionRepository;
use Mosyca\Core\Bridge\TenantSession\TenantSessionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[CoversClass(TenantSessionApprovalController::class)]
final class TenantSessionApprovalControllerTest extends TestCase
{
    private const string TEST_SECRET = 'test-uri-signer-secret-oob-ca';
    private const string FAKE_JTI = 'a1b2c3d4-e5f6-4000-8000-aabbccddeeff';
    private const string BASE_URL = 'https://example.com/api/v1/mcp/session/a1b2c3d4-e5f6-4000-8000-aabbccddeeff/approve';

    private UriSigner $uriSigner;
    private MockObject&TenantSessionRepository $repository;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&Environment $twig;
    private MockObject&CsrfTokenManagerInterface $csrfTokenManager;

    protected function setUp(): void
    {
        $this->uriSigner = new UriSigner(self::TEST_SECRET);
        $this->repository = $this->createMock(TenantSessionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);

        $this->twig->method('render')->willReturn('<html>test</html>');
    }

    private function makeController(): TenantSessionApprovalController
    {
        return new TenantSessionApprovalController(
            $this->repository,
            $this->uriSigner,
            $this->entityManager,
            $this->twig,
            $this->csrfTokenManager,
        );
    }

    private function makePendingSession(): TenantSession
    {
        return new TenantSession(self::FAKE_JTI, 'production', new \DateTimeImmutable('+10 minutes'));
    }

    private function makeSignedUrl(): string
    {
        return $this->uriSigner->sign(self::BASE_URL, new \DateTimeImmutable('+10 minutes'));
    }

    // ── SR-OOB-2: unsigned / tampered URLs return 403 ─────────────────────

    #[Test]
    public function unsignedGetReturns403(): void
    {
        $request = Request::create(self::BASE_URL); // no signature

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function tamperedGetReturns403(): void
    {
        $signed = $this->makeSignedUrl();
        $tampered = str_replace(self::FAKE_JTI, 'evil-jti', $signed);

        $request = Request::create($tampered);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Session states on GET ──────────────────────────────────────────────

    #[Test]
    public function getWithValidSignedUrlAndPendingSessionReturns200(): void
    {
        $this->repository->method('findByJti')->willReturn($this->makePendingSession());
        $this->csrfTokenManager
            ->method('getToken')
            ->willReturn(new CsrfToken('tenant_session_'.self::FAKE_JTI, 'test-csrf-token'));

        $signedUrl = $this->makeSignedUrl();
        $request = Request::create($signedUrl);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function getWithSessionNotFoundReturns410(): void
    {
        $this->repository->method('findByJti')->willReturn(null);

        $signedUrl = $this->makeSignedUrl();
        $request = Request::create($signedUrl);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(410, $response->getStatusCode());
    }

    #[Test]
    public function getWithExpiredSessionReturns410(): void
    {
        $expired = new TenantSession(self::FAKE_JTI, 'production', new \DateTimeImmutable('-1 second'));
        $this->repository->method('findByJti')->willReturn($expired);

        $signedUrl = $this->makeSignedUrl();
        $request = Request::create($signedUrl);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(410, $response->getStatusCode());
    }

    #[Test]
    public function getWithAlreadyApprovedSessionReturns410(): void
    {
        $session = $this->makePendingSession();
        $session->approve();
        $this->repository->method('findByJti')->willReturn($session);

        $signedUrl = $this->makeSignedUrl();
        $request = Request::create($signedUrl);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(410, $response->getStatusCode());
    }

    // ── POST: approve ──────────────────────────────────────────────────────

    #[Test]
    public function postApproveTransitionsSessionToActive(): void
    {
        $session = $this->makePendingSession();
        $this->repository->method('findByJti')->willReturn($session);

        $this->csrfTokenManager
            ->method('isTokenValid')
            ->willReturn(true);

        $this->entityManager->expects(self::once())->method('flush');

        $signedUrl = $this->makeSignedUrl();
        $request = Request::create($signedUrl, 'POST', [
            '_csrf_token' => 'valid-token',
            'action' => 'approve',
        ]);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(TenantSessionState::ACTIVE, $session->getState());
    }

    // ── POST: deny ─────────────────────────────────────────────────────────

    #[Test]
    public function postDenyTransitionsSessionToDenied(): void
    {
        $session = $this->makePendingSession();
        $this->repository->method('findByJti')->willReturn($session);

        $this->csrfTokenManager
            ->method('isTokenValid')
            ->willReturn(true);

        $this->entityManager->expects(self::once())->method('flush');

        $signedUrl = $this->makeSignedUrl();
        $request = Request::create($signedUrl, 'POST', [
            '_csrf_token' => 'valid-token',
            'action' => 'deny',
        ]);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(TenantSessionState::DENIED, $session->getState());
    }

    // ── SR-OOB-3: CSRF validation ─────────────────────────────────────────

    #[Test]
    public function postWithInvalidCsrfReturns422(): void
    {
        $session = $this->makePendingSession();
        $this->repository->method('findByJti')->willReturn($session);

        $this->csrfTokenManager
            ->method('isTokenValid')
            ->willReturn(false);

        $this->entityManager->expects(self::never())->method('flush');

        $signedUrl = $this->makeSignedUrl();
        $request = Request::create($signedUrl, 'POST', [
            '_csrf_token' => 'invalid-token',
            'action' => 'approve',
        ]);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(TenantSessionState::PENDING, $session->getState()); // unchanged
    }

    #[Test]
    public function postWithInvalidActionReturns400(): void
    {
        $session = $this->makePendingSession();
        $this->repository->method('findByJti')->willReturn($session);

        $this->csrfTokenManager
            ->method('isTokenValid')
            ->willReturn(true);

        $this->entityManager->expects(self::never())->method('flush');

        $signedUrl = $this->makeSignedUrl();
        $request = Request::create($signedUrl, 'POST', [
            '_csrf_token' => 'valid-token',
            'action' => 'invalid-action',
        ]);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(TenantSessionState::PENDING, $session->getState()); // unchanged
    }

    // ── POST: unsigned URL still returns 403 (SR-OOB-2) ───────────────────

    #[Test]
    public function postWithUnsignedUrlReturns403BeforeSessionAccess(): void
    {
        $this->repository->expects(self::never())->method('findByJti');

        $request = Request::create(self::BASE_URL, 'POST', [
            '_csrf_token' => 'any-token',
            'action' => 'approve',
        ]);

        $controller = $this->makeController();
        $response = $controller($request, self::FAKE_JTI);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Twig rendering ─────────────────────────────────────────────────────

    #[Test]
    public function getRendersApprovalTemplate(): void
    {
        $session = $this->makePendingSession();
        $this->repository->method('findByJti')->willReturn($session);
        $this->csrfTokenManager
            ->method('getToken')
            ->willReturn(new CsrfToken('tenant_session_'.self::FAKE_JTI, 'test-csrf-token'));

        $this->twig
            ->expects(self::once())
            ->method('render')
            ->with('bridge/tenant_session_approval.html.twig', self::callback(static function (array $vars): bool {
                return isset($vars['session']) && isset($vars['csrf_token']) && isset($vars['jti']);
            }))
            ->willReturn('<html>test</html>');

        $signedUrl = $this->makeSignedUrl();
        $request = Request::create($signedUrl);

        $this->makeController()($request, self::FAKE_JTI);
    }
}
