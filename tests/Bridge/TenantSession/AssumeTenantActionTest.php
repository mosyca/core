<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Bridge\TenantSession;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Mosyca\Core\Action\Builtin\AssumeTenantAction;
use Mosyca\Core\Bridge\TenantSession\TenantSession;
use Mosyca\Core\Bridge\TenantSession\TenantSessionApprovalUrlGenerator;
use Mosyca\Core\Bridge\TenantSession\TenantSessionRepository;
use Mosyca\Core\Context\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssumeTenantAction::class)]
final class AssumeTenantActionTest extends TestCase
{
    private const string FAKE_JWT = 'eyJhbGciOiJSUzI1NiJ9.fake.token';
    private const string FAKE_APPROVAL_URL = 'https://example.com/api/v1/mcp/session/abc/approve?_hash=xyz';

    private MockObject&JWTEncoderInterface $jwtEncoder;
    private MockObject&TenantSessionRepository $repository;
    private MockObject&TenantSessionApprovalUrlGenerator $urlGenerator;

    protected function setUp(): void
    {
        $this->jwtEncoder = $this->createMock(JWTEncoderInterface::class);
        $this->repository = $this->createMock(TenantSessionRepository::class);
        $this->urlGenerator = $this->createMock(TenantSessionApprovalUrlGenerator::class);
    }

    private function makeAction(): AssumeTenantAction
    {
        return new AssumeTenantAction($this->jwtEncoder, $this->repository, $this->urlGenerator);
    }

    private function makeContext(string $tenantId = 'test-tenant'): ExecutionContext
    {
        return new ExecutionContext(
            tenantId: $tenantId,
            userId: null,
            actingUserId: null,
            delegated: false,
            authenticated: false,
            aclBypassed: false,
        );
    }

    // ── Happy path ─────────────────────────────────────────────────────────

    #[Test]
    public function successfulExecutionReturnsOkWithToken(): void
    {
        $this->jwtEncoder->method('encode')->willReturn(self::FAKE_JWT);
        $this->urlGenerator->method('generate')->willReturn(self::FAKE_APPROVAL_URL);

        $result = $this->makeAction()->execute(['tenant' => 'production'], $this->makeContext());

        self::assertTrue($result->success);
        self::assertIsArray($result->data);
        self::assertArrayHasKey('_mcp_context_token', $result->data);
        self::assertSame(self::FAKE_JWT, $result->data['_mcp_context_token']);
    }

    #[Test]
    public function successfulExecutionReturnsApprovalUrl(): void
    {
        $this->jwtEncoder->method('encode')->willReturn(self::FAKE_JWT);
        $this->urlGenerator->method('generate')->willReturn(self::FAKE_APPROVAL_URL);

        $result = $this->makeAction()->execute(['tenant' => 'production'], $this->makeContext());

        self::assertIsArray($result->data);
        self::assertArrayHasKey('approval_url', $result->data);
        self::assertSame(self::FAKE_APPROVAL_URL, $result->data['approval_url']);
    }

    #[Test]
    public function successfulExecutionReturnsTenantSlug(): void
    {
        $this->jwtEncoder->method('encode')->willReturn(self::FAKE_JWT);
        $this->urlGenerator->method('generate')->willReturn(self::FAKE_APPROVAL_URL);

        $result = $this->makeAction()->execute(['tenant' => 'staging'], $this->makeContext());

        self::assertIsArray($result->data);
        self::assertArrayHasKey('tenant', $result->data);
        self::assertSame('staging', $result->data['tenant']);
    }

    #[Test]
    public function sessionIsPersistedWithFlush(): void
    {
        $this->jwtEncoder->method('encode')->willReturn(self::FAKE_JWT);
        $this->urlGenerator->method('generate')->willReturn(self::FAKE_APPROVAL_URL);

        $this->repository
            ->expects(self::once())
            ->method('save')
            ->with(
                self::callback(static function (TenantSession $session): bool {
                    return 'production' === $session->getTenantId();
                }),
                true, // flush=true
            );

        $this->makeAction()->execute(['tenant' => 'production'], $this->makeContext());
    }

    #[Test]
    public function jwtClaimsIncludeTenantIdJtiAndExp(): void
    {
        $capturedPayload = null;

        $this->jwtEncoder
            ->expects(self::once())
            ->method('encode')
            ->with(self::callback(static function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            }))
            ->willReturn(self::FAKE_JWT);

        $this->urlGenerator->method('generate')->willReturn(self::FAKE_APPROVAL_URL);

        $this->makeAction()->execute(['tenant' => 'production'], $this->makeContext());

        self::assertIsArray($capturedPayload);
        self::assertArrayHasKey('tenant_id', $capturedPayload);
        self::assertSame('production', $capturedPayload['tenant_id']);
        self::assertArrayHasKey('jti', $capturedPayload);
        self::assertIsString($capturedPayload['jti']);
        self::assertArrayHasKey('exp', $capturedPayload);
        self::assertIsInt($capturedPayload['exp']);
        // exp should be in the future (approx. now + 600 seconds)
        self::assertGreaterThan(time(), $capturedPayload['exp']);
    }

    // ── Missing tenant parameter ────────────────────────────────────────────

    #[Test]
    public function missingTenantParameterReturnsFailure(): void
    {
        $result = $this->makeAction()->execute([], $this->makeContext());

        self::assertFalse($result->success);
        self::assertSame('ERROR_INVALID_PARAMS', $result->errorCode);
    }

    #[Test]
    public function emptyTenantParameterReturnsFailure(): void
    {
        $result = $this->makeAction()->execute(['tenant' => ''], $this->makeContext());

        self::assertFalse($result->success);
        self::assertSame('ERROR_INVALID_PARAMS', $result->errorCode);
    }

    // ── Security: SR-OOB-4 — result data must not contain secrets ──────────

    #[Test]
    public function resultDataNeverContainsSecretValues(): void
    {
        $this->jwtEncoder->method('encode')->willReturn(self::FAKE_JWT);
        $this->urlGenerator->method('generate')->willReturn(self::FAKE_APPROVAL_URL);

        $result = $this->makeAction()->execute(['tenant' => 'production'], $this->makeContext());

        // The JWT itself is returned (it is the OOB-CA token, not a secret credential).
        // But the payload must not contain raw password-like secret values.
        $dataJson = json_encode($result->data);

        self::assertIsString($dataJson);
        self::assertStringNotContainsString('client_secret', $dataJson);
        self::assertStringNotContainsString('access_token', $dataJson);
        self::assertStringNotContainsString('password', $dataJson);
    }

    // ── Metadata ───────────────────────────────────────────────────────────

    #[Test]
    public function actionNameFollowsConvention(): void
    {
        self::assertSame('mosyca:system:assume_tenant', $this->makeAction()->getName());
    }

    #[Test]
    public function actionIsNotMutating(): void
    {
        // Creates a DB record but does not mutate tenant data.
        self::assertFalse($this->makeAction()->isMutating());
    }

    #[Test]
    public function actionHasTenantParameter(): void
    {
        $params = $this->makeAction()->getParameters();

        self::assertArrayHasKey('tenant', $params);
        self::assertTrue($params['tenant']['required']);
    }
}
