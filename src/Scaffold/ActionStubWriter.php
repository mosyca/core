<?php

declare(strict_types=1);

namespace Mosyca\Core\Scaffold;

/**
 * Renders and writes a PHP scaffold action stub from a ScaffoldDescriptor.
 *
 * The generated class implements ScaffoldActionInterface, which:
 *   - Marks it as a development prototype excluded from Depot caching
 *   - Makes it invisible to production clearance levels (once V0.11 ACL is in place)
 *
 * Code generation uses plain PHP string building — no Twig runtime overhead,
 * no template file to keep in sync.
 */
final class ActionStubWriter
{
    public function __construct(
        private readonly ParameterConstraintMapper $mapper,
    ) {
    }

    /**
     * Render the complete PHP source for a scaffold action class.
     */
    public function render(ScaffoldDescriptor $descriptor): string
    {
        $lines = [];

        // File header
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = 'namespace '.$descriptor->namespace.';';
        $lines[] = '';

        // Use statements (always alphabetical — CS-Fixer Symfony preset)
        $lines[] = 'use Mosyca\\Core\\Action\\ActionResult;';
        $lines[] = 'use Mosyca\\Core\\Action\\ActionTrait;';
        $lines[] = 'use Mosyca\\Core\\Action\\Attribute\\AsAction;';
        $lines[] = 'use Mosyca\\Core\\Action\\ScaffoldActionInterface;';
        $lines[] = 'use Mosyca\\Core\\Context\\ExecutionContextInterface;';
        $lines[] = 'use Symfony\\Component\\Validator\\Constraint;';
        $lines[] = 'use Symfony\\Component\\Validator\\Constraints as Assert;';
        $lines[] = '';

        // Class docblock
        $date = date('Y-m-d');
        $lines[] = '/**';
        $lines[] = ' * SCAFFOLD ACTION — auto-generated from OpenAPI spec.';
        $lines[] = ' * Source endpoint: '.$descriptor->httpMethod.' '.$descriptor->path;
        $lines[] = ' * Generated: '.$date;
        $lines[] = ' *';
        $lines[] = ' * ⚠️  Approach B prototype — NOT for production.';
        $lines[] = ' *    Migrate to a proper Approach A action before shipping:';
        $lines[] = ' *    php bin/console mosyca:scaffold:promote '.$descriptor->className;
        $lines[] = ' */';

        // Class declaration
        $lines[] = '#[AsAction]';
        $lines[] = 'final class '.$descriptor->className.' implements ScaffoldActionInterface';
        $lines[] = '{';
        $lines[] = '    use ActionTrait;';
        $lines[] = '';

        // getName()
        $lines[] = '    public function getName(): string';
        $lines[] = '    {';
        $lines[] = "        return '".$descriptor->actionName."';";
        $lines[] = '    }';
        $lines[] = '';

        // getDescription()
        $safeDesc = addslashes($descriptor->description);
        $lines[] = '    public function getDescription(): string';
        $lines[] = '    {';
        $lines[] = "        return '[SCAFFOLD] {$descriptor->httpMethod} {$descriptor->path} – {$safeDesc}';";
        $lines[] = '    }';
        $lines[] = '';

        // getUsage()
        $lines[] = '    public function getUsage(): string';
        $lines[] = '    {';
        $lines[] = "        return '[SCAFFOLD] Direct proxy to {$descriptor->httpMethod} {$descriptor->path}. "
            ."Auto-generated. Migrate to Approach A before production use.';";
        $lines[] = '    }';
        $lines[] = '';

        // getParameters()
        $lines[] = '    /**';
        $lines[] = '     * @return array<string, array<string, mixed>>';
        $lines[] = '     */';
        $lines[] = '    public function getParameters(): array';
        $lines[] = '    {';
        if (empty($descriptor->parameters)) {
            $lines[] = '        return [];';
        } else {
            $lines[] = '        return [';
            foreach ($descriptor->parameters as $name => $schema) {
                $type = isset($schema['type']) && \is_string($schema['type']) ? $schema['type'] : 'string';
                $desc = addslashes(isset($schema['description']) && \is_string($schema['description']) ? $schema['description'] : '');
                $required = (bool) ($schema['required'] ?? false) ? 'true' : 'false';
                $lines[] = "            '{$name}' => [";
                $lines[] = "                'type'        => '{$type}',";
                $lines[] = "                'description' => '{$desc}',";
                $lines[] = "                'required'    => {$required},";
                $lines[] = '            ],';
            }
            $lines[] = '        ];';
        }
        $lines[] = '    }';
        $lines[] = '';

        // getValidationConstraints()
        $lines[] = '    /**';
        $lines[] = '     * Declarative validation — evaluated by Core BEFORE execute() is called.';
        $lines[] = '     * Auto-mapped from OpenAPI parameter schema by ParameterConstraintMapper.';
        $lines[] = '     */';
        $lines[] = '    public function getValidationConstraints(): ?Constraint';
        $lines[] = '    {';
        if (empty($descriptor->parameters)) {
            $lines[] = '        return null;';
        } else {
            $lines[] = '        return new Assert\\Collection([';
            $lines[] = "            'fields' => [";
            foreach ($descriptor->parameters as $name => $schema) {
                $required = (bool) ($schema['required'] ?? false);
                $constraints = $this->mapper->mapField($schema, $required);
                $lines[] = "                '{$name}' => {$constraints},";
            }
            $lines[] = '            ],';
            $lines[] = "            'allowExtraFields' => false,";
            $lines[] = '        ]);';
        }
        $lines[] = '    }';
        $lines[] = '';

        // isMutating()
        $mutating = $descriptor->isMutating() ? 'true' : 'false';
        $lines[] = '    public function isMutating(): bool';
        $lines[] = '    {';
        $lines[] = '        return '.$mutating.';';
        $lines[] = '    }';
        $lines[] = '';

        // execute()
        $lines[] = '    public function execute(array $payload, ExecutionContextInterface $context): ActionResult';
        $lines[] = '    {';
        $lines[] = '        // TODO: Replace with your connector\'s ApiClient call.';
        $lines[] = '        // Example: $response = $this->api->'.strtolower($descriptor->httpMethod).'(\''.addslashes($descriptor->path).'\', $payload);';
        $lines[] = '        return ActionResult::ok(';
        $lines[] = '            data:    [],';
        $lines[] = "            summary: '[scaffold] {$descriptor->httpMethod} {$descriptor->path} — not implemented yet',";
        $lines[] = '        );';
        $lines[] = '    }';
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * Render and write a scaffold stub to disk.
     *
     * @return string Absolute path of the written file
     *
     * @throws \RuntimeException When the directory cannot be created or the file cannot be written
     */
    public function write(ScaffoldDescriptor $descriptor, string $outputDir): string
    {
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                throw new \RuntimeException("Cannot create output directory: {$outputDir}");
            }
        }

        $filePath = rtrim($outputDir, '/').'/'.$descriptor->getFileName();
        $content = $this->render($descriptor);

        if (false === file_put_contents($filePath, $content)) {
            throw new \RuntimeException("Cannot write scaffold action: {$filePath}");
        }

        return $filePath;
    }
}
