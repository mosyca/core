<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Builtin;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Bridge\TenantSession\TenantSession;
use Mosyca\Core\Bridge\TenantSession\TenantSessionApprovalUrlGenerator;
use Mosyca\Core\Bridge\TenantSession\TenantSessionRepository;
use Mosyca\Core\Context\ExecutionContextInterface;

/**
 * Out-of-Band Context Authorization (OOB-CA) session initiator.
 *
 * Initiates a human-gated tenant context switch. Issues a short-lived JWT
 * backed by a PENDING TenantSession DB record. The JWT must be passed as
 * `_mcp_context_token` in subsequent MCP tool calls — TenantSessionInterceptor
 * will block them until a human approves the session via the `approval_url`.
 *
 * ## Three-phase flow
 *
 *   Phase 1 — Call assume_tenant:
 *     Action creates PENDING session, returns _mcp_context_token + approval_url.
 *
 *   Phase 2 — Human approval:
 *     Operator opens approval_url in browser, clicks Approve or Deny.
 *     DB state transitions to ACTIVE or DENIED.
 *
 *   Phase 3 — Subsequent tool calls:
 *     Claude passes _mcp_context_token → interceptor validates JWT + DB state.
 *     ACTIVE: arguments['tenant'] is overwritten with JWT tenant_id → action proceeds.
 *     PENDING/DENIED: early return, action does not run.
 *
 * @see TenantSessionInterceptor
 * @see TenantSession
 */
#[AsAction]
final class AssumeTenantAction implements ActionInterface
{
    use ActionTrait;

    private const int TTL_SECONDS = 600; // 10 minutes

    public function __construct(
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly TenantSessionRepository $repository,
        private readonly TenantSessionApprovalUrlGenerator $approvalUrlGenerator,
    ) {
    }

    public function getName(): string
    {
        return 'core:system:assume_tenant';
    }

    public function getDescription(): string
    {
        return 'Creates a short-lived tenant session token (OOB-CA) requiring human approval before use.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Out-of-Band Context Authorization (OOB-CA). Initiates a human-gated tenant context switch.

        ## Flow

        1. Call this action with the target `tenant` slug.
        2. The response contains a `_mcp_context_token` and an `approval_url`.
        3. **Do not proceed** — present the `approval_url` to the human operator and wait for
           them to approve it in their browser before continuing.
        4. Once the operator approves, include `_mcp_context_token` in subsequent tool calls.
           The interceptor will authenticate the tenant context from the token.
        5. Token is valid for 10 minutes. Request a new one if it expires.

        ## Returns
        - `_mcp_context_token`: Short-lived JWT (10 min TTL) — pass in subsequent tool calls
        - `approval_url`: Signed URL the human operator must open to approve this session
        - `tenant`: The requested tenant slug

        ## Example
        Input:  { "tenant": "production" }
        Output: { "_mcp_context_token": "eyJ...", "approval_url": "https://...", "tenant": "production" }
        USAGE;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getParameters(): array
    {
        return [
            'tenant' => [
                'type' => 'string',
                'description' => 'The tenant slug to assume context for (e.g. "production", "staging").',
                'required' => true,
                'example' => 'production',
            ],
        ];
    }

    /** @return string[] */
    public function getTags(): array
    {
        return ['core', 'security', 'oob-ca'];
    }

    public function isMutating(): bool
    {
        // Creates a DB record (TenantSession) but does not mutate any tenant data.
        return false;
    }

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        $tenantId = \is_string($args['tenant'] ?? null) ? (string) $args['tenant'] : '';

        if ('' === $tenantId) {
            return ActionResult::failure(
                'Parameter "tenant" is required.',
                'ERROR_INVALID_PARAMS',
                'Provide the target tenant slug as "tenant" in the call arguments.',
            );
        }

        $jti = $this->generateJti();
        $expiresAt = new \DateTimeImmutable('+'.self::TTL_SECONDS.' seconds');

        $jwt = $this->jwtEncoder->encode([
            'tenant_id' => $tenantId,
            'jti' => $jti,
            'exp' => $expiresAt->getTimestamp(),
        ]);

        $session = new TenantSession($jti, $tenantId, $expiresAt);
        $this->repository->save($session, flush: true);

        $approvalUrl = $this->approvalUrlGenerator->generate($jti);

        return ActionResult::ok(
            data: [
                '_mcp_context_token' => $jwt,
                'approval_url' => $approvalUrl,
                'tenant' => $tenantId,
            ],
            summary: \sprintf(
                'Tenant session created for "%s". Present the approval_url to the operator and wait for approval. TTL: %d seconds.',
                $tenantId,
                self::TTL_SECONDS,
            ),
        );
    }

    /** Generate a UUID v4 for use as the JWT ID (jti). */
    private function generateJti(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40); // version 4
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80); // variant 10xx

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
