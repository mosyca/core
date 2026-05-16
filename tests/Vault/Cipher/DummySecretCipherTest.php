<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Cipher;

use Mosyca\Core\Vault\Cipher\DummySecretCipher;
use Mosyca\Core\Vault\Exception\DecryptionException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mosyca\Core\Vault\Cipher\DummySecretCipher
 */
final class DummySecretCipherTest extends TestCase
{
    private DummySecretCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new DummySecretCipher();
    }

    // ── round-trip ────────────────────────────────────────────────────────────

    public function testEncryptDecryptRoundtrip(): void
    {
        $plaintext = 'api-key-abc-123';
        self::assertSame($plaintext, $this->cipher->decrypt($this->cipher->encrypt($plaintext)));
    }

    public function testEncryptDecryptEmptyString(): void
    {
        self::assertSame('', $this->cipher->decrypt($this->cipher->encrypt('')));
    }

    public function testEncryptDecryptJsonPayload(): void
    {
        $payload = '{"access_token":"tok","refresh_token":"ref"}';
        self::assertSame($payload, $this->cipher->decrypt($this->cipher->encrypt($payload)));
    }

    // ── deterministic output ─────────────────────────────────────────────────

    public function testEncryptIsDeterministic(): void
    {
        // DummySecretCipher produces the same output for the same input (base64 is deterministic).
        $plaintext = 'same input';
        self::assertSame($this->cipher->encrypt($plaintext), $this->cipher->encrypt($plaintext));
    }

    public function testEncryptOutputIsBase64(): void
    {
        $encrypted = $this->cipher->encrypt('test value');
        self::assertNotFalse(base64_decode($encrypted, strict: true));
    }

    // ── corrupt input ─────────────────────────────────────────────────────────

    public function testDecryptThrowsOnInvalidBase64(): void
    {
        $this->expectException(DecryptionException::class);
        $this->cipher->decrypt('!!!not-valid-base64!!!');
    }
}
