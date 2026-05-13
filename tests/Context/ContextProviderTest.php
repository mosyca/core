<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Context;

use Mosyca\Core\Context\ContextProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * ContextProvider tests — TDD for ACL Architecture Acceptance Criteria.
 *
 * AC 2: SwitchUserToken → isDelegated=true, correct user IDs
 * AC 3: Missing tenant → RuntimeException
 * AC 4: A impersonates B → getUserId()===B, getActingUserId()===A
 * AC 5: ROLE_ADMIN without ROLE_MANAGER → isAclBypassed()===false
 * AC 6: see PluginResultTest
 * CLI: createForCli() → isAclBypassed()===false
 */
final class ContextProviderTest extends TestCase
{
    /** @var RequestStack&MockObject */
    private RequestStack $requestStack;
    /** @var TokenStorageInterface&MockObject */
    private TokenStorageInterface $tokenStorage;
    /** @var AuthorizationCheckerInterface&MockObject */
    private AuthorizationCheckerInterface $authChecker;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);
    }

    // ── HTTP context ─────────────────────────────────────────────────────────

    public function testCreateReturnsContextWithTenantFromRoute(): void
    {
        $request = $this->requestWithTenant('shop-berlin');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->tokenStorage->method('getToken')->willReturn(null);
        $this->authChecker->method('isGranted')->willReturn(false);

        $ctx = $this->provider()->create();

        self::assertSame('shop-berlin', $ctx->getTenantId());
    }

    /**
     * AC 3: missing route attribute "tenant" → RuntimeException.
     */
    public function testCreateThrowsWhenTenantAttributeMissing(): void
    {
        $request = Request::create('/api/v1/core/system/ping/run', 'POST');
        // No 'tenant' attribute set → empty
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->tokenStorage->method('getToken')->willReturn(null);
        $this->authChecker->method('isGranted')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tenant/i');

        $this->provider()->create();
    }

    /**
     * AC 3: no request AND no mcpTenantId → RuntimeException.
     */
    public function testCreateThrowsWhenNoRequestAndNoMcpTenant(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);
        $this->tokenStorage->method('getToken')->willReturn(null);
        $this->authChecker->method('isGranted')->willReturn(false);

        $this->expectException(\RuntimeException::class);

        $this->provider()->create(mcpTenantId: null);
    }

    /**
     * MCP stdio: tenant comes from tool payload, not URL.
     */
    public function testCreateWithMcpTenantIdWhenNoRequest(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);
        $this->tokenStorage->method('getToken')->willReturn(null);
        $this->authChecker->method('isGranted')->willReturn(false);

        $ctx = $this->provider()->create(mcpTenantId: 'mcp-tenant');

        self::assertSame('mcp-tenant', $ctx->getTenantId());
    }

    /**
     * AC 2 + AC 4: SwitchUserToken — A impersonates B.
     * getUserId() === B (the target), getActingUserId() === A (the physical actor).
     */
    public function testCreateWithSwitchUserTokenSetsDelegatedAndUserIds(): void
    {
        $request = $this->requestWithTenant('default');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->authChecker->method('isGranted')->willReturn(false);

        // B = impersonated user (target)
        $userB = $this->mockUser('bob');
        // A = physical actor (support staff)
        $userA = $this->mockUser('alice');

        $originalToken = $this->createMock(TokenInterface::class);
        $originalToken->method('getUser')->willReturn($userA);

        $switchToken = $this->createMock(SwitchUserToken::class);
        $switchToken->method('getUser')->willReturn($userB);
        $switchToken->method('getOriginalToken')->willReturn($originalToken);

        $this->tokenStorage->method('getToken')->willReturn($switchToken);

        $ctx = $this->provider()->create();

        // AC 2: isDelegated must be true
        self::assertTrue($ctx->isDelegated());
        // AC 4: getUserId() = B (impersonated), getActingUserId() = A (physical)
        self::assertSame('bob', $ctx->getUserId());
        self::assertSame('alice', $ctx->getActingUserId());
    }

    /**
     * Regular (non-delegated) token: userId === actingUserId, isDelegated=false.
     */
    public function testCreateWithRegularTokenNotDelegated(): void
    {
        $request = $this->requestWithTenant('default');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->authChecker->method('isGranted')->willReturn(false);

        $user = $this->mockUser('alice');
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->tokenStorage->method('getToken')->willReturn($token);

        $ctx = $this->provider()->create();

        self::assertFalse($ctx->isDelegated());
        self::assertSame('alice', $ctx->getUserId());
        self::assertSame('alice', $ctx->getActingUserId());
    }

    /**
     * AC 5: ROLE_MANAGER → isAclBypassed=true.
     */
    public function testCreateWithRoleManagerSetsAclBypassedTrue(): void
    {
        $request = $this->requestWithTenant('default');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->tokenStorage->method('getToken')->willReturn(null);
        $this->authChecker->method('isGranted')
            ->with('ROLE_MANAGER')
            ->willReturn(true);

        $ctx = $this->provider()->create();

        self::assertTrue($ctx->isAclBypassed());
    }

    /**
     * AC 5: ROLE_ADMIN without ROLE_MANAGER → isAclBypassed=false.
     *
     * ROLE_ADMIN = technical rights only. ROLE_ADMIN alone does NOT bypass domain ACL.
     */
    public function testCreateWithRoleAdminOnlyDoesNotBypassAcl(): void
    {
        $request = $this->requestWithTenant('default');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->tokenStorage->method('getToken')->willReturn(null);

        // isGranted('ROLE_MANAGER') returns false — operator has ROLE_ADMIN but NOT ROLE_MANAGER
        $this->authChecker->method('isGranted')
            ->with('ROLE_MANAGER')
            ->willReturn(false);

        $ctx = $this->provider()->create();

        self::assertFalse($ctx->isAclBypassed());
    }

    public function testCreateAnonymousIsNotAuthenticated(): void
    {
        $request = $this->requestWithTenant('default');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->tokenStorage->method('getToken')->willReturn(null);
        $this->authChecker->method('isGranted')->willReturn(false);

        $ctx = $this->provider()->create();

        self::assertFalse($ctx->isAuthenticated());
        self::assertNull($ctx->getUserId());
    }

    public function testCreateAuthenticatedUserIsAuthenticated(): void
    {
        $request = $this->requestWithTenant('default');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->authChecker->method('isGranted')->willReturn(false);

        $user = $this->mockUser('alice');
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $ctx = $this->provider()->create();

        self::assertTrue($ctx->isAuthenticated());
    }

    // ── CLI context ──────────────────────────────────────────────────────────

    /**
     * CLI MUST have isAclBypassed=false — no god mode, data governance must apply.
     *
     * Built-in plugins (Ping, Echo) are unaffected because they don't check any
     * ACL vector. But plugins that require a vector MUST fail on CLI if the vector
     * is not passed in --args.
     */
    public function testCreateForCliSetsAclBypassedFalse(): void
    {
        $ctx = $this->provider()->createForCli();

        self::assertFalse($ctx->isAclBypassed());
    }

    public function testCreateForCliIsNotAuthenticated(): void
    {
        $ctx = $this->provider()->createForCli();

        self::assertFalse($ctx->isAuthenticated());
        self::assertNull($ctx->getUserId());
        self::assertNull($ctx->getActingUserId());
    }

    public function testCreateForCliIsNotDelegated(): void
    {
        $ctx = $this->provider()->createForCli();

        self::assertFalse($ctx->isDelegated());
    }

    public function testCreateForCliUsesDefaultTenant(): void
    {
        $ctx = $this->provider()->createForCli();

        self::assertSame('default', $ctx->getTenantId());
    }

    public function testCreateForCliAllowsCustomTenant(): void
    {
        $ctx = $this->provider()->createForCli(tenant: 'shop-berlin');

        self::assertSame('shop-berlin', $ctx->getTenantId());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function provider(): ContextProvider
    {
        return new ContextProvider(
            requestStack: $this->requestStack,
            tokenStorage: $this->tokenStorage,
            authorizationChecker: $this->authChecker,
        );
    }

    private function requestWithTenant(string $tenant): Request
    {
        $request = Request::create('/api/v1/core/'.$tenant.'/system/ping/run', 'POST');
        $request->attributes->set('tenant', $tenant);

        return $request;
    }

    private function mockUser(string $identifier): UserInterface
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn($identifier);

        return $user;
    }
}
