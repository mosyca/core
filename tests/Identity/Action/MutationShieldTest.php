<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Identity\Action;

use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\Identity\GroupListAction;
use Mosyca\Core\Action\Identity\GroupReadAction;
use Mosyca\Core\Action\Identity\TenantListAction;
use Mosyca\Core\Action\Identity\TenantReadAction;
use Mosyca\Core\Action\Identity\UserListAction;
use Mosyca\Core\Action\Identity\UserReadAction;
use Mosyca\Core\Identity\Dto\GroupDto;
use Mosyca\Core\Identity\Dto\TenantDto;
use Mosyca\Core\Identity\Dto\UserDto;
use Mosyca\Core\Identity\Provider\GroupProviderInterface;
use Mosyca\Core\Identity\Provider\TenantProviderInterface;
use Mosyca\Core\Identity\Provider\UserProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Rule ID2 — Action Mutation Shield (ADR 1.5.2).
 *
 * Verifies that the Core action registry contains NO create, update, or delete
 * actions for the mosyca:tenant, mosyca:user, or mosyca:group namespaces.
 */
final class MutationShieldTest extends TestCase
{
    private ActionRegistry $registry;

    protected function setUp(): void
    {
        $tenantProvider = $this->createStub(TenantProviderInterface::class);
        $tenantProvider->method('getTenant')->willReturn(new TenantDto('t', 'T'));
        $tenantProvider->method('getTenants')->willReturn([]);

        $userProvider = $this->createStub(UserProviderInterface::class);
        $userProvider->method('getUser')->willReturn(new UserDto('u', 'u@example.com'));
        $userProvider->method('getUsersByTenant')->willReturn([]);

        $groupProvider = $this->createStub(GroupProviderInterface::class);
        $groupProvider->method('getGroup')->willReturn(new GroupDto('g'));
        $groupProvider->method('getGroups')->willReturn([]);

        $this->registry = new ActionRegistry();
        $this->registry->register(new TenantReadAction($tenantProvider));
        $this->registry->register(new TenantListAction($tenantProvider));
        $this->registry->register(new UserReadAction($userProvider));
        $this->registry->register(new UserListAction($userProvider));
        $this->registry->register(new GroupReadAction($groupProvider));
        $this->registry->register(new GroupListAction($groupProvider));
    }

    /** @return array<int, array{string}> */
    public static function forbiddenMosycaActions(): array
    {
        return [
            ['mosyca:tenant:create'],
            ['mosyca:tenant:update'],
            ['mosyca:tenant:delete'],
            ['mosyca:user:create'],
            ['mosyca:user:update'],
            ['mosyca:user:delete'],
            ['mosyca:group:create'],
            ['mosyca:group:update'],
            ['mosyca:group:delete'],
        ];
    }

    /**
     * @dataProvider forbiddenMosycaActions
     */
    public function testCoreRegistersNoMutationActionsForMosycaNamespace(string $oai): void
    {
        self::assertFalse(
            $this->registry->has($oai),
            "Core MUST NOT register mutation action '{$oai}' (ADR 1.5.2).",
        );
    }

    public function testAllCoreIdentityActionsAreReadOnly(): void
    {
        foreach ($this->registry->all() as $action) {
            if (!str_starts_with($action->getName(), 'mosyca:')) {
                continue;
            }

            self::assertFalse(
                $action->isMutating(),
                "Core identity action '{$action->getName()}' must not be mutating (ADR 1.5.2).",
            );
        }
    }

    public function testOnlyListAndReadActionsExistInIdentityNamespace(): void
    {
        $allowedSuffixes = ['list', 'read'];
        $identityPrefixes = ['mosyca:tenant:', 'mosyca:user:', 'mosyca:group:'];

        foreach ($this->registry->all() as $name => $action) {
            foreach ($identityPrefixes as $prefix) {
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }

                $suffix = substr($name, \strlen($prefix));
                self::assertContains(
                    $suffix,
                    $allowedSuffixes,
                    "Core identity action '{$name}' has unexpected operation '{$suffix}' (ADR 1.5.2).",
                );
            }
        }
    }
}
