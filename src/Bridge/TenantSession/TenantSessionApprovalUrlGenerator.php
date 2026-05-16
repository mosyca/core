<?php

declare(strict_types=1);

namespace Mosyca\Core\Bridge\TenantSession;

use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Generates cryptographically signed, time-limited tenant session approval URLs.
 *
 * The URL embeds the JTI as a path segment and is signed by UriSigner
 * (HMAC-SHA256 over %kernel.secret% + expiry timestamp). The human operator
 * opens this URL to approve or deny the tenant context switch.
 *
 * SECURITY (SR-OOB-2):
 * - The signed URL IS the authorisation credential — no separate session is needed.
 * - Short TTL (default 600 s = 10 min) mirrors the JWT expiry.
 * - TenantSessionApprovalController MUST verify the signature and return 403 on failure.
 * - The URL MUST only be transmitted over HTTPS in production.
 */
readonly class TenantSessionApprovalUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
        private int $ttlSeconds = 600,
    ) {
    }

    /**
     * Generate a signed approval URL for a pending tenant session.
     *
     * @param string $jti The JWT ID of the pending TenantSession
     */
    public function generate(string $jti): string
    {
        $url = $this->urlGenerator->generate(
            'mosyca_tenant_session_approve',
            ['jti' => $jti],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $expiry = new \DateTimeImmutable('+'.$this->ttlSeconds.' seconds');

        return $this->uriSigner->sign($url, $expiry);
    }
}
