<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mosyca\Core\Vault\Repository\McpTokenRepository;

/**
 * Audit record for a long-lived JWT MCP Token.
 *
 * The token itself is a JWT signed by Lexik. This entity tracks
 * its jti (JWT ID) for future revocation and audit purposes.
 */
#[ORM\Entity(repositoryClass: McpTokenRepository::class)]
#[ORM\Table(name: 'mosyca_mcp_token')]
class McpToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Operator::class, inversedBy: 'mcpTokens')]
    #[ORM\JoinColumn(nullable: false)]
    private Operator $operator;

    /** Human-readable label (e.g. "Claude Desktop – Laptop"). */
    #[ORM\Column(length: 100)]
    private string $name;

    /** JWT `jti` claim — used for revocation lookups. */
    #[ORM\Column(length: 100, unique: true)]
    private string $tokenJti;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $revoked = false;

    public function __construct(
        Operator $operator,
        string $name,
        string $tokenJti,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->operator = $operator;
        $this->name = $name;
        $this->tokenJti = $tokenJti;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperator(): Operator
    {
        return $this->operator;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenJti(): string
    {
        return $this->tokenJti;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return !$this->revoked && !$this->isExpired();
    }
}
