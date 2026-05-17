<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Identity;

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Identity\Provider\UserProviderInterface;

/**
 * Read a single user by id (ADR 1.5.2 — read-only).
 *
 * OAI: mosyca:user:read
 */
#[AsAction]
final class UserReadAction implements \Mosyca\Core\Action\ActionInterface
{
    use ActionTrait;

    public function __construct(private readonly UserProviderInterface $users)
    {
    }

    public function getName(): string
    {
        return 'mosyca:user:read';
    }

    public function getDescription(): string
    {
        return 'Returns a single user by id.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Fetches one user from the identity registry by id (slug key from YAML).

        ## Parameters
        - id (required): the user id / slug (e.g. operator_roland)

        ## Returns
        - id, email, displayName, groups, allowedTenants

        ## Errors
        - NOT_FOUND: no user with that id exists
        USAGE;
    }

    public function getParameters(): array
    {
        return [
            'id' => [
                'type' => 'string',
                'description' => 'User id / slug (e.g. operator_roland).',
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
        return ['identity', 'user'];
    }

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        $dto = $this->users->getUser((string) $args['id']);

        if (null === $dto) {
            return ActionResult::failure(
                'User not found.',
                'NOT_FOUND',
                'Provide a valid user id.',
            );
        }

        return ActionResult::ok(
            data: [
                'id' => $dto->id,
                'email' => $dto->email,
                'displayName' => $dto->displayName,
                'groups' => $dto->groups,
                'allowedTenants' => $dto->allowedTenants,
            ],
            summary: "User: {$dto->email}",
        );
    }
}
