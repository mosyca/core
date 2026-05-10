<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Mosyca\Core\Vault\Entity\Operator;

/**
 * @extends ServiceEntityRepository<Operator>
 */
class OperatorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Operator::class);
    }

    public function findByUsername(string $username): ?Operator
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function usernameExists(string $username): bool
    {
        return null !== $this->findByUsername($username);
    }

    public function save(Operator $operator, bool $flush = false): void
    {
        $this->getEntityManager()->persist($operator);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
