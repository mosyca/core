<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Acl;

use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Vault\Clearance\ClearanceRegistry;
use Mosyca\Core\Vault\Entity\Operator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Checks whether the currently authenticated Operator may call a plugin.
 *
 * Called by PluginRunProcessor before executing any plugin.
 *
 * Rules:
 *   - No authenticated operator (anonymous)  → 403
 *   - Operator's clearance not found         → 403
 *   - Clearance does not permit plugin       → 403
 *   - Clearance permits plugin               → pass
 */
final class PluginAccessChecker
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ClearanceRegistry $clearanceRegistry,
    ) {
    }

    /**
     * @throws AccessDeniedHttpException when access is denied
     */
    public function assertCanRun(PluginInterface $plugin): void
    {
        $token = $this->tokenStorage->getToken();
        $operator = $token?->getUser();

        if (!$operator instanceof Operator) {
            throw new AccessDeniedHttpException('Authentication required to run plugins.');
        }

        $clearance = $this->clearanceRegistry->get($operator->getClearance());

        if (null === $clearance) {
            throw new AccessDeniedHttpException(\sprintf('Unknown clearance "%s" for operator "%s".', $operator->getClearance(), $operator->getUsername()));
        }

        if (!$clearance->permits($plugin->getName(), $plugin->isMutating())) {
            throw new AccessDeniedHttpException(\sprintf('Clearance "%s" does not permit calling plugin "%s"%s.', $clearance->name, $plugin->getName(), $plugin->isMutating() ? ' (mutating)' : ''));
        }
    }
}
