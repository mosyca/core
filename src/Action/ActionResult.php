<?php

declare(strict_types=1);

namespace Mosyca\Core\Action;

/**
 * Unified output object for all Mosyca actions.
 *
 * The Renderer converts this into the requested output format:
 * json, yaml, raw, table, text (twig), mcp.
 *
 * V0.8: Carries Depot and Ledger metadata decided by the plugin inside run().
 * Both systems are opt-in — defaults are off.
 */
final class ActionResult
{
    private function __construct(
        public readonly bool $success,
        public readonly mixed $data,
        public readonly string $summary,
        /** @var array<string, string> */
        public readonly array $links = [],
        /** @var array<string, mixed> */
        public readonly array $embedded = [],
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
        // — V0.8 Depot — //
        /** Whether this result may be written to Depot if the caller requests it. */
        public readonly bool $depotEligible = false,
        /** Depot TTL in seconds. Only relevant when depotEligible = true. */
        public readonly int $depotTtl = 3600,
        // — V0.8 Ledger — //
        /** @var array<string, mixed>|null Ledger payload for the Action Log. null = no action log entry. */
        public readonly ?array $ledgerPayload = null,
        /** Ledger level for this payload. Only relevant when ledgerPayload != null. */
        public readonly string $ledgerLevel = 'info',
        // — ACL / Domain Error — //
        /**
         * Machine-readable error code for LLM-deterministic error handling.
         * Non-null for failure() results; null for ok() and legacy error() results
         * (though error() now internally sets 'ERROR_LEGACY').
         *
         * Standard codes: 'ERROR_ACL_DENIED', 'ERROR_INVALID_PIN', 'ERROR_LEGACY', etc.
         */
        public readonly ?string $errorCode = null,
        /**
         * Actionable correction hint for LLM agents.
         * Tells Claude what to do next when this error is received.
         *
         * Example: 'Provide the correct security_pin in the payload field "pin".'
         */
        public readonly ?string $correctionHint = null,
    ) {
    }

    /**
     * Successful result.
     *
     * @param mixed  $data    The result data (array, object, scalar)
     * @param string $summary One-line human readable summary.
     *                        Claude reads this as the primary response text.
     *                        Example: "Order #1234: margin 47.30€ (23.5%)"
     */
    public static function ok(mixed $data, string $summary): self
    {
        return new self(
            success: true,
            data: $data,
            summary: $summary,
        );
    }

    /**
     * Structured failure result — the mandatory pattern for ACL denials and domain errors.
     *
     * LLM agents (Claude) can react deterministically to errorCode without parsing
     * the human-readable message. correctionHint gives Claude an explicit action hint.
     *
     * Domain ACL vector pattern:
     * <code>
     *     if (!$context->isAclBypassed() && !$this->validatePin($args['pin'] ?? null)) {
     *         return ActionResult::failure(
     *             'Access denied. Domain authentication failed.',
     *             'ERROR_ACL_DENIED',
     *             'Provide the correct security_pin in the payload.',
     *         );
     *     }
     * </code>
     *
     * @param array<string, mixed> $context Additional error context (no PII)
     */
    public static function failure(
        string $message,
        string $errorCode,
        string $correctionHint,
        array $context = [],
    ): self {
        return new self(
            success: false,
            data: $context,
            summary: $message,
            errorCode: $errorCode,
            correctionHint: $correctionHint,
        );
    }

    /**
     * Signal that credentials are missing for a given integration.
     *
     * The MCP Bridge detects errorCode 'AUTH_REQUIRED' and triggers the out-of-band
     * provisioning flow (V0.14d), surfacing a secure link to the user without
     * ever prompting Claude to collect credentials directly.
     *
     * Usage in connector actions:
     * <code>
     *     $secret = $this->vault->retrieveSecret($context->getTenantId(), 'spotify');
     *     if (null === $secret) {
     *         return ActionResult::authRequired('spotify', ['playlist-modify-public']);
     *     }
     * </code>
     *
     * SECURITY: The returned `data` array carries ONLY the integration type and
     * required scope identifiers — never credential values (Vault Rule V2).
     *
     * @param string[] $requiredScopes OAuth scopes or permission identifiers needed
     */
    public static function authRequired(string $integrationType, array $requiredScopes = []): self
    {
        return new self(
            success: false,
            data: ['integration_type' => $integrationType, 'required_scopes' => $requiredScopes],
            summary: \sprintf('Authentication required: no credentials stored for "%s".', $integrationType),
            errorCode: 'AUTH_REQUIRED',
            correctionHint: \sprintf(
                'Use the out-of-band provisioning link to store credentials for "%s", then retry.',
                $integrationType,
            ),
        );
    }

