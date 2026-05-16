<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Cipher;

use Mosyca\Core\Vault\Cipher\SodiumSecretCipher;
use Mosyca\Core\Vault\Exception\DecryptionException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mosyca\Core\Vault\Cipher\SodiumSecretCipher
 *
 * Uses a hardcoded 32-byte test key — NOT the production MOSYCA_VAULT_MASTER_KEY
 * environment variable (Vault Rule V3 / QA Rule 2: Deterministic Isolation).
 */
final class SodiumSecretCipherTest extends TestCase
{
    /** Hardcoded 32-byte key (64 hex chars) — test use only, not a real secret. */
    private const TEST_KEY_HEX = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

    private SodiumSecretCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new SodiumSecretCipher(self::TEST_KEY_HEX);
    }

    // ── round-trip ────────────────────────────────────────────────────────────

    public function testEncryptDecryptRoundtrip(): void
    {
        $plaintext = 'shopware-api-key-supersecret-1234';
        $encrypted = $this->cipher->encrypt($plaintext);

        self::assertSame($plaintext, $this->cipher->decrypt($encrypted));
    }

    public function testEncryptDecryptEmptyString(): void
    {
        $encrypted = $this->cipher->encrypt('');
        self::assertSame('', $this->cipher->decrypt($encrypted));
    }

    public function testEncryptDecryptJsonPayload(): void
    {
        $payload = '{"access_token":"tok_abc","refresh_token":"ref_xyz","expires_in":3600}';
        $encrypted = $this->cipher->encrypt($payload);

        self::assertSame($payload, $this->cipher->decrypt($encrypted));
    }

    // ── nonce uniqueness ──────────────────────────────────────────────────────

    public function testEncryptProducesDifferentCiphertextOnEachCall(): void
    {
        $plaintext = 'same plaintext';
        $first = $this->cipher->encrypt($plaintext);
        $second = $this->cipher->encrypt($plaintext);

        // Each call generates a fresh nonce → ciphertexts must differ.
        self::assertNotSame($first, $second);
    }

    // ── ciphertext is opaque base64 ───────────────────────────────────────────

    public function testEncryptedOutputIsBase64(): void
    {
        $encrypted = $this->cipher->encrypt('test');
        self::assertNotFalse(base64_decode($encrypted, strict: true));
    }

    public function testEncryptedOutputDoesNotContainPlaintext(): void
    {
        $plaintext = 'supersecret';
        $encrypted = $this->cipher->encrypt($plaintext);
        self::assertStringNotContainsString($plaintext, $encrypted);
    }

    // ── tampered / corrupt ciphertext ─────────────────────────────────────────

    public function testDecryptThrowsOnTamperedCiphertext(): void
    {
        $encrypted = $this->cipher->encrypt('original value');
        // Flip a byte in the middle of the ciphertext to break MAC verification.
        $raw = base64_decode($encrypted, strict: true);
        \assert(false !== $raw);
        $tampered = $raw;
        $tampered[\strlen($tampered) - 1] = \chr(\ord($tampered[\strlen($tampered) - 1]) ^ 0xFF);
        $tamperedBase64 = base64_encode($tampered);

        $this->expectException(DecryptionException::class);
        $this->cipher->decrypt($tamperedBase64);
    }

    public function testDecryptThrowsOnInvalidBase64(): void
    {
        $this->expectException(DecryptionException::class);
        $this->cipher->decrypt('!!!not-base64!!!');
    }

    public function testDecryptThrowsOnTruncatedPayload(): void
    {
        // A payload shorter than nonce (24) + MAC (16) = 40 bytes must be rejected.
        $tooShort = base64_encode('short');

        $this->expectException(DecryptionException::class);
        $this->cipher->decrypt($tooShort);
    }

    public function testDecryptThrowsOnEmptyCiphertext(): void
    {
        $this->expectException(DecryptionException::class);
        $this->cipher->decrypt('');
    }

    // ── constructor validation ────────────────────────────────────────────────

    public function testConstructorThrowsOnWrongKeyLength(): void
    {
        // 31 bytes (62 hex chars) is invalid.
        $shortKey = str_repeat('ab', 31);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('32 bytes');

        new SodiumSecretCipher($shortKey);
    }

    public function testConstructorThrowsOnTooLongKey(): void
    {
        // 33 bytes (66 hex chars) is also invalid.
        $longKey = str_repeat('ab', 33);

        $this->expectException(\InvalidArgumentException::class);

        new SodiumSecretCipher($longKey);
    }
}
