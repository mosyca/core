<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Security;

use Mosyca\Core\Vault\Entity\Operator;
use Mosyca\Core\Vault\Repository\OperatorRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<Operator>
 */
final class OperatorUserProvider implements UserProviderInterface
{
    public function __construct(private readonly OperatorRepository $repository)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $operator = $this->repository->findByUsername($identifier);

        if (null === $operator) {
            throw new UserNotFoundException(\sprintf('Operator "%s" not found.', $identifier));
        }

        return $operator;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof Operator) {
            throw new UnsupportedUserException(\sprintf('User class "%s" is not supported.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return Operator::class === $class || is_subclass_of($class, Operator::class);
    }
}
