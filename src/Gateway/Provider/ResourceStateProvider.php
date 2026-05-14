<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;

/**
 * State provider for Mosyca True-REST GET operations (V0.11).
 *
 * Handles all read (GET / HEAD) requests dispatched by
 * {@see \Mosyca\Core\Gateway\Metadata\ResourceMetadataFactory}.
 *
 * URI variables injected from the route (e.g. `{tenant}`, `{id}`) are available
 * in `$uriVariables` and MUST be merged into the action payload before the action
 * is executed, so that the action remains completely protocol-agnostic.
 *
 * This is a stub — real dispatch logic (ActionRegistry lookup, validation,
 * ExecutionContext construction) will be implemented in the next slice.
 *
 * @implements ProviderInterface<object>
 */
final readonly class ResourceStateProvider implements ProviderInterface
{
    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return object|array<string, mixed>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        // TODO: resolve Resource + Action from $operation metadata, merge $uriVariables
        //       into payload, build ExecutionContext, run action, return result.
        return null;
    }
}
