<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Acl;

use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Vault\Clearance\ClearanceRegistry;
use Mosyca\Core\Vault\Entity\Operator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Checks whether the currently authenticated Operator may call an action.
 *
 * Called by ActionRunProcessor before executing any action.
 *
 * Rules:
 *   - No authenticated operator (anonymous)  → 403
 *   - Operator's clearance not found         → 403
 *   - Clearance does not permit action       → 403
 *   - Clearance permits action               → pass
 */
final class ActionAccessChecker
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ClearanceRegistry $clearanceRegistry,
    ) {
    }

    /**
     * @throws AccessDeniedHttpException when access is denied
     */
    public function assertCanRun(ActionInterface $action): void
    {
        $token = $this->tokenStorage->getToken();
        $operator = $token?->getUser();

        if (!$operator instanceof Operator) {
            throw new AccessDeniedHttpException('Authentication required to run actions.');
        }

        $clearance = $this->clearanceRegistry->get($operator->getClearance());

        if (null === $clearance) {
            throw new AccessDeniedHttpException(\sprintf('Unknown clearance "%s" for operator "%s".', $operator->getClearance(), $operator->getUsername()));
        }

        if (!$clearance->permits($action->getName(), $action->isMutating())) {
            throw new AccessDeniedHttpException(\sprintf('Clearance "%s" does not permit calling action "%s"%s.', $clearance->name, $action->getName(), $action->isMutating() ? ' (mutating)' : ''));
        }
    }
}
