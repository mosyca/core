<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Mosyca\Core\Vault\Entity\McpToken;
use Mosyca\Core\Vault\Entity\Operator;

/**
 * @extends ServiceEntityRepository<McpToken>
 */
class McpTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpToken::class);
    }

    public function findByJti(string $jti): ?McpToken
    {
        return $this->findOneBy(['tokenJti' => $jti]);
    }

    /** @return McpToken[] */
    public function findActiveByOperator(Operator $operator): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.operator = :operator')
            ->andWhere('t.revoked = false')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('operator', $operator)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(McpToken $token, bool $flush = false): void
    {
        $this->getEntityManager()->persist($token);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
