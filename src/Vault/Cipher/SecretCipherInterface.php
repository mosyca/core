<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Cipher;

use Mosyca\Core\Vault\Exception\DecryptionException;

/**
 * Contract for symmetric encryption/decryption of Vault secrets.
 *
 * Implementations MUST be stateless with respect to plaintext.
 * The encrypted output is a self-contained, opaque string that includes
 * all data (nonce, MAC, ciphertext) needed for decryption.
 *
 * Production implementation: SodiumSecretCipher (libsodium secretbox).
 * Test implementation:       DummySecretCipher  (base64 — deterministic, no key).
 */
interface SecretCipherInterface
{
    /**
     * Encrypt a plaintext string.
     *
     * Returns a self-contained opaque string (nonce prepended to ciphertext, base64-encoded).
     * Each call MUST produce a different ciphertext due to a fresh random nonce.
     *
     * @throws \RuntimeException if encryption fails due to an internal error
     */
    public function encrypt(string $plaintext): string;

    /**
     * Decrypt a previously encrypted string.
     *
     * @throws DecryptionException if the ciphertext is corrupt, truncated, or MAC verification fails
     */
    public function decrypt(string $ciphertext): string;
}
