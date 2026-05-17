<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Identity;

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Identity\Provider\TenantProviderInterface;

/**
 * List all tenants with optional pagination (ADR 1.5.2 — read-only).
 *
 * OAI: mosyca:tenant:list
 */
#[AsAction]
final class TenantListAction implements \Mosyca\Core\Action\ActionInterface
{
    use ActionTrait;

    public function __construct(private readonly TenantProviderInterface $tenants)
    {
    }

    public function getName(): string
    {
        return 'mosyca:tenant:list';
    }

    public function getDescription(): string
    {
        return 'Returns a paginated list of all tenants.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Lists all tenants registered in the identity configuration.

        ## Parameters
        - limit  (optional, default 50): maximum number of results
        - offset (optional, default 0):  starting offset

        ## Returns
        Array of { slug, name, metadata } objects.
        USAGE;
    }

    public function getParameters(): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of tenants to return (default 50).',
                'required' => false,
                'default' => 50,
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Starting offset for pagination (default 0).',
                'required' => false,
                'default' => 0,
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
        return ['identity', 'tenant'];
    }

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;

        $dtos = $this->tenants->getTenants($offset, $limit);

        $items = array_map(
            static fn ($dto): array => ['slug' => $dto->slug, 'name' => $dto->name, 'metadata' => $dto->metadata],
            $dtos,
        );

        return ActionResult::ok(
            data: $items,
            summary: \sprintf('%d tenant(s)', \count($items)),
        );
    }
}
