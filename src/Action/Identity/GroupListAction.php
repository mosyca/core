<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Identity;

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Identity\Provider\GroupProviderInterface;

/**
 * List all groups with optional pagination (ADR 1.5.2 — read-only).
 *
 * OAI: mosyca:group:list
 */
#[AsAction]
final class GroupListAction implements \Mosyca\Core\Action\ActionInterface
{
    use ActionTrait;

    public function __construct(private readonly GroupProviderInterface $groups)
    {
    }

    public function getName(): string
    {
        return 'mosyca:group:list';
    }

    public function getDescription(): string
    {
        return 'Returns a paginated list of all groups.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Lists all groups registered in the identity configuration.

        ## Parameters
        - limit (optional, default 50): maximum number of results

        ## Returns
        Array of { id, roles, permissions } objects.
        USAGE;
    }

    public function getParameters(): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of groups to return (default 50).',
                'required' => false,
                'default' => 50,
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
        $limit = isset($args['limit']) ? (int) $args['limit'] : 50;

        $dtos = $this->groups->getGroups(0, $limit);

        $items = array_map(
            static fn ($dto): array => [
                'id' => $dto->id,
                'roles' => $dto->roles,
                'permissions' => $dto->permissions,
            ],
            $dtos,
        );

        return ActionResult::ok(
            data: $items,
            summary: \sprintf('%d group(s)', \count($items)),
        );
    }
}
