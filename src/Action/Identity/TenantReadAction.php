<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Identity;

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Identity\Provider\TenantProviderInterface;

/**
 * Read a single tenant by slug (ADR 1.5.2 — read-only).
 *
 * OAI: mosyca:tenant:read
 */
#[AsAction]
final class TenantReadAction implements \Mosyca\Core\Action\ActionInterface
{
    use ActionTrait;

    public function __construct(private readonly TenantProviderInterface $tenants)
    {
    }

    public function getName(): string
    {
        return 'mosyca:tenant:read';
    }

    public function getDescription(): string
    {
        return 'Returns a single tenant by slug.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Fetches one tenant from the identity registry by its slug.

        ## Parameters
        - id (required): the tenant slug (e.g. demecan_gmbh)

        ## Returns
        - slug, name, metadata

        ## Errors
        - NOT_FOUND: no tenant with that slug exists
        USAGE;
    }

    public function getParameters(): array
    {
        return [
            'id' => [
                'type' => 'string',
                'description' => 'Tenant slug (e.g. demecan_gmbh).',
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
        return ['identity', 'tenant'];
    }

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        $dto = $this->tenants->getTenant((string) $args['id']);

        if (null === $dto) {
            return ActionResult::failure(
                'Tenant not found.',
                'NOT_FOUND',
                'Provide a valid tenant slug.',
            );
        }

        return ActionResult::ok(
            data: ['slug' => $dto->slug, 'name' => $dto->name, 'metadata' => $dto->metadata],
            summary: "Tenant: {$dto->name}",
        );
    }
}
