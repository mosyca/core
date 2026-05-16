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

    /**
     * Find all secrets whose integration_type is NOT in the given active set.
     *
     * Used by VaultCleanupCommand to discover orphaned secrets after a plugin
     * is uninstalled. Returns an empty array when $activeIntegrationTypes is
     * empty (safety guard — never marks everything as orphaned).
     *
     * @param string[]    $activeIntegrationTypes Namespaces currently in ActionRegistry
     * @param string|null $tenantId               Optional tenant scope
     *
     * @return VaultSecret[]
     */
    public function findOrphaned(array $activeIntegrationTypes, ?string $tenantId = null): array
    {
        if ([] === $activeIntegrationTypes) {
            return [];
        }

        $qb = $this->createQueryBuilder('s')
            ->where('s.integrationType NOT IN (:activeTypes)')
            ->setParameter('activeTypes', $activeIntegrationTypes)
            ->orderBy('s.tenantId', 'ASC')
            ->addOrderBy('s.integrationType', 'ASC');

        if (null !== $tenantId) {
            $qb->andWhere('s.tenantId = :tenantId')
                ->setParameter('tenantId', $tenantId);
        }

        /** @var VaultSecret[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Remove all given secrets in a single batched flush.
     *
     * Prefer this over calling delete() in a loop when removing multiple secrets
     * at once (GC path) to avoid one flush per entity.
     *
     * @param VaultSecret[] $secrets
     */
    public function deleteMany(array $secrets): void
    {
        $em = $this->getEntityManager();
        foreach ($secrets as $secret) {
            $em->remove($secret);
        }
        $em->flush();
    }
}
