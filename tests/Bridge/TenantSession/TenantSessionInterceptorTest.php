<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Bridge\TenantSession;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Mosyca\Core\Bridge\TenantSession\TenantSession;
use Mosyca\Core\Bridge\TenantSession\TenantSessionApprovalUrlGenerator;
use Mosyca\Core\Bridge\TenantSession\TenantSessionInterceptor;
use Mosyca\Core\Bridge\TenantSession\TenantSessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantSessionInterceptor::class)]
final class TenantSessionInterceptorTest extends TestCase
{
    private const string FAKE_TOKEN = 'eyJ.fake.token';
    private const string FAKE_JTI = 'a1b2c3d4-e5f6-4000-8000-aabbccddeeff';
    private const string FAKE_TENANT = 'production';
    private const string FAKE_APPROVAL_URL = 'https://example.com/api/v1/mcp/session/abc/approve?_hash=abc';

    private MockObject&JWTEncoderInterface $jwtEncoder;
    private MockObject&TenantSessionRepository $repository;
    private MockObject&TenantSessionApprovalUrlGenerator $urlGenerator;

    protected function setUp(): void
    {
        $this->jwtEncoder = $this->createMock(JWTEncoderInterface::class);
        $this->repository = $this->createMock(TenantSessionRepository::class);
        $this->urlGenerator = $this->createMock(TenantSessionApprovalUrlGenerator::class);

        $this->urlGenerator->method('generate')->willReturn(self::FAKE_APPROVAL_URL);
    }

    private function makeInterceptor(): TenantSessionInterceptor
    {
        return new TenantSessionInterceptor($this->jwtEncoder, $this->repository, $this->urlGenerator);
    }

    // ── Pass-through: no token ─────────────────────────────────────────────

    #[Test]
    public function noTokenPassesThrough(): void
    {
        $arguments = ['tenant' => 'default', 'limit' => 10];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNull($result);
        self::assertArrayNotHasKey('_mcp_context_token', $arguments);
        self::assertSame('default', $arguments['tenant']); // unchanged
    }

    // ── SR-OOB-4: token is always stripped ────────────────────────────────

    #[Test]
    public function tokenIsAlwaysStrippedFromArgumentsEvenOnFailure(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willThrowException(new JWTDecodeFailureException(JWTDecodeFailureException::INVALID_TOKEN, 'Invalid JWT Token'));

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN, 'tenant' => 'default'];

        $this->makeInterceptor()->intercept($arguments);

