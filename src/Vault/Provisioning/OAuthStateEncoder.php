<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Provisioning;

/**
 * Encodes and verifies the OAuth `state` parameter used for CSRF protection.
 *
 * Wire format:  base64url(json_payload) . "." . base64url(hmac_sha256)
 *
 * The JSON payload contains:
 *   - integration : integration type (e.g. 'spotify')
 *   - tenant_id   : tenant identifier
 *   - user_id     : user identifier, or null for tenant-level M2M credentials
 *   - exp         : UNIX timestamp at which the state expires
 *
 * SECURITY:
 * - hash_equals() is used for HMAC verification (constant-time — prevents timing attacks).
 * - The kernel.secret is the HMAC key; it is never logged or exposed.
 * - Expiry is embedded in the signed payload — replay after expiry is rejected.
 * - Exception messages must NOT include the state value or its HMAC (Vault Rule V2).
 */
final readonly class OAuthStateEncoder
{
    public function __construct(
        private string $secret,
    ) {
    }

    /**
     * Encode the provisioning context as a signed OAuth state string.
     *
     * @param int $ttlSeconds Lifetime in seconds (default: 3 600 = 1 hour)
     */
    public function encode(
        string $integration,
        string $tenantId,
        ?string $userId,
        int $ttlSeconds = 3_600,
    ): string {
        $payload = json_encode([
            'integration' => $integration,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'exp' => time() + $ttlSeconds,
        ], \JSON_THROW_ON_ERROR);

        $hmac = hash_hmac('sha256', $payload, $this->secret);

        return $this->base64urlEncode($payload).'.'.$this->base64urlEncode($hmac);
    }

    /**
     * Decode and verify a signed OAuth state string.
     *
     * @return array{integration: string, tenant_id: string, user_id: string|null}
     *
     * @throws \InvalidArgumentException if the state is malformed, tampered, or expired
     */
    public function decode(string $state): array
    {
        $dotPos = strpos($state, '.');

        if (false === $dotPos) {
            throw new \InvalidArgumentException('OAuth state is malformed.');
        }

        $rawPayload = $this->base64urlDecode(substr($state, 0, $dotPos));
        $rawHmac = $this->base64urlDecode(substr($state, $dotPos + 1));

        if (false === $rawPayload || false === $rawHmac) {
            throw new \InvalidArgumentException('OAuth state is malformed.');
        }

        $expectedHmac = hash_hmac('sha256', $rawPayload, $this->secret);

        // Constant-time comparison prevents timing-based HMAC oracle attacks.
        if (!hash_equals($expectedHmac, $rawHmac)) {
            throw new \InvalidArgumentException('OAuth state signature is invalid.');
        }

        $data = json_decode($rawPayload, true);

        if (!\is_array($data)) {
            throw new \InvalidArgumentException('OAuth state payload is malformed.');
        }

        $exp = $data['exp'] ?? null;

        if (!\is_int($exp) || $exp < time()) {
            throw new \InvalidArgumentException('OAuth state has expired.');
        }

        $integration = \is_string($data['integration'] ?? null) ? (string) $data['integration'] : '';
        $tenantId = \is_string($data['tenant_id'] ?? null) ? (string) $data['tenant_id'] : '';
        $rawUserId = $data['user_id'] ?? null;
        $userId = \is_string($rawUserId) && '' !== $rawUserId ? $rawUserId : null;

        if ('' === $integration || '' === $tenantId) {
            throw new \InvalidArgumentException('OAuth state payload is missing required fields.');
        }

        return [
            'integration' => $integration,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ];
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return string|false Returns false on invalid base64url input
     */
    private function base64urlDecode(string $data): string|false
    {
        $padLen = (4 - \strlen($data) % 4) % 4;
        $padded = $data.str_repeat('=', $padLen);

        return base64_decode(strtr($padded, '-_', '+/'), true);
    }
}
