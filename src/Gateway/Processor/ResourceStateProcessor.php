<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * State processor for Mosyca True-REST write operations (V0.11).
 *
 * Handles all mutating (POST / PUT / PATCH / DELETE) requests dispatched by
 * {@see \Mosyca\Core\Gateway\Metadata\ResourceMetadataFactory}.
 *
 * URI variables injected from the route (e.g. `{tenant}`, `{id}`) are available
 * in `$uriVariables` and MUST be merged into the action payload before the action
 * is executed, so that the action remains completely protocol-agnostic.
 *
 * This is a stub — real dispatch logic (ActionRegistry lookup, validation,
 * ExecutionContext construction, ACL check, Ledger write) will be implemented
 * in the next slice.
 *
 * @implements ProcessorInterface<mixed, Response>
 */
final readonly class ResourceStateProcessor implements ProcessorInterface
{
    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        // TODO: resolve Resource + Action from $operation metadata, merge $uriVariables
        //       into payload, build ExecutionContext, run ACL check, execute action,
        //       write to Ledger, return rendered JsonResponse.
        return new JsonResponse(['status' => 'not_implemented'], Response::HTTP_NOT_IMPLEMENTED);
    }
}
