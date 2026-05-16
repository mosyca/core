<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Mosyca\Core\Vault\Entity\VaultSecret;

/**
 * @extends ServiceEntityRepository<VaultSecret>
 */
class VaultSecretRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VaultSecret::class);
    }

    /**
     * Find a credential set by its lookup context.
     *
     * Pass $userId = null for tenant-level (M2M) credentials.
     * Pass $userId = '<user-id>' for per-user OAuth credentials.
     */
    public function findByContext(
        string $tenantId,
        string $integrationType,
        ?string $userId = null,
    ): ?VaultSecret {
        return $this->findOneBy([
            'tenantId' => $tenantId,
            'integrationType' => $integrationType,
            'userId' => $userId,
        ]);
    }

    public function save(VaultSecret $secret, bool $flush = false): void
    {
        $this->getEntityManager()->persist($secret);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function delete(VaultSecret $secret, bool $flush = false): void
    {
        $this->getEntityManager()->remove($secret);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
