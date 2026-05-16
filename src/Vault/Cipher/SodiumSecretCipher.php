<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Cipher;

use Mosyca\Core\Vault\Exception\DecryptionException;

/**
 * Production cipher: libsodium secretbox (XSalsa20-Poly1305).
 *
 * Wire format:  base64( nonce(24 bytes) || ciphertext || MAC(16 bytes) )
 *
 * The master key is a 32-byte binary string injected as a DI parameter.
 * In practice it is read from the environment variable MOSYCA_VAULT_MASTER_KEY
 * (hex-encoded 64 characters → 32 bytes) and decoded here.
 *
 * SECURITY NOTES:
 *   - A fresh cryptographically-random nonce is generated on every encrypt() call.
 *     Never reuse a nonce with the same key.
 *   - The master key is held only in this private property.
 *     Do NOT log it, serialize this object, or include it in error messages.
 *   - DecryptionException messages are deliberately generic (no payload echoed back).
 */
final class SodiumSecretCipher implements SecretCipherInterface
{
    private string $binaryKey;

    /**
     * @param string $masterKey Hex-encoded 32-byte master key (64 hex chars)
     *                          from env MOSYCA_VAULT_MASTER_KEY
     *
     * @throws \InvalidArgumentException if the decoded key is not exactly 32 bytes
     */
    public function __construct(string $masterKey)
    {
        $decoded = sodium_hex2bin($masterKey);

        if (\SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($decoded)) {
            throw new \InvalidArgumentException(\sprintf('MOSYCA_VAULT_MASTER_KEY must decode to exactly %d bytes; got %d.', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, \strlen($decoded)));
        }

        $this->binaryKey = $decoded;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $this->binaryKey);

        return base64_encode($nonce.$box);
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, strict: true);

        if (false === $raw) {
            throw DecryptionException::forCorruptPayload();
        }

        $minLength = \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES;

        if (\strlen($raw) < $minLength) {
            throw DecryptionException::forTruncatedPayload();
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($box, $nonce, $this->binaryKey);

        if (false === $plaintext) {
            throw DecryptionException::forCorruptPayload();
        }

        return $plaintext;
    }
}
