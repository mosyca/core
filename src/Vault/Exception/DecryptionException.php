<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Exception;

/**
 * Thrown when a Vault ciphertext cannot be decrypted.
 *
 * The message is deliberately generic — it MUST NOT include the ciphertext,
 * plaintext, or any key material to prevent accidental secret leakage in
 * stack traces or JSON-RPC error responses (Security Rule V2).
 */
final class DecryptionException extends \RuntimeException
{
    public static function forCorruptPayload(): self
    {
        return new self('Vault payload is corrupt or has been tampered with.');
    }

    public static function forTruncatedPayload(): self
    {
        return new self('Vault payload is too short to be a valid ciphertext.');
    }
}
