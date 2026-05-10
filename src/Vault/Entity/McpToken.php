<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Mosyca\Core\Vault\Repository\McpTokenRepository;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Audit record for a long-lived JWT MCP Token.
 *
 * The token itself is a JWT signed by Lexik. This entity tracks
 * its jti (JWT ID) for future revocation and audit purposes.
 *
 * Token creation (POST) is handled by VaultController::generateMcpToken()
 * which returns the raw JWT string (only visible once).
 */
#[ORM\Entity(repositoryClass: McpTokenRepository::class)]
#[ORM\Table(name: 'mosyca_mcp_token')]
#[ApiResource(
    shortName: 'McpToken',
    description: 'Long-lived MCP Bearer tokens. Generate via POST /api/vault/mcp-tokens/generate.',
    operations: [
        new GetCollection(uriTemplate: '/vault/mcp-tokens'),
        new Get(uriTemplate: '/vault/mcp-tokens/{id}'),
        new Delete(uriTemplate: '/vault/mcp-tokens/{id}'),
    ],
    normalizationContext: ['groups' => ['mcp_token:read']],
)]
class McpToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mcp_token:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Operator::class, inversedBy: 'mcpTokens')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mcp_token:read'])]
    private Operator $operator;

    /** Human-readable label (e.g. "Claude Desktop – Laptop"). */
    #[ORM\Column(length: 100)]
    #[Groups(['mcp_token:read'])]
    private string $name;

    /** JWT `jti` claim — used for revocation lookups. */
    #[ORM\Column(length: 100, unique: true)]
    #[Groups(['mcp_token:read'])]
    private string $tokenJti;

    #[ORM\Column]
    #[Groups(['mcp_token:read'])]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    #[Groups(['mcp_token:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['mcp_token:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['mcp_token:read'])]
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
