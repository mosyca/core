<?php

declare(strict_types=1);

namespace Mosyca\Core\Context;

/**
 * Immutable execution context passed to every plugin execute() call.
 *
 * This interface is the ONLY bridge between Mosyca core infrastructure
 * and the plugin's business logic. Plugins must not import Symfony classes.
 *
 * Created by ContextProvider — the single component that knows about
 * HTTP requests, Symfony Security tokens, and transport specifics.
 *
 * Three context origins:
 *   - HTTP/REST: tenant from route attribute, user from Symfony token
 *   - MCP stdio: tenant from tool payload ($mcpTenantId argument)
 *   - CLI:       tenant='default', no user, isAclBypassed=false (data governance)
 */
interface ExecutionContextInterface
{
    /**
     * The active tenant identifier.
     *
     * - HTTP: derived from route attribute {tenant}
     * - MCP:  passed via tool payload
     * - CLI:  'default' unless overridden via --tenant option
     */
    public function getTenantId(): string;

    /**
     * The target user identifier (who the action is performed FOR).
     *
     * For impersonation (SwitchUser), this is the impersonated user (B).
     * For normal requests, this equals getActingUserId().
     * For CLI, this is null (no user context).
     */
    public function getUserId(): ?string;

    /**
     * The physical user identifier (who actually made the request — for audit).
     *
     * For impersonation (SwitchUser), this is the support staff member (A).
     * For normal requests, this equals getUserId().
     * For CLI, this is null.
     */
    public function getActingUserId(): ?string;

    /**
     * Whether this request is delegated (impersonation active).
     *
     * true  → getUserId() !== getActingUserId() (Symfony SwitchUser active)
     * false → direct request, no impersonation
     */
    public function isDelegated(): bool;

    /**
     * Whether the request was made by an authenticated user.
     *
     * false for anonymous HTTP requests and all CLI calls.
     */
    public function isAuthenticated(): bool;

    /**
     * Whether domain-level ACL vector checks should be skipped.
     *
     * true  → user has ROLE_MANAGER; plugin MUST skip its vector check
     * false → plugin MUST perform its domain ACL check (e.g. validate PIN)
     *
     * This is NEVER true for CLI contexts (createForCli()) — data governance
     * must apply regardless of transport.
     */
    public function isAclBypassed(): bool;
}
