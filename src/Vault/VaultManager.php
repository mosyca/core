<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault;

use Mosyca\Core\Vault\Cipher\SecretCipherInterface;
use Mosyca\Core\Vault\Entity\VaultSecret;
use Mosyca\Core\Vault\Exception\SecretNotFoundException;
use Mosyca\Core\Vault\Repository\VaultSecretRepository;

/**
 * Service layer for encrypted M2M credential storage.
 *
 * All credentials are stored as JSON-encoded, Cipher-encrypted blobs.
 * The plaintext payload is NEVER logged, returned via API, or included
 * in exception messages (Vault Rules V2, Security Rule 2).
 *
 * Usage in connector actions:
 * <code>
 *     try {
 *         $creds = $this->vault->retrieveSecret($context->getTenantId(), 'shopware6');
 *         // $creds['client_id'], $creds['client_secret'], etc.
 *     } catch (SecretNotFoundException $e) {
 *         return ActionResult::authRequired('shopware6');
 *     }
 * </code>
 */
class VaultManager
{
    public function __construct(
        private readonly VaultSecretRepository $repository,
        private readonly SecretCipherInterface $cipher,
    ) {
    }

    /**
     * Store (create or update) an encrypted credential set.
     *
     * Performs an upsert: if a secret already exists for the given context
     * it is updated in-place; otherwise a new record is created.
     *
     * @param array<string, mixed> $payload Credential key/value pairs (e.g. client_id, client_secret)
     *
     * @throws \JsonException if payload cannot be JSON-encoded (should never happen with mixed arrays)
     */
    public function storeSecret(
        string $tenantId,
        string $integrationType,
        array $payload,
        ?string $userId = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): void {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR);
        $encrypted = $this->cipher->encrypt($json);

        $existing = $this->repository->findByContext($tenantId, $integrationType, $userId);

        if (null !== $existing) {
            $existing->updatePayload($encrypted, $expiresAt);
            $this->repository->save($existing, flush: true);
        } else {
            $secret = new VaultSecret(
                tenantId: $tenantId,
                integrationType: $integrationType,
                credentialPayload: $encrypted,
                userId: $userId,
                expiresAt: $expiresAt,
            );
            $this->repository->save($secret, flush: true);
        }
    }

    /**
     * Retrieve and decrypt the credential set for a given context.
     *
     * @return array<string, mixed>
     *
     * @throws SecretNotFoundException if no active (non-expired) credential exists
     * @throws \RuntimeException       if the stored payload is structurally invalid (corrupt vault entry)
     */
    public function retrieveSecret(
        string $tenantId,
        string $integrationType,
        ?string $userId = null,
    ): array {
        $secret = $this->repository->findByContext($tenantId, $integrationType, $userId);

        if (null === $secret || $secret->isExpired()) {
            throw SecretNotFoundException::forContext($tenantId, $integrationType, $userId);
        }

        $json = $this->cipher->decrypt($secret->getCredentialPayload());

        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            // Corrupt vault entry — encrypted payload decoded to a non-array.
            // Do NOT include the decoded value in the exception (Security Rule V2).
            throw new \RuntimeException(\sprintf('Vault payload for "%s" / "%s" is structurally invalid (expected JSON object).', $integrationType, $tenantId));
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Hard-delete the credential set for a given context.
     *
     * @throws SecretNotFoundException if no credential exists for the given context
     */
    public function deleteSecret(
        string $tenantId,
        string $integrationType,
        ?string $userId = null,
    ): void {
        $secret = $this->repository->findByContext($tenantId, $integrationType, $userId);

        if (null === $secret) {
            throw SecretNotFoundException::forContext($tenantId, $integrationType, $userId);
        }

        $this->repository->delete($secret, flush: true);
    }
}