    /**
     * Legacy error result.
     *
     * @deprecated Use ActionResult::failure() with errorCode and correctionHint.
     *             failure() enables LLM-deterministic error handling and is required
     *             for ACL denials and domain errors.
     *
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): self
    {
        return self::failure(
            message: $message,
            errorCode: 'ERROR_LEGACY',
            correctionHint: 'Use ActionResult::failure() with errorCode and correctionHint.',
            context: $context,
        );
    }

    /**
     * Mark this result as depot-eligible with a TTL.
     *
     * The caller still has to actively request depot caching per call (double opt-in).
     * Scaffold actions: ActionRunProcessor strips eligibility regardless of this flag.
     */
    public function withDepot(int $ttl = 3600): self
    {
        return new self(
            success: $this->success,
            data: $this->data,
            summary: $this->summary,
            links: $this->links,
            embedded: $this->embedded,
            metadata: $this->metadata,
            depotEligible: true,
            depotTtl: $ttl,
            ledgerPayload: $this->ledgerPayload,
            ledgerLevel: $this->ledgerLevel,
            errorCode: $this->errorCode,
            correctionHint: $this->correctionHint,
        );
    }

    /**
     * Strip depot eligibility.
     *
     * Called by ActionRunProcessor for scaffold actions — cannot be overridden.
     */
    public function withoutDepot(): self
    {
        return new self(
            success: $this->success,
            data: $this->data,
            summary: $this->summary,
            links: $this->links,
            embedded: $this->embedded,
            metadata: $this->metadata,
            depotEligible: false,
            depotTtl: $this->depotTtl,
            ledgerPayload: $this->ledgerPayload,
            ledgerLevel: $this->ledgerLevel,
            errorCode: $this->errorCode,
            correctionHint: $this->correctionHint,
        );
    }

    /**
     * Attach an action log payload.
     *
     * The action is responsible for stripping PII before calling this.
     * No request parameters may be passed here directly.
     *
     * @param array<string, mixed> $payload
     */
    public function withLedger(string $level = 'info', array $payload = []): self
    {
        return new self(
            success: $this->success,
            data: $this->data,
            summary: $this->summary,
            links: $this->links,
            embedded: $this->embedded,
            metadata: $this->metadata,
            depotEligible: $this->depotEligible,
            depotTtl: $this->depotTtl,
            ledgerPayload: $payload,
            ledgerLevel: $level,
            errorCode: $this->errorCode,
            correctionHint: $this->correctionHint,
        );
    }

    /**
     * Add HATEOAS links.
     *
     * @param array<string, string> $links ['self' => '/api/...', 'order' => '/api/...']
     */
    public function withLinks(array $links): self
    {
        return new self(
            success: $this->success,
            data: $this->data,
            summary: $this->summary,
            links: $links,
            embedded: $this->embedded,
            metadata: $this->metadata,
            depotEligible: $this->depotEligible,
            depotTtl: $this->depotTtl,
            ledgerPayload: $this->ledgerPayload,
            ledgerLevel: $this->ledgerLevel,
            errorCode: $this->errorCode,
            correctionHint: $this->correctionHint,
        );
    }

    /**
     * Add embedded related objects.
     *
     * @param array<string, mixed> $embedded ['order' => $order->toArray(), ...]
     */
    public function withEmbedded(array $embedded): self
    {
        return new self(
            success: $this->success,
            data: $this->data,
            summary: $this->summary,
            links: $this->links,
            embedded: $embedded,
            metadata: $this->metadata,
            depotEligible: $this->depotEligible,
            depotTtl: $this->depotTtl,
            ledgerPayload: $this->ledgerPayload,
            ledgerLevel: $this->ledgerLevel,
            errorCode: $this->errorCode,
            correctionHint: $this->correctionHint,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'success' => $this->success,
            'summary' => $this->summary,
            'data' => $this->data,
        ];

        if (null !== $this->errorCode) {
            $result['errorCode'] = $this->errorCode;
        }

        if (null !== $this->correctionHint) {
            $result['correctionHint'] = $this->correctionHint;
        }

        if (!empty($this->links)) {
            $result['_links'] = $this->links;
        }

        if (!empty($this->embedded)) {
            $result['_embedded'] = $this->embedded;
        }

        if (!empty($this->metadata)) {
            $result['_meta'] = $this->metadata;
        }

        return $result;
    }
}
