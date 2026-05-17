<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Identity;

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Identity\Provider\GroupProviderInterface;

/**
 * Read a single group by id (ADR 1.5.2 — read-only).
 *
 * OAI: mosyca:group:read
 */
#[AsAction]
final class GroupReadAction implements \Mosyca\Core\Action\ActionInterface
{
    use ActionTrait;

    public function __construct(private readonly GroupProviderInterface $groups)
    {
    }

    public function getName(): string
    {
        return 'mosyca:group:read';
    }

    public function getDescription(): string
    {
        return 'Returns a single group by id.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Fetches one group from the identity registry by id.

        ## Parameters
        - id (required): the group id / slug (e.g. admins)

        ## Returns
        - id, roles, permissions

        ## Errors
        - NOT_FOUND: no group with that id exists
        USAGE;
    }

    public function getParameters(): array
    {
        return [
            'id' => [
                'type' => 'string',
                'description' => 'Group id / slug (e.g. admins).',
                'required' => true,
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
        return ['identity', 'group'];
    }

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        $dto = $this->groups->getGroup((string) $args['id']);

        if (null === $dto) {
            return ActionResult::failure(
                'Group not found.',
                'NOT_FOUND',
                'Provide a valid group id.',
            );
        }

        return ActionResult::ok(
            data: [
                'id' => $dto->id,
                'roles' => $dto->roles,
                'permissions' => $dto->permissions,
            ],
            summary: "Group: {$dto->id}",
        );
    }
}
