<?php

declare(strict_types=1);

namespace Mosyca\Core\Scaffold;

/**
 * Value object describing one scaffold action to be generated.
 *
 * Each instance represents a single OpenAPI endpoint that will be turned into
 * a file implementing ScaffoldActionInterface.
 *
 * Immutable — constructed once by ScaffoldFromOpenApiCommand, consumed by ActionStubWriter.
 */
final class ScaffoldDescriptor
{
    /**
     * @param string                              $httpMethod  HTTP verb: GET, POST, PUT, PATCH, DELETE
     * @param string                              $path        Original OpenAPI path, e.g. /revenue/monthly
     * @param string                              $connector   Connector slug, e.g. myapp
     * @param string                              $className   PHP class name, e.g. GetRevenueMonthlyAction
     * @param string                              $namespace   Fully-qualified namespace for the generated class
     * @param string                              $actionName  Mosyca action name, e.g. scaffold:myapp:revenue-monthly
     * @param string                              $description One-line description from OpenAPI summary/description
     * @param array<string, array<string, mixed>> $parameters  Normalised parameter map keyed by parameter name
     */
    public function __construct(
        public readonly string $httpMethod,
        public readonly string $path,
        public readonly string $connector,
        public readonly string $className,
        public readonly string $namespace,
        public readonly string $actionName,
        public readonly string $description,
        public readonly array $parameters,
    ) {
    }

    /**
     * Whether this endpoint has side effects (not GET or HEAD).
     */
    public function isMutating(): bool
    {
        return !\in_array(strtoupper($this->httpMethod), ['GET', 'HEAD'], true);
    }

    /**
     * Relative filename for the generated class, e.g. GetRevenueMonthlyAction.php.
     */
    public function getFileName(): string
    {
        return $this->className.'.php';
    }
}
