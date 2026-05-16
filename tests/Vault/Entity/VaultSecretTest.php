<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Entity;

use Mosyca\Core\Vault\Entity\VaultSecret;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mosyca\Core\Vault\Entity\VaultSecret
 */
final class VaultSecretTest extends TestCase
{
    // ── construction ─────────────────────────────────────────────────────────

    public function testConstructorSetsFields(): void
    {
        $secret = new VaultSecret(
            tenantId: 'tenant-1',
            integrationType: 'shopware6',
            credentialPayload: 'encrypted-payload',
        );

        self::assertSame('tenant-1', $secret->getTenantId());
        self::assertSame('shopware6', $secret->getIntegrationType());
        self::assertSame('encrypted-payload', $secret->getCredentialPayload());
        self::assertNull($secret->getUserId());
        self::assertNull($secret->getExpiresAt());
        self::assertNull($secret->getId());
    }

    public function testConstructorWithOptionalFields(): void
    {
        $expiresAt = new \DateTimeImmutable('+1 year');
        $secret = new VaultSecret(
            tenantId: 'tenant-2',
            integrationType: 'spotify',
            credentialPayload: 'encrypted-oauth-tokens',
            userId: 'user-karim',
            expiresAt: $expiresAt,
        );

        self::assertSame('user-karim', $secret->getUserId());
        self::assertSame($expiresAt, $secret->getExpiresAt());
    }

    public function testConstructorSetsTimestamps(): void
    {
        $before = new \DateTimeImmutable();
        $secret = new VaultSecret('t', 'integration', 'payload');
        $after = new \DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $secret->getCreatedAt());
        self::assertLessThanOrEqual($after, $secret->getCreatedAt());
        self::assertGreaterThanOrEqual($before, $secret->getUpdatedAt());
        self::assertLessThanOrEqual($after, $secret->getUpdatedAt());
    }

    // ── isExpired ─────────────────────────────────────────────────────────────

    public function testIsExpiredReturnsFalseWhenNoExpirySet(): void
    {
        $secret = new VaultSecret('t', 'integration', 'payload');
        self::assertFalse($secret->isExpired());
    }

    public function testIsExpiredReturnsFalseForFutureExpiry(): void
    {
        $secret = new VaultSecret(
            'tenant', 'integration', 'payload',
            expiresAt: new \DateTimeImmutable('+1 day'),
        );

        self::assertFalse($secret->isExpired());
    }

    public function testIsExpiredReturnsTrueForPastExpiry(): void
    {
        $secret = new VaultSecret(
            'tenant', 'integration', 'payload',
            expiresAt: new \DateTimeImmutable('-1 second'),
        );

        self::assertTrue($secret->isExpired());
    }

    // ── updatePayload ─────────────────────────────────────────────────────────

    public function testUpdatePayloadChangesCredentialPayload(): void
    {
        $secret = new VaultSecret('t', 'integration', 'old-payload');
        $secret->updatePayload('new-payload');

        self::assertSame('new-payload', $secret->getCredentialPayload());
    }

    public function testUpdatePayloadUpdatesTimestamp(): void
    {
        $secret = new VaultSecret('t', 'integration', 'payload');
        $originalUpdatedAt = $secret->getUpdatedAt();

        // Small sleep to ensure timestamp advances (DateTimeImmutable has microsecond precision).
        usleep(1000);
        $secret->updatePayload('new-payload');

        self::assertGreaterThan($originalUpdatedAt, $secret->getUpdatedAt());
    }

    public function testUpdatePayloadSetsNewExpiryWhenProvided(): void
    {
        $secret = new VaultSecret('t', 'integration', 'payload');
        $newExpiry = new \DateTimeImmutable('+30 days');

        $secret->updatePayload('new-payload', $newExpiry);

        self::assertSame($newExpiry, $secret->getExpiresAt());
    }

    public function testUpdatePayloadPreservesExistingExpiryWhenNullPassed(): void
    {
        $originalExpiry = new \DateTimeImmutable('+1 year');
        $secret = new VaultSecret('t', 'integration', 'payload', expiresAt: $originalExpiry);

        $secret->updatePayload('new-payload', null);

        // Passing null must NOT clear the existing expiry — it means "keep what's there".
        self::assertSame($originalExpiry, $secret->getExpiresAt());
    }
}
