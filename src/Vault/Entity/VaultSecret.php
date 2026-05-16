<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mosyca\Core\Vault\Repository\VaultSecretRepository;

/**
 * Encrypted M2M credential set for a given tenant + integration type.
 *
 * The `credential_payload` column stores a JSON object that has been encrypted
 * by SecretCipherInterface before persistence. Its plaintext MUST NEVER appear
 * in logs, API responses, or exception messages (Vault Rule V2).
 *
 * Lookup key: (tenant_id, integration_type, user_id)
 *   - tenant_id:       mandatory — identifies the Mosyca tenant (e.g. "bluevendsand")
 *   - integration_type: mandatory — identifies the connector (e.g. "shopware6", "spotify")
 *   - user_id:          optional  — scopes the credential to a specific end-user (OAuth flows)
 *                                   null = tenant-level credential (M2M / admin token)
 */
#[ORM\Entity(repositoryClass: VaultSecretRepository::class)]
#[ORM\Table(name: 'mosyca_vault_secret')]
#[ORM\UniqueConstraint(
    name: 'uq_vault_secret_context',
    columns: ['tenant_id', 'integration_type', 'user_id'],
)]
class VaultSecret
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Mosyca tenant identifier (e.g. "bluevendsand"). */
    #[ORM\Column(length: 100)]
    private string $tenantId;

    /**
     * Optional end-user identifier for per-user OAuth credentials.
     * null = tenant-level credential.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $userId;

    /** Connector / integration identifier (e.g. "shopware6", "spotify"). */
    #[ORM\Column(length: 100)]
    private string $integrationType;

    /**
     * Encrypted JSON payload. The plaintext is a JSON-encoded array of credentials
     * (e.g. {"access_token":"...", "refresh_token":"..."}). It is encrypted by
     * SecretCipherInterface before being written here.
     *
     * NEVER log, print, or expose this value in plaintext.
     */
    #[ORM\Column(type: 'text')]
    private string $credentialPayload;

    /** Optional expiry. null = credential does not expire. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $tenantId,
        string $integrationType,
        string $credentialPayload,
        ?string $userId = null,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->tenantId = $tenantId;
        $this->integrationType = $integrationType;
        $this->credentialPayload = $credentialPayload;
        $this->userId = $userId;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getIntegrationType(): string
    {
        return $this->integrationType;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getCredentialPayload(): string
    {
        return $this->credentialPayload;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns true when an expiry has been set and it has passed.
     * Returns false when there is no expiry (credential never expires).
     */
    public function isExpired(): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    /**
     * Replace the encrypted credential payload (e.g. after token rotation).
     * Updates the updatedAt timestamp and optionally extends the expiry.
     */
    public function updatePayload(string $encryptedPayload, ?\DateTimeImmutable $expiresAt = null): void
    {
        $this->credentialPayload = $encryptedPayload;
        $this->updatedAt = new \DateTimeImmutable();

        if (null !== $expiresAt) {
            $this->expiresAt = $expiresAt;
        }
    }
}
