<?php

declare(strict_types=1);

namespace Mosyca\Core\Context;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The ONLY component that knows about HTTP requests and Symfony Security.
 *
 * Bridges three transport contexts into a uniform ExecutionContextInterface
 * that plugins can consume without any Symfony dependencies.
 *
 * Transport origins:
 *   - HTTP/REST: tenant from route attribute {tenant}, user from security token
 *   - MCP stdio: no HTTP request; tenant provided via $mcpTenantId argument from tool payload
 *   - CLI:       no user, no HTTP, isAclBypassed=false (data governance must always apply)
 *
 * GBAC roles derived from Operator::getRoles():
 *   ROLE_MANAGER → isAclBypassed=true  (data bypass, no admin rights)
 *   ROLE_ADMIN   → isAclBypassed=false (technical rights only)
 */
class ContextProvider
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    /**
     * Build context for HTTP (REST) or MCP (stdio) transport.
     *
     * For HTTP: reads tenant from route attribute 'tenant'.
     * For MCP:  pass $mcpTenantId from the tool call payload (no HTTP request exists).
     *
     * @throws \RuntimeException when tenant cannot be determined
     */
    public function create(?string $mcpTenantId = null): ExecutionContextInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request) {
            $tenantId = $request->attributes->get('tenant');
            if (!\is_string($tenantId) || '' === $tenantId) {
                throw new \RuntimeException('Cannot determine tenant: route attribute "tenant" is missing or empty. Ensure the route pattern includes {tenant} and it is set by the router.');
            }
        } elseif (null !== $mcpTenantId && '' !== $mcpTenantId) {
            $tenantId = $mcpTenantId;
        } else {
            throw new \RuntimeException('Cannot determine tenant: no HTTP request is active and no mcpTenantId was provided. For MCP stdio transport, pass the tenant from the tool payload.');
        }

        // — Resolve user identity from Symfony Security token —
        $token = $this->tokenStorage?->getToken();
        $isDelegated = false;
        $userId = null;
        $actingUserId = null;

        if (null !== $token) {
            $user = $token->getUser();
            $userId = $user?->getUserIdentifier();

            if ($token instanceof SwitchUserToken) {
                // Impersonation active: token holds the impersonated user (B),
                // original token holds the physical actor (A).
                $isDelegated = true;
                $actingUserId = $token->getOriginalToken()->getUser()?->getUserIdentifier();
            } else {
                // Normal request: acting user = target user
                $actingUserId = $userId;
            }
        }

        $isAuthenticated = null !== $token && null !== $userId;

        // isAclBypassed = ROLE_MANAGER (data bypass).
        // ROLE_ADMIN alone does NOT bypass domain ACL (only technical rights).
        // When AuthorizationChecker is not available (no SecurityBundle) → false.
        $isAclBypassed = null !== $this->authorizationChecker
            && $this->authorizationChecker->isGranted('ROLE_MANAGER');

        return new ExecutionContext(
            tenantId: $tenantId,
            userId: $userId,
            actingUserId: $actingUserId,
            delegated: $isDelegated,
            authenticated: $isAuthenticated,
            aclBypassed: $isAclBypassed,
        );
    }

    /**
     * Build context for CLI transport (bin/console).
     *
     * Deliberately sets isAclBypassed=false — CLI is NOT a god-mode bypass.
     * Cron jobs, workers, and support staff use CLI and must respect data governance.
     *
     * Plugins that check an ACL vector (e.g. PIN, ZIP, token) WILL FAIL on CLI
     * if the vector is not passed via --args. This is intentional behaviour.
     * Built-in plugins (Ping, Echo) are unaffected as they check no vector.
     */
    public function createForCli(string $tenant = 'default'): ExecutionContextInterface
    {
        return new ExecutionContext(
            tenantId: $tenant,
            userId: null,
            actingUserId: null,
            delegated: false,
            authenticated: false,
            aclBypassed: false, // Never bypass ACL on CLI — data governance must apply
        );
    }
}
