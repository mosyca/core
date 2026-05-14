<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Resource;

use ApiPlatform\Metadata\ApiResource;

/**
 * API Platform backing class for the Mosyca True-REST Resource Gateway (V0.11).
 *
 * This class is intentionally empty. Its sole purpose is to give API Platform
 * a concrete PHP class to discover. The `operations: []` array is replaced at
 * runtime by {@see \Mosyca\Core\Gateway\Metadata\ResourceMetadataFactory}, which
 * reads the {@see \Mosyca\Core\Resource\ResourceRegistry} and generates one
 * {@see \ApiPlatform\Metadata\HttpOperation} per operation defined in each resource.
 *
 * ## URI pattern
 *
 *   GET/HEAD /v1/{pluginNamespace}/{tenant}/{resourceName}{path}
 *            → {@see \Mosyca\Core\Gateway\Provider\ResourceStateProvider}
 *
 *   POST/PUT/PATCH/DELETE /v1/{pluginNamespace}/{tenant}/{resourceName}{path}
 *            → {@see \Mosyca\Core\Gateway\Processor\ResourceStateProcessor}
 *
 * `pluginNamespace` and `resourceName` are embedded LITERALLY per operation
 * (they are not URI template variables). Only `{tenant}` and any path-level
 * placeholders (e.g. `{id}`) are URI template variables.
 *
 * @see \Mosyca\Core\Gateway\Metadata\ResourceMetadataFactory
 * @see \Mosyca\Core\Resource\AbstractResource::getOperations()
 */
#[ApiResource(
    shortName: 'MosycaResource',
    description: 'Mosyca Resource REST endpoints — generated from ResourceRegistry.',
    operations: [], // Intentionally empty, filled by ResourceMetadataFactory
    formats: ['json'],
    paginationEnabled: false,
)]
final class MosycaResource
{
}
