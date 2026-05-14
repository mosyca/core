<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Context\ExecutionContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * State provider for Mosyca True-REST GET/HEAD operations (V0.12).
 *
 * Dispatches GET and HEAD requests to their registered Action via ActionRegistry.
 * This is the bridge between the REST domain and protocol-agnostic Actions.
 *
 * ## Payload construction
 *
 * All incoming data is merged into a single flat `$payload` array before the
 * Action sees it, keeping Actions completely HTTP-agnostic:
 *
 *   1. `$request->query->all()` — query string parameters
 *   2. `$uriVariables`          — URI template variables (e.g. `{tenant}`, `{id}`)
 *      (URI variables take precedence over query string on key collision)
 *
 * ## Action resolution
 *
 * The target Action's FQCN is stored in `HttpOperation::extraProperties['mosyca_action']`
 * by {@see \Mosyca\Core\Gateway\Metadata\ResourceMetadataFactory} and resolved
 * via {@see ActionRegistry::getByClass()}.
 *
 * @implements ProviderInterface<object>
 */
final readonly class ResourceStateProvider implements ProviderInterface
{
    public function __construct(
        private ActionRegistry $registry,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return \Mosyca\Core\Action\ActionResult
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $extras = $operation->getExtraProperties();
        $actionClass = $extras['mosyca_action'] ?? null;

        if (!\is_string($actionClass) || '' === $actionClass) {
            throw new \RuntimeException(\sprintf("Operation '%s' is missing the 'mosyca_action' extra property. ".'Ensure ResourceMetadataFactory generated this operation.', $operation->getName() ?? '(unknown)'));
        }

        $action = $this->registry->getByClass($actionClass);

        // Merge query params first, then URI variables so routing params win on collision.
        $request = $this->requestStack->getCurrentRequest();
        $queryParams = null !== $request ? $request->query->all() : [];

        /** @var array<string, mixed> $payload */
        $payload = array_merge($queryParams, $uriVariables);

        $tenantId = \is_string($uriVariables['tenant'] ?? null) ? (string) $uriVariables['tenant'] : 'default';

        $executionContext = new ExecutionContext(
            tenantId: $tenantId,
            userId: null,
            actingUserId: null,
            delegated: false,
            authenticated: false,
            aclBypassed: false,
        );

        return $action->execute($payload, $executionContext);
    }
}
