<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Metadata;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use Mosyca\Core\Gateway\Processor\ResourceStateProcessor;
use Mosyca\Core\Gateway\Provider\ResourceStateProvider;
use Mosyca\Core\Gateway\Resource\MosycaResource;
use Mosyca\Core\Resource\ResourceRegistry;

/**
 * API Platform metadata factory decorator for the Mosyca True-REST Resource Gateway (V0.11).
 *
 * Decorates the built-in `api_platform.metadata.resource.metadata_collection_factory`.
 * For every resource class OTHER than {@see MosycaResource}, it delegates to the inner
 * factory unchanged. For {@see MosycaResource::class}, it generates one
 * {@see HttpOperation} per operation declared in every registered
 * {@see \Mosyca\Core\Resource\AbstractResource}.
 *
 * ## Operation name convention
 *
 *   {pluginNamespace}_{resourceName}_{operationSlug}
 *   e.g. "mosyca_system_ping", "shopware6_order_read"
 *
 * ## URI template convention
 *
 *   /v1/{pluginNamespace}/{tenant}/{resourceName}{path}
 *
 * `pluginNamespace` and `resourceName` are embedded as CONCRETE literals
 * (not URI template variables). Only `{tenant}` and any path-level
 * placeholders defined in {@see \Mosyca\Core\Resource\AbstractResource::getOperations()}
 * (e.g. `{id}`) remain as URI template variables.
 *
 * ## Routing
 *
 *   GET / HEAD  → ResourceStateProvider
 *   POST / PUT / PATCH / DELETE → ResourceStateProcessor (input: false, output: false)
 *
 * ## openapiContext support
 *
 *   Each operation entry in `getOperations()` may include an optional
 *   `openapiContext` key (`array<string, mixed>`) that is forwarded verbatim
 *   to the {@see HttpOperation} constructor — giving connectors full control
 *   over the generated OpenAPI / Swagger documentation.
 *
 * @see ResourceRegistry
 * @see MosycaResource
 * @see \Mosyca\Core\Resource\AbstractResource::getOperations()
 */
final readonly class ResourceMetadataFactory implements ResourceMetadataCollectionFactoryInterface
{
    public function __construct(
        private ResourceMetadataCollectionFactoryInterface $inner,
        private ResourceRegistry $registry,
    ) {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        if (MosycaResource::class !== $resourceClass) {
            return $this->inner->create($resourceClass);
        }

        /** @var array<string, HttpOperation> $operations */
        $operations = [];

        foreach ($this->registry->all() as $resource) {
            $ns = $resource->getPluginNamespace();
            $resName = $resource->getName();
            $description = $resource->getDescription();

            foreach ($resource->getOperations() as $opSlug => $opDef) {
                $opName = $ns.'_'.$resName.'_'.$opSlug;
                $uriTemplate = '/v1/'.$ns.'/{tenant}/'.$resName.$opDef['path'];
                $method = strtoupper($opDef['method']);
                $isWrite = \in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

                // openapiContext is an optional key — access safely to avoid PHPStan offset errors.
                $rawContext = $opDef['openapiContext'] ?? null;
                $openapiCtx = \is_array($rawContext) ? $rawContext : null;

                // mosyca_action carries the action's FQCN so the adapter can
                // resolve it via ActionRegistry::getByClass() without parsing the route.
                $extraProperties = ['mosyca_action' => $opDef['action']];

                if ($isWrite) {
                    $operations[$opName] = new HttpOperation(
                        method: $method,
                        uriTemplate: $uriTemplate,
                        openapiContext: $openapiCtx,
                        description: $description,
                        input: false,
                        output: false,
                        processor: ResourceStateProcessor::class,
                        formats: ['json'],
                        paginationEnabled: false,
                        extraProperties: $extraProperties,
                    );
                } else {
                    $operations[$opName] = new HttpOperation(
                        method: $method,
                        uriTemplate: $uriTemplate,
                        openapiContext: $openapiCtx,
                        description: $description,
                        provider: ResourceStateProvider::class,
                        formats: ['json'],
                        paginationEnabled: false,
                        extraProperties: $extraProperties,
                    );
                }
            }
        }

        $apiResource = new ApiResource(
            operations: $operations,
            formats: ['json'],
            paginationEnabled: false,
        );

        return new ResourceMetadataCollection(MosycaResource::class, [$apiResource]);
    }
}
