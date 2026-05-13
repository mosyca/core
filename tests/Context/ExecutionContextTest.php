<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Context;

use Mosyca\Core\Context\ExecutionContext;
use Mosyca\Core\Context\ExecutionContextInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExecutionContext value object.
 */
final class ExecutionContextTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: null,
            actingUserId: null,
            delegated: false,
            authenticated: false,
            aclBypassed: false,
        );

        self::assertInstanceOf(ExecutionContextInterface::class, $ctx);
    }

    public function testGetTenantId(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'shop-berlin',
            userId: null,
            actingUserId: null,
            delegated: false,
            authenticated: false,
            aclBypassed: false,
        );

        self::assertSame('shop-berlin', $ctx->getTenantId());
    }

    public function testGetUserId(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: 'alice',
            actingUserId: 'alice',
            delegated: false,
            authenticated: true,
            aclBypassed: false,
        );

        self::assertSame('alice', $ctx->getUserId());
    }

    public function testGetUserIdNullWhenUnauthenticated(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: null,
            actingUserId: null,
            delegated: false,
            authenticated: false,
            aclBypassed: false,
        );

        self::assertNull($ctx->getUserId());
    }

    public function testGetActingUserId(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: 'bob',
            actingUserId: 'alice',
            delegated: true,
            authenticated: true,
            aclBypassed: false,
        );

        self::assertSame('alice', $ctx->getActingUserId());
    }

    public function testIsDelegatedFalseByDefault(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: 'alice',
            actingUserId: 'alice',
            delegated: false,
            authenticated: true,
            aclBypassed: false,
        );

        self::assertFalse($ctx->isDelegated());
    }

    public function testIsDelegatedTrueWhenImpersonating(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: 'bob',       // target (impersonated)
            actingUserId: 'alice', // physical actor
            delegated: true,
            authenticated: true,
            aclBypassed: false,
        );

        self::assertTrue($ctx->isDelegated());
    }

    public function testIsAuthenticatedFalseForAnonymous(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: null,
            actingUserId: null,
            delegated: false,
            authenticated: false,
            aclBypassed: false,
        );

        self::assertFalse($ctx->isAuthenticated());
    }

    public function testIsAuthenticatedTrueForLoggedInUser(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: 'alice',
            actingUserId: 'alice',
            delegated: false,
            authenticated: true,
            aclBypassed: false,
        );

        self::assertTrue($ctx->isAuthenticated());
    }

    public function testIsAclBypassedFalseByDefault(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: 'alice',
            actingUserId: 'alice',
            delegated: false,
            authenticated: true,
            aclBypassed: false,
        );

        self::assertFalse($ctx->isAclBypassed());
    }

    public function testIsAclBypassedTrueForManager(): void
    {
        $ctx = new ExecutionContext(
            tenantId: 'default',
            userId: 'mgr',
            actingUserId: 'mgr',
            delegated: false,
            authenticated: true,
            aclBypassed: true,
        );

        self::assertTrue($ctx->isAclBypassed());
    }
}
