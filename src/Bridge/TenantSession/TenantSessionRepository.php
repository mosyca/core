<?php

declare(strict_types=1);

namespace Mosyca\Core\Bridge\TenantSession;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantSession>
 */
class TenantSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantSession::class);
    }

    public function findByJti(string $jti): ?TenantSession
    {
        return $this->findOneBy(['jti' => $jti]);
    }

    public function save(TenantSession $session, bool $flush = false): void
    {
        $this->getEntityManager()->persist($session);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
