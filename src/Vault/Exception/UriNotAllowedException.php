<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Exception;

/**
 * Thrown when VaultAwareHttpClient refuses to inject a Bearer token
 * because the target URI is not in the configured allowlist for the integration.
 *
 * This is a programming / configuration error (LogicException), not a runtime error.
 * It must be fixed by adding the correct base URI to the integration's allowlist
 * in the application configuration (Vault Rule V5).
 *
 * The exception message intentionally includes the target URI — URIs are not secrets.
 * The token value is NEVER included in this or any other Vault exception.
 */
final class UriNotAllowedException extends \LogicException
{
    /**
     * The target URI is not in the allowed list (or the list is empty / missing).
     */
    public static function forUri(string $uri, string $integration): self
    {
        return new self(\sprintf(
            'URI "%s" is not in the allowed base URI list for integration "%s". '
            .'Token injection refused (Vault Rule V5). '
            .'Add the base URI to the allowlist configuration for this integration.',
            $uri,
            $integration,
        ));
    }
}
