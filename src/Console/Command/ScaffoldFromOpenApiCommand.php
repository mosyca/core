<?php

declare(strict_types=1);

namespace Mosyca\Core\Console\Command;

use Mosyca\Core\Scaffold\ActionStubWriter;
use Mosyca\Core\Scaffold\OpenApiSpecLoader;
use Mosyca\Core\Scaffold\ScaffoldDescriptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates Approach B scaffold action stubs from an OpenAPI 3.x specification.
 *
 * Each endpoint in the spec becomes one file implementing ScaffoldActionInterface.
 * Scaffold actions are:
 *   - Immediately registered as MCP Tools / CLI commands / REST endpoints
 *   - Permanently excluded from Depot caching (ScaffoldActionInterface enforces this)
 *   - Intended as a development stepping stone toward proper Approach A actions
 *
 * Usage:
 *   php bin/console mosyca:scaffold:from-openapi \
 *       --url=http://myapi/openapi.json \
 *       --connector=myapp \
 *       --namespace="MyOrg\Connector\MyApp\Action\Scaffold" \
 *       --output=src/Action/Scaffold/
 */
#[AsCommand(
    name: 'mosyca:scaffold:from-openapi',
    description: 'Generate scaffold action stubs from an OpenAPI 3.x specification',
)]
final class ScaffoldFromOpenApiCommand extends Command
{
    /** HTTP methods that produce scaffold actions */
    private const SUPPORTED_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public function __construct(
        private readonly OpenApiSpecLoader $loader,
        private readonly ActionStubWriter $writer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'OpenAPI spec URL or local file path')
            ->addOption('connector', null, InputOption::VALUE_REQUIRED, 'Connector slug (e.g. myapp)')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'PHP namespace for generated classes (e.g. "MyOrg\\Connector\\MyApp\\Action\\Scaffold")')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory for generated PHP files')
            ->addOption('auth-header', null, InputOption::VALUE_OPTIONAL, 'Authorization header value for fetching authenticated specs')
            ->addOption('only-get', null, InputOption::VALUE_NONE, 'Only scaffold GET endpoints (readonly, non-mutating)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print generated stubs without writing files')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Validate required options
        $url = $input->getOption('url');
        $connector = $input->getOption('connector');
        $namespace = $input->getOption('namespace');
        $outputDir = $input->getOption('output');

        if (!\is_string($url) || '' === $url) {
            $io->error('--url is required. Provide an OpenAPI spec URL or local file path.');

            return Command::FAILURE;
        }
        if (!\is_string($connector) || '' === $connector) {
            $io->error('--connector is required. Example: --connector=myapp');

            return Command::FAILURE;
        }
        if (!\is_string($namespace) || '' === $namespace) {
            $io->error('--namespace is required. Example: --namespace="MyOrg\\Connector\\MyApp\\Action\\Scaffold"');

            return Command::FAILURE;
        }
        if (!\is_string($outputDir) || '' === $outputDir) {
            $io->error('--output is required. Example: --output=src/Action/Scaffold/');

            return Command::FAILURE;
        }

        $authHeader = $input->getOption('auth-header');
        $authHeader = \is_string($authHeader) && '' !== $authHeader ? $authHeader : null;
        $onlyGet = (bool) $input->getOption('only-get');
        $dryRun = (bool) $input->getOption('dry-run');

        // Load + parse the OpenAPI spec
        $io->section('Loading OpenAPI spec');
        try {
            $spec = $this->loader->load($url, $authHeader);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $specVersion = \is_string($spec['openapi'] ?? null) ? $spec['openapi'] : '?';
        $specTitle = isset($spec['info']['title']) && \is_string($spec['info']['title']) ? $spec['info']['title'] : '(untitled)';
        $io->text("Loaded: <info>{$specTitle}</info> (OpenAPI {$specVersion})");

        // Build descriptors from paths
        $descriptors = $this->buildDescriptors($spec, $connector, $namespace, $onlyGet);

        if (empty($descriptors)) {
            $io->warning('No endpoints found in the spec'.('' !== ($onlyGet ? ' (--only-get is active)' : '')).'.');

            return Command::SUCCESS;
        }

        $io->section(\sprintf('Generating %d scaffold action(s)', \count($descriptors)));

        if ($dryRun) {
            $io->comment('DRY RUN — no files will be written.');
        }

        $rows = [];
        $written = 0;
        foreach ($descriptors as $descriptor) {
            $status = '✅ OK';
            try {
                if ($dryRun) {
                    $io->text('<fg=yellow>[DRY RUN]</> '.$descriptor->className.' → '.$outputDir.'/'.$descriptor->getFileName());
                    if ($output->isVeryVerbose()) {
                        $io->block($this->writer->render($descriptor), null, 'fg=gray', '  ');
                    }
                } else {
                    $this->writer->write($descriptor, $outputDir);
                    ++$written;
                }
            } catch (\RuntimeException $e) {
                $status = '<error>FAILED: '.$e->getMessage().'</error>';
            }

            $rows[] = [
                $descriptor->httpMethod,
                $descriptor->path,
                $descriptor->className,
                $descriptor->actionName,
                $status,
            ];
        }

        $io->table(['Method', 'Path', 'Class', 'Action name', 'Status'], $rows);

        if (!$dryRun) {
            $io->success(\sprintf('%d scaffold action(s) written to %s', $written, $outputDir));
            $io->note([
                'Next steps:',
                '1. Register your connector\'s ApiClient in config/services.yaml',
                '2. Inject it into each generated action\'s constructor',
                '3. Implement the execute() body (marked TODO)',
                '4. Run: php bin/console mosyca:action:list --tag=scaffold',
                '5. When ready: php bin/console mosyca:scaffold:promote <ClassName>',
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Build ScaffoldDescriptor instances from an OpenAPI paths object.
     *
     * @param array<string, mixed> $spec
     *
     * @return ScaffoldDescriptor[]
     */
    private function buildDescriptors(array $spec, string $connector, string $namespace, bool $onlyGet): array
    {
        $paths = isset($spec['paths']) && \is_array($spec['paths']) ? $spec['paths'] : [];
        $descriptors = [];

        foreach ($paths as $path => $pathItem) {
            if (!\is_string($path) || !\is_array($pathItem)) {
                continue;
            }

            foreach (self::SUPPORTED_METHODS as $method) {
                if ($onlyGet && 'get' !== $method) {
                    continue;
                }

                if (!isset($pathItem[$method]) || !\is_array($pathItem[$method])) {
                    continue;
                }

                $operation = $pathItem[$method];
                $descriptors[] = $this->buildDescriptor($method, $path, $operation, $connector, $namespace, $pathItem);
            }
        }

        return $descriptors;
    }

    /**
     * @param array<string, mixed> $operation OpenAPI operation object
     * @param array<string, mixed> $pathItem  OpenAPI path item (may carry shared parameters)
     */
    private function buildDescriptor(
        string $method,
        string $path,
        array $operation,
        string $connector,
        string $namespace,
        array $pathItem,
    ): ScaffoldDescriptor {
        $description = $this->extractDescription($operation);
        $parameters = $this->extractParameters($operation, $pathItem, $method);

        [$className, $actionName] = $this->deriveNames($method, $path, $connector);

        return new ScaffoldDescriptor(
            httpMethod: strtoupper($method),
            path: $path,
            connector: $connector,
            className: $className,
            namespace: $namespace,
            actionName: $actionName,
            description: $description,
            parameters: $parameters,
        );
    }

    /**
     * Extract a one-line description from an OpenAPI operation.
     *
     * @param array<string, mixed> $operation
     */
    private function extractDescription(array $operation): string
    {
        if (isset($operation['summary']) && \is_string($operation['summary']) && '' !== $operation['summary']) {
            return $operation['summary'];
        }

        if (isset($operation['description']) && \is_string($operation['description'])) {
            // Take only first line of multi-line descriptions
            return explode("\n", $operation['description'])[0];
        }

        return 'No description.';
    }

    /**
     * Extract and normalise parameters from an operation + shared path-item parameters.
     *
     * Merges path-level parameters with operation-level ones (operation wins on name collision).
     * Handles both query and path parameters. For POST/PUT/PATCH, also reads requestBody schema.
     *
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $pathItem
     *
     * @return array<string, array<string, mixed>>
     */
    private function extractParameters(array $operation, array $pathItem, string $method): array
    {
        // Merge path-item params + operation params (operation overrides)
        $rawParams = [];
        foreach ([[$pathItem['parameters'] ?? []], [$operation['parameters'] ?? []]] as [$source]) {
            if (!\is_array($source)) {
                continue;
            }
            foreach ($source as $param) {
                if (\is_array($param) && isset($param['name']) && \is_string($param['name'])) {
                    $rawParams[$param['name']] = $param;
                }
            }
        }

        $normalized = [];
        foreach ($rawParams as $name => $param) {
            $schema = isset($param['schema']) && \is_array($param['schema']) ? $param['schema'] : [];
            $required = (bool) ($param['required'] ?? false);
            $in = isset($param['in']) && \is_string($param['in']) ? $param['in'] : 'query';

            // Skip header parameters — not passed through the action payload
            if ('header' === $in) {
                continue;
            }

            $normalized[$name] = [
                'type' => isset($schema['type']) && \is_string($schema['type']) ? $schema['type'] : 'string',
                'description' => isset($param['description']) && \is_string($param['description']) ? $param['description'] : '',
                'required' => $required || 'path' === $in,  // path params are always required
                'in' => $in,
            ];

            // Forward enum, minimum, maximum, format, minLength, maxLength, pattern
            foreach (['enum', 'minimum', 'maximum', 'format', 'minLength', 'maxLength', 'pattern'] as $key) {
                if (isset($schema[$key])) {
                    $normalized[$name][$key] = $schema[$key];
                }
            }
        }

        // For POST / PUT / PATCH: pull fields from requestBody → content → application/json → schema → properties
        if (\in_array($method, ['post', 'put', 'patch'], true)) {
            $bodySchema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
            if (\is_array($bodySchema)) {
                $required = isset($bodySchema['required']) && \is_array($bodySchema['required']) ? $bodySchema['required'] : [];
                $properties = isset($bodySchema['properties']) && \is_array($bodySchema['properties']) ? $bodySchema['properties'] : [];

                foreach ($properties as $propName => $propSchema) {
                    if (!\is_string($propName) || !\is_array($propSchema)) {
                        continue;
                    }
                    $normalized[$propName] = [
                        'type' => isset($propSchema['type']) && \is_string($propSchema['type']) ? $propSchema['type'] : 'string',
                        'description' => isset($propSchema['description']) && \is_string($propSchema['description']) ? $propSchema['description'] : '',
                        'required' => \in_array($propName, $required, true),
                        'in' => 'body',
                    ];
                    foreach (['enum', 'minimum', 'maximum', 'format', 'minLength', 'maxLength', 'pattern'] as $key) {
                        if (isset($propSchema[$key])) {
                            $normalized[$propName][$key] = $propSchema[$key];
                        }
                    }
                }
            }
        }

        return $normalized;
    }

    /**
     * Derive the PHP class name and Mosyca action name from an HTTP method + path.
     *
     * GET  /revenue/monthly    → GetRevenueMonthlyAction,  scaffold:myapp:revenue-monthly
     * POST /orders/{id}/cancel → PostOrdersCancelAction,   scaffold:myapp:orders-cancel
     * GET  /items              → GetItemsAction,           scaffold:myapp:items
     *
     * @return array{string, string} [className, actionName]
     */
    private function deriveNames(string $method, string $path, string $connector): array
    {
        // Strip path-parameter placeholders: /orders/{id}/cancel → /orders/cancel
        $clean = (string) preg_replace('/\{[^}]+\}/', '', $path);

        // Split on / and filter empty segments
        $segments = array_values(array_filter(explode('/', $clean)));

        if (empty($segments)) {
            $segments = ['root'];
        }

        // Class name: <Method><Seg1><Seg2>…Action  (each segment PascalCase)
        $classParts = [ucfirst(strtolower($method))];
        foreach ($segments as $seg) {
            // Convert snake_case or kebab-case segments to PascalCase
            $classParts[] = str_replace(['-', '_'], '', ucwords($seg, '-_'));
        }
        $className = implode('', $classParts).'Action';

        // Action name: scaffold:<connector>:<seg1>-<seg2>-…
        $slug = implode('-', array_map('strtolower', $segments));
        $actionName = "scaffold:{$connector}:{$slug}";

        return [$className, $actionName];
    }
}
