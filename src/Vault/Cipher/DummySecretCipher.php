<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Cipher;

use Mosyca\Core\Vault\Exception\DecryptionException;

/**
 * Test-only cipher: base64 encode/decode.
 *
 * @internal This adapter is for unit-test isolation ONLY (Vault Rule V3).
 *           It provides NO security. Never register it in a production DI container.
 *           Use SodiumSecretCipher in all non-test environments.
 */
final class DummySecretCipher implements SecretCipherInterface
{
    public function encrypt(string $plaintext): string
    {
        return base64_encode($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        $result = base64_decode($ciphertext, strict: true);

        if (false === $result) {
            throw DecryptionException::forCorruptPayload();
        }

        return $result;
    }
}
