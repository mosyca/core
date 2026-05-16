<?php

declare(strict_types=1);

namespace Mosyca\Core\Bridge\TenantSession;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Mosyca\Core\Action\ActionResult;

/**
 * Intercepts MCP tool calls that carry a `_mcp_context_token` argument.
 *
 * Called by McpExecutionService BEFORE tenant extraction. The interceptor either:
 * - Allows the call to continue (return null), optionally having overwritten
 *   `$arguments['tenant']` with the JWT-authorised tenant slug.
 * - Short-circuits the call (return array = ActionResult::toArray()) when the
 *   token is invalid, expired, DENIED, or PENDING (awaiting human approval).
 *
 * Security rules enforced:
 *   SR-OOB-1: JWT `exp` claim is validated by the Lexik decoder.
 *   SR-OOB-4: `_mcp_context_token` is stripped from $arguments before continuing.
 *   SR-OOB-5: `tenant_id` from JWT is authoritative — overwrites `arguments['tenant']`.
 *   SR-OOB-7: DENIED state is permanent — session cannot be re-approved.
 */
class TenantSessionInterceptor
{
    public function __construct(
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly TenantSessionRepository $repository,
        private readonly TenantSessionApprovalUrlGenerator $approvalUrlGenerator,
    ) {
    }

    /**
     * Inspect and optionally mutate MCP arguments before tenant extraction.
     *
     * @param array<string, mixed> $arguments MCP arguments (passed by reference — mutated on ACTIVE)
     *
     * @return array<string, mixed>|null null = continue (may have mutated $arguments['tenant']);
     *                                   array = ActionResult::toArray() — return immediately to caller
     */
    public function intercept(array &$arguments): ?array
    {
        // SR-OOB-4: No token present → pass-through, no vault or DB access.
        if (!\array_key_exists('_mcp_context_token', $arguments)) {
            return null;
        }

        $token = $arguments['_mcp_context_token'];

        // SR-OOB-4: Always strip the token — downstream action must never see it.
        unset($arguments['_mcp_context_token']);

        if (!\is_string($token) || '' === $token) {
            return ActionResult::failure(
                'Invalid tenant context token.',
                'OOBCA_INVALID_TOKEN',
                'Call core_system_assume_tenant to obtain a new tenant session token.',
            )->toArray();
        }

        // SR-OOB-1: Decode + validate JWT signature and exp claim.
        try {
            /** @var array<string, mixed> $payload */
            $payload = $this->jwtEncoder->decode($token);
        } catch (JWTDecodeFailureException $e) {
            if (JWTDecodeFailureException::EXPIRED_TOKEN === $e->getReason()) {
                return ActionResult::failure(
                    'Tenant context token has expired.',
                    'OOBCA_TOKEN_EXPIRED',
                    'Call core_system_assume_tenant to obtain a new tenant session token.',
                )->toArray();
            }

            return ActionResult::failure(
                'Tenant context token is invalid.',
                'OOBCA_INVALID_TOKEN',
                'Call core_system_assume_tenant to obtain a new tenant session token.',
            )->toArray();
        }

        $jti = \is_string($payload['jti'] ?? null) ? (string) $payload['jti'] : '';
        $tenantId = \is_string($payload['tenant_id'] ?? null) ? (string) $payload['tenant_id'] : '';

        if ('' === $jti || '' === $tenantId) {
            return ActionResult::failure(
                'Tenant context token is missing required claims.',
                'OOBCA_INVALID_TOKEN',
                'Call core_system_assume_tenant to obtain a new tenant session token.',
            )->toArray();
        }

        $session = $this->repository->findByJti($jti);

        if (null === $session) {
            return ActionResult::failure(
                'Tenant session not found.',
                'OOBCA_SESSION_NOT_FOUND',
                'Call core_system_assume_tenant to obtain a new tenant session token.',
            )->toArray();
        }

        // Belt-and-suspenders: DB-side expiry for PENDING sessions (JWT decoder already checked exp,
        // but we also guard against clock skew or a DB record that outlived the JWT somehow).
        if (TenantSessionState::PENDING === $session->getState() && $session->isExpired()) {
            return ActionResult::failure(
                'Tenant session has expired without approval.',
                'OOBCA_TOKEN_EXPIRED',
                'Call core_system_assume_tenant to obtain a new tenant session token.',
            )->toArray();
        }

        if (TenantSessionState::ACTIVE === $session->getState()) {
            // SR-OOB-5: JWT tenant_id is authoritative — overwrite caller-supplied tenant.
            $arguments['tenant'] = $tenantId;

            return null; // continue with mutated arguments
        }

        if (TenantSessionState::DENIED === $session->getState()) {
            return ActionResult::failure(
                \sprintf('Tenant session for "%s" was denied by the operator.', $tenantId),
                'OOBCA_SESSION_DENIED',
                'The operator denied this tenant context switch. Call core_system_assume_tenant to request a new session.',
            )->toArray();
        }

        // PENDING state — await human approval.
        return ActionResult::failure(
            \sprintf('Tenant session for "%s" is awaiting operator approval.', $tenantId),
            'OOBCA_APPROVAL_PENDING',
            \sprintf(
                'Ask the operator to open this URL to approve the tenant context switch: %s',
                $this->approvalUrlGenerator->generate($jti),
            ),
            [
                'approval_url' => $this->approvalUrlGenerator->generate($jti),
                'jti' => $jti,
                'tenant' => $tenantId,
            ],
        )->toArray();
    }
}