        self::assertArrayNotHasKey('_mcp_context_token', $arguments);
    }

    #[Test]
    public function emptyTokenReturnsFailure(): void
    {
        $arguments = ['_mcp_context_token' => '', 'tenant' => 'default'];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNotNull($result);
        self::assertFalse($result['success']);
        self::assertSame('OOBCA_INVALID_TOKEN', $result['errorCode']);
    }

    // ── JWT decode failures ────────────────────────────────────────────────

    #[Test]
    public function expiredJwtReturnsTokenExpiredError(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willThrowException(new JWTDecodeFailureException(JWTDecodeFailureException::EXPIRED_TOKEN, 'Expired JWT Token'));

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNotNull($result);
        self::assertFalse($result['success']);
        self::assertSame('OOBCA_TOKEN_EXPIRED', $result['errorCode']);
    }

    #[Test]
    public function invalidJwtReturnsInvalidTokenError(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willThrowException(new JWTDecodeFailureException(JWTDecodeFailureException::UNVERIFIED_TOKEN, 'Unable to verify JWT Token'));

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNotNull($result);
        self::assertFalse($result['success']);
        self::assertSame('OOBCA_INVALID_TOKEN', $result['errorCode']);
    }

    #[Test]
    public function missingJtiClaimReturnsInvalidTokenError(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willReturn(['tenant_id' => self::FAKE_TENANT]); // no jti

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNotNull($result);
        self::assertFalse($result['success']);
        self::assertSame('OOBCA_INVALID_TOKEN', $result['errorCode']);
    }

    #[Test]
    public function missingTenantIdClaimReturnsInvalidTokenError(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willReturn(['jti' => self::FAKE_JTI]); // no tenant_id

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNotNull($result);
        self::assertFalse($result['success']);
        self::assertSame('OOBCA_INVALID_TOKEN', $result['errorCode']);
    }

    // ── Session not found ──────────────────────────────────────────────────

    #[Test]
    public function sessionNotFoundReturnsError(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willReturn(['jti' => self::FAKE_JTI, 'tenant_id' => self::FAKE_TENANT]);

        $this->repository->method('findByJti')->willReturn(null);

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNotNull($result);
        self::assertFalse($result['success']);
        self::assertSame('OOBCA_SESSION_NOT_FOUND', $result['errorCode']);
    }

    // ── PENDING state ──────────────────────────────────────────────────────

    #[Test]
    public function pendingSessionReturnsApprovalPendingError(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willReturn(['jti' => self::FAKE_JTI, 'tenant_id' => self::FAKE_TENANT]);

        $session = new TenantSession(self::FAKE_JTI, self::FAKE_TENANT, new \DateTimeImmutable('+10 minutes'));
        $this->repository->method('findByJti')->willReturn($session);

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNotNull($result);
        self::assertFalse($result['success']);
        self::assertSame('OOBCA_APPROVAL_PENDING', $result['errorCode']);
        self::assertIsArray($result['data']);
        self::assertArrayHasKey('approval_url', $result['data']);
        self::assertSame(self::FAKE_APPROVAL_URL, $result['data']['approval_url']);
    }

    #[Test]
    public function expiredPendingSessionReturnsExpiredError(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willReturn(['jti' => self::FAKE_JTI, 'tenant_id' => self::FAKE_TENANT]);

        $session = new TenantSession(self::FAKE_JTI, self::FAKE_TENANT, new \DateTimeImmutable('-1 second'));
        $this->repository->method('findByJti')->willReturn($session);

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNotNull($result);
        self::assertFalse($result['success']);
        self::assertSame('OOBCA_TOKEN_EXPIRED', $result['errorCode']);
    }

    // ── DENIED state ───────────────────────────────────────────────────────

    #[Test]
    public function deniedSessionReturnsSessionDeniedError(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willReturn(['jti' => self::FAKE_JTI, 'tenant_id' => self::FAKE_TENANT]);

        $session = new TenantSession(self::FAKE_JTI, self::FAKE_TENANT, new \DateTimeImmutable('+10 minutes'));
        $session->deny();
        $this->repository->method('findByJti')->willReturn($session);

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN];

        $result = $this->makeInterceptor()->intercept($arguments);

        self::assertNotNull($result);
        self::assertFalse($result['success']);
        self::assertSame('OOBCA_SESSION_DENIED', $result['errorCode']);
    }

    // ── ACTIVE state ───────────────────────────────────────────────────────

    #[Test]
    public function activeSessionReturnNullAndMutatesTenant(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willReturn(['jti' => self::FAKE_JTI, 'tenant_id' => self::FAKE_TENANT]);

        $session = new TenantSession(self::FAKE_JTI, self::FAKE_TENANT, new \DateTimeImmutable('+10 minutes'));
        $session->approve();
        $this->repository->method('findByJti')->willReturn($session);

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN, 'tenant' => 'staging'];

        $result = $this->makeInterceptor()->intercept($arguments);

        // SR-OOB-5: null means continue; tenant overwritten with JWT-authorised value.
        self::assertNull($result);
        self::assertSame(self::FAKE_TENANT, $arguments['tenant']); // overwritten
    }

    #[Test]
    public function activeSessionStripsTokenFromArguments(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willReturn(['jti' => self::FAKE_JTI, 'tenant_id' => self::FAKE_TENANT]);

        $session = new TenantSession(self::FAKE_JTI, self::FAKE_TENANT, new \DateTimeImmutable('+10 minutes'));
        $session->approve();
        $this->repository->method('findByJti')->willReturn($session);

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN, 'tenant' => 'staging'];

        $this->makeInterceptor()->intercept($arguments);

        // SR-OOB-4: token must not be visible to the downstream action.
        self::assertArrayNotHasKey('_mcp_context_token', $arguments);
    }

    // ── SR-OOB-5: JWT tenant_id is authoritative ───────────────────────────

    #[Test]
    public function activeSessionOverwritesCallerSuppliedTenant(): void
    {
        $this->jwtEncoder
            ->method('decode')
            ->willReturn(['jti' => self::FAKE_JTI, 'tenant_id' => 'production']); // JWT says production

        $session = new TenantSession(self::FAKE_JTI, 'production', new \DateTimeImmutable('+10 minutes'));
        $session->approve();
        $this->repository->method('findByJti')->willReturn($session);

        $arguments = ['_mcp_context_token' => self::FAKE_TOKEN, 'tenant' => 'evil-override'];

        $this->makeInterceptor()->intercept($arguments);

        self::assertSame('production', $arguments['tenant']); // attacker-supplied value rejected
    }
}
