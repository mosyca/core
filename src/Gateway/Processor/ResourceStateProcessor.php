<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Context\ExecutionContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * State processor for Mosyca True-REST write operations (V0.12).
 *
 * Dispatches POST, PUT, PATCH, and DELETE requests to their registered Action
 * via ActionRegistry. This is the bridge between the REST domain and
 * protocol-agnostic Actions.
 *
 * ## Payload construction
 *
 * All incoming data is merged into a single flat `$payload` array before the
 * Action sees it, keeping Actions completely HTTP-agnostic:
 *
 *   1. `$data` (deserialized request body) — array or object properties
 *   2. `$uriVariables`                      — URI template variables (e.g. `{tenant}`, `{id}`)
 *      (URI variables take precedence over body on key collision)
 *
 * ## Action resolution
 *
 * The target Action's FQCN is stored in `HttpOperation::extraProperties['mosyca_action']`
 * by {@see \Mosyca\Core\Gateway\Metadata\ResourceMetadataFactory} and resolved
 * via {@see ActionRegistry::getByClass()}.
 *
 * @implements ProcessorInterface<mixed, Response>
 */
final readonly class ResourceStateProcessor implements ProcessorInterface
{
    public function __construct(
        private ActionRegistry $registry,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $extras = $operation->getExtraProperties();
        $actionClass = $extras['mosyca_action'] ?? null;

        if (!\is_string($actionClass) || '' === $actionClass) {
            throw new \RuntimeException(\sprintf("Operation '%s' is missing the 'mosyca_action' extra property. ".'Ensure ResourceMetadataFactory generated this operation.', $operation->getName() ?? '(unknown)'));
        }

        $action = $this->registry->getByClass($actionClass);

        // Deserialize $data (request body) into a flat array.
        // input: false is set on write operations, so $data is null — fall back to reading
        // the raw request body directly in that case.
        if (\is_array($data)) {
            /** @var array<string, mixed> $bodyPayload */
            $bodyPayload = $data;
        } elseif (\is_object($data)) {
            /** @var array<string, mixed> $bodyPayload */
            $bodyPayload = (array) $data;
        } else {
            // input: false → API Platform passes null; read the JSON body ourselves.
            $request = $this->requestStack->getCurrentRequest();
            $raw = null !== $request ? json_decode($request->getContent(), true) : null;
            /** @var array<string, mixed> $bodyPayload */
            $bodyPayload = \is_array($raw) ? $raw : [];
        }

        // URI variables take precedence over body fields on key collision.
        /** @var array<string, mixed> $payload */
        $payload = array_merge($bodyPayload, $uriVariables);

        $tenantId = \is_string($uriVariables['tenant'] ?? null) ? (string) $uriVariables['tenant'] : 'default';

        $executionContext = new ExecutionContext(
            tenantId: $tenantId,
            userId: null,
            actingUserId: null,
            delegated: false,
            authenticated: false,
            aclBypassed: false,
        );

        $result = $action->execute($payload, $executionContext);

        return new JsonResponse(
            $result->toArray(),
            $result->success ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
