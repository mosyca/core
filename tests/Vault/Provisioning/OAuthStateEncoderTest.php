<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Provisioning;

use Mosyca\Core\Vault\Provisioning\OAuthStateEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuthStateEncoder::class)]
final class OAuthStateEncoderTest extends TestCase
{
    private const string TEST_SECRET = 'test-hmac-secret-for-unit-tests-only';

    private OAuthStateEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new OAuthStateEncoder(self::TEST_SECRET);
    }

    #[Test]
    public function encodeDecodeRoundtripPreservesAllFields(): void
    {
        $state = $this->encoder->encode('spotify', 'tenant-abc', 'user-42', 3_600);

        $ctx = $this->encoder->decode($state);

        self::assertSame('spotify', $ctx['integration']);
        self::assertSame('tenant-abc', $ctx['tenant_id']);
        self::assertSame('user-42', $ctx['user_id']);
    }

    #[Test]
    public function encodeDecodeRoundtripWithNullUserId(): void
    {
        $state = $this->encoder->encode('shopware6', 'tenant-x', null, 3_600);

        $ctx = $this->encoder->decode($state);

        self::assertSame('shopware6', $ctx['integration']);
        self::assertSame('tenant-x', $ctx['tenant_id']);
        self::assertNull($ctx['user_id']);
    }

    #[Test]
    public function encodeDecodeRoundtripWithEmptyUserIdTreatedAsNull(): void
    {
        // Empty string userId is stored as-is in the JSON; decode normalises '' → null.
        $payload = json_encode([
            'integration' => 'spotify',
            'tenant_id' => 'tenant-1',
            'user_id' => '',
            'exp' => time() + 3_600,
        ], \JSON_THROW_ON_ERROR);

        $hmac = hash_hmac('sha256', $payload, self::TEST_SECRET);
        $state = $this->base64urlEncode($payload).'.'.$this->base64urlEncode($hmac);

        $ctx = $this->encoder->decode($state);

        self::assertNull($ctx['user_id']);
    }

    #[Test]
    public function tamperedPayloadThrowsInvalidArgumentException(): void
    {
        $state = $this->encoder->encode('spotify', 'tenant-abc', null, 3_600);

        // Flip one character in the payload portion.
        $parts = explode('.', $state, 2);
        $tampered = substr_replace($parts[0], 'X', 5, 1).'.'.$parts[1];

        $this->expectException(\InvalidArgumentException::class);
        $this->encoder->decode($tampered);
    }

    #[Test]
    public function tamperedHmacThrowsInvalidArgumentException(): void
    {
        $state = $this->encoder->encode('spotify', 'tenant-abc', null, 3_600);

        // Flip one character in the HMAC portion.
        $dotPos = strrpos($state, '.');
        $tamperedHmac = substr_replace(substr($state, $dotPos + 1), 'X', 5, 1);
        $tampered = substr($state, 0, $dotPos + 1).$tamperedHmac;

        $this->expectException(\InvalidArgumentException::class);
        $this->encoder->decode($tampered);
    }

    #[Test]
    public function expiredStateThrowsInvalidArgumentException(): void
    {
        // Encode with a 1-second TTL and then manually backdate the exp field.
        $payload = json_encode([
            'integration' => 'spotify',
            'tenant_id' => 'tenant-abc',
            'user_id' => null,
            'exp' => time() - 1, // already expired
        ], \JSON_THROW_ON_ERROR);

        $hmac = hash_hmac('sha256', $payload, self::TEST_SECRET);
        $state = $this->base64urlEncode($payload).'.'.$this->base64urlEncode($hmac);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expired');
        $this->encoder->decode($state);
    }

    #[Test]
    public function missingDotSeparatorThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->encoder->decode('nodothere');
    }

    #[Test]
    public function malformedBase64ThrowsInvalidArgumentException(): void
    {
        // Build a state with invalid base64url in the payload part.
        $this->expectException(\InvalidArgumentException::class);
        $this->encoder->decode('!!!invalid!!!.!!!invalid!!!');
    }

    #[Test]
    public function stateSignedWithDifferentSecretThrowsInvalidArgumentException(): void
    {
        $otherEncoder = new OAuthStateEncoder('completely-different-secret');
        $state = $otherEncoder->encode('spotify', 'tenant-abc', null, 3_600);

        $this->expectException(\InvalidArgumentException::class);
        $this->encoder->decode($state);
    }

    #[Test]
    public function missingIntegrationFieldThrowsInvalidArgumentException(): void
    {
        $payload = json_encode([
            'tenant_id' => 'tenant-abc',
            'user_id' => null,
            'exp' => time() + 3_600,
        ], \JSON_THROW_ON_ERROR);

        $hmac = hash_hmac('sha256', $payload, self::TEST_SECRET);
        $state = $this->base64urlEncode($payload).'.'.$this->base64urlEncode($hmac);

        $this->expectException(\InvalidArgumentException::class);
        $this->encoder->decode($state);
    }

    #[Test]
    public function stateMustContainForwardOnlyOneDecimalPointSplit(): void
    {
        // A valid state that contains extra dots in the HMAC is still parseable
        // because we split on the FIRST dot only.
        $state = $this->encoder->encode('spotify', 'tenant.with.dots', null, 3_600);

        $ctx = $this->encoder->decode($state);
        self::assertSame('tenant.with.dots', $ctx['tenant_id']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
