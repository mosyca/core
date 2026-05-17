<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Identity;

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Identity\Provider\UserProviderInterface;

/**
 * List users, optionally filtered by tenant (ADR 1.5.2 — read-only).
 *
 * OAI: mosyca:user:list
 */
#[AsAction]
final class UserListAction implements \Mosyca\Core\Action\ActionInterface
{
    use ActionTrait;

    public function __construct(private readonly UserProviderInterface $users)
    {
    }

    public function getName(): string
    {
        return 'mosyca:user:list';
    }

    public function getDescription(): string
    {
        return 'Returns users, optionally filtered by tenant slug.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Lists users from the identity registry.

        ## Parameters
        - tenant_id (optional): filter by tenant slug (e.g. demecan_gmbh)

        ## Returns
        Array of { id, email, displayName, groups, allowedTenants } objects.
        USAGE;
    }

    public function getParameters(): array
    {
        return [
            'tenant_id' => [
                'type' => 'string',
                'description' => 'Filter users by tenant slug.',
                'required' => false,
                'default' => null,
            ],
        ];
    }

    public function isMutating(): bool
    {
        return false;
    }

    /** @return string[] */
    public function getTags(): array
    {
        return ['identity', 'user'];
    }

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        $tenantId = isset($args['tenant_id']) ? (string) $args['tenant_id'] : null;

        $dtos = null !== $tenantId
            ? $this->users->getUsersByTenant($tenantId)
            : [];

        $items = array_map(
            static fn ($dto): array => [
                'id' => $dto->id,
                'email' => $dto->email,
                'displayName' => $dto->displayName,
                'groups' => $dto->groups,
                'allowedTenants' => $dto->allowedTenants,
            ],
            $dtos,
        );

        return ActionResult::ok(
            data: $items,
            summary: \sprintf('%d user(s)', \count($items)),
        );
    }
}
