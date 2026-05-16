<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Provisioning;

use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Generates cryptographically signed, time-limited out-of-band provisioning URLs.
 *
 * The URL encodes the (integration, tenant_id, user_id) context as query parameters
 * and signs the entire URI with Symfony's UriSigner (HMAC-SHA256 over %kernel.secret%).
 * Tampering with any parameter invalidates the signature; the URL expires after ttlSeconds.
 *
 * SECURITY:
 * - The signed URL IS the authorisation credential — no separate JWT or session is required.
 * - Short TTL (default 24 h) limits the replay window.
 * - Single-use enforcement (nonce tracking) is deferred to V0.14e. Within the TTL,
 *   the same link can be used to overwrite credentials for that (integration, tenant) context.
 * - The URL MUST only be transmitted over HTTPS in production (deployment concern).
 */
final readonly class ProvisioningLinkGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    /**
     * Generate a signed, time-limited URL for POST /api/vault/provision.
     *
     * @param int $ttlSeconds Lifetime in seconds (default: 86 400 = 24 hours)
     */
    public function generate(
        string $integration,
        string $tenantId,
        ?string $userId = null,
        int $ttlSeconds = 86_400,
    ): string {
        $params = [
            'integration' => $integration,
            'tenant_id' => $tenantId,
        ];

        if (null !== $userId) {
            $params['user_id'] = $userId;
        }

        $url = $this->urlGenerator->generate(
            'mosyca_vault_provision',
            $params,
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $expiry = new \DateTimeImmutable('+'.$ttlSeconds.' seconds');

        return $this->uriSigner->sign($url, $expiry);
    }
}
