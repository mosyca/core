<?php

declare(strict_types=1);

namespace Mosyca\Core\Bridge\TenantSession;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine entity tracking a short-lived OOB-CA tenant context-switch session.
 *
 * Lifecycle:
 *   1. AssumeTenantAction creates a PENDING record + issues a short-lived JWT (jti = this.jti).
 *   2. TenantSessionApprovalController flips state to ACTIVE or DENIED on human interaction.
 *   3. TenantSessionInterceptor reads state on every subsequent MCP tool call:
 *      - PENDING  → block + return approval URL
 *      - ACTIVE   → allow + inject tenantId into arguments
 *      - DENIED   → permanent block
 *      - expired  → treat as PENDING-never-approved (still blocked)
 *
 * Security rules:
 *   SR-OOB-6: ACTIVE sessions allow unlimited subsequent calls (session token, not one-time).
 *   SR-OOB-7: DENIED is permanent — deny() throws LogicException if called on non-PENDING.
 */
#[ORM\Entity(repositoryClass: TenantSessionRepository::class)]
#[ORM\Table(name: 'mosyca_tenant_sessions')]
#[ORM\Index(columns: ['jti'], name: 'idx_tenant_session_jti')]
#[ORM\Index(columns: ['state'], name: 'idx_tenant_session_state')]
class TenantSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** JWT ID (UUID v4) — matches the `jti` claim in the short-lived JWT. */
    #[ORM\Column(length: 36, unique: true)]
    private string $jti;

    /** The tenant slug this session authorises access to. */
    #[ORM\Column(length: 255)]
    private string $tenantId;

    #[ORM\Column(length: 10, enumType: TenantSessionState::class)]
    private TenantSessionState $state;

    /** Mirrors the JWT `exp` claim — belt-and-suspenders expiry check alongside JWT validation. */
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Set when state transitions to ACTIVE. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    /** Set when state transitions to DENIED. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deniedAt = null;

    public function __construct(
        string $jti,
        string $tenantId,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->jti = $jti;
        $this->tenantId = $tenantId;
        $this->state = TenantSessionState::PENDING;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJti(): string
    {
        return $this->jti;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getState(): TenantSessionState
    {
        return $this->state;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getDeniedAt(): ?\DateTimeImmutable
    {
        return $this->deniedAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    /**
     * Transition PENDING → ACTIVE.
     *
     * @throws \LogicException When called on a session not in PENDING state
     */
    public function approve(): void
    {
        if (TenantSessionState::PENDING !== $this->state) {
            throw new \LogicException(\sprintf('Cannot approve a tenant session in state "%s" (only PENDING can be approved).', $this->state->value));
        }

        $this->state = TenantSessionState::ACTIVE;
        $this->activatedAt = new \DateTimeImmutable();
    }

    /**
     * Transition PENDING → DENIED (permanent).
     *
     * @throws \LogicException When called on a session not in PENDING state
     */
    public function deny(): void
    {
        if (TenantSessionState::PENDING !== $this->state) {
            throw new \LogicException(\sprintf('Cannot deny a tenant session in state "%s" (only PENDING can be denied).', $this->state->value));
        }

        $this->state = TenantSessionState::DENIED;
        $this->deniedAt = new \DateTimeImmutable();
    }
}
