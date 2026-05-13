<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Scaffold;

use Mosyca\Core\Scaffold\ActionStubWriter;
use Mosyca\Core\Scaffold\ParameterConstraintMapper;
use Mosyca\Core\Scaffold\ScaffoldDescriptor;
use PHPUnit\Framework\TestCase;

final class ActionStubWriterTest extends TestCase
{
    private ActionStubWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new ActionStubWriter(new ParameterConstraintMapper());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, array<string, mixed>> $parameters
     */
    private function makeDescriptor(
        string $httpMethod = 'GET',
        string $path = '/items',
        array $parameters = [],
        string $connector = 'myapp',
        string $className = 'GetItemsAction',
        string $actionName = 'scaffold:myapp:items',
        string $description = 'List items',
    ): ScaffoldDescriptor {
        return new ScaffoldDescriptor(
            httpMethod: $httpMethod,
            path: $path,
            connector: $connector,
            className: $className,
            namespace: 'MyOrg\Connector\MyApp\Action\Scaffold',
            actionName: $actionName,
            description: $description,
            parameters: $parameters,
        );
    }

    // -----------------------------------------------------------------------
    // Mandatory PHP structure
    // -----------------------------------------------------------------------

    public function testRenderedStubHasDeclareStrictTypes(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('declare(strict_types=1);', $output);
    }

    public function testRenderedStubHasCorrectNamespace(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('namespace MyOrg\Connector\MyApp\Action\Scaffold;', $output);
    }

    public function testRenderedStubImplementsScaffoldActionInterface(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('implements ScaffoldActionInterface', $output);
    }

    public function testRenderedStubUsesActionTrait(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('use ActionTrait;', $output);
    }

    public function testRenderedStubHasAsActionAttribute(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('#[AsAction]', $output);
    }

    public function testRenderedStubIsFinalClass(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('final class GetItemsAction', $output);
    }

    // -----------------------------------------------------------------------
    // Use statements (alphabetical, Symfony CS-Fixer preset)
    // -----------------------------------------------------------------------

    public function testRenderedStubImportsRequiredSymbols(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('use Mosyca\Core\Action\ActionResult;', $output);
        self::assertStringContainsString('use Mosyca\Core\Action\ActionTrait;', $output);
        self::assertStringContainsString('use Mosyca\Core\Action\Attribute\AsAction;', $output);
        self::assertStringContainsString('use Mosyca\Core\Action\ScaffoldActionInterface;', $output);
        self::assertStringContainsString('use Mosyca\Core\Context\ExecutionContextInterface;', $output);
        self::assertStringContainsString('use Symfony\Component\Validator\Constraint;', $output);
        self::assertStringContainsString('use Symfony\Component\Validator\Constraints as Assert;', $output);
    }

    // -----------------------------------------------------------------------
    // Class docblock
    // -----------------------------------------------------------------------

    public function testRenderedStubDocblockContainsHttpMethodAndPath(): void
    {
        $output = $this->writer->render($this->makeDescriptor('POST', '/orders'));

        self::assertStringContainsString('POST /orders', $output);
        self::assertStringContainsString('SCAFFOLD ACTION', $output);
    }

    public function testRenderedStubDocblockContainsPromoteCommand(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('mosyca:scaffold:promote GetItemsAction', $output);
    }

    // -----------------------------------------------------------------------
    // getName()
    // -----------------------------------------------------------------------

    public function testRenderedStubContainsCorrectActionName(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString("return 'scaffold:myapp:items';", $output);
    }

    // -----------------------------------------------------------------------
    // isMutating()
    // -----------------------------------------------------------------------

    public function testGetActionIsMutatingFalse(): void
    {
        $output = $this->writer->render($this->makeDescriptor('GET'));

        self::assertStringContainsString('return false;', $output);
        self::assertStringNotContainsString('return true;', $output);
    }

    public function testPostActionIsMutatingTrue(): void
    {
        $output = $this->writer->render($this->makeDescriptor('POST', '/orders', [], 'myapp', 'PostOrdersAction', 'scaffold:myapp:orders'));

        self::assertStringContainsString('return true;', $output);
    }

    // -----------------------------------------------------------------------
    // execute() TODO comment
    // -----------------------------------------------------------------------

    public function testRenderedStubContainsTodoComment(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('TODO: Replace with your connector\'s ApiClient call', $output);
    }

    public function testRenderedStubExecuteReturnsNotImplementedSummary(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringContainsString('not implemented yet', $output);
        self::assertStringContainsString('ActionResult::ok(', $output);
    }

    // -----------------------------------------------------------------------
    // getParameters() — empty case
    // -----------------------------------------------------------------------

    public function testEmptyParametersRendersReturnEmptyArray(): void
    {
        $output = $this->writer->render($this->makeDescriptor(parameters: []));

        // The getParameters() method body should just return []
        self::assertStringContainsString('return [];', $output);
    }

    // -----------------------------------------------------------------------
    // getValidationConstraints() — empty case
    // -----------------------------------------------------------------------

    public function testEmptyParametersRendersReturnNull(): void
    {
        $output = $this->writer->render($this->makeDescriptor(parameters: []));

        self::assertStringContainsString('return null;', $output);
    }

    // -----------------------------------------------------------------------
    // getParameters() + getValidationConstraints() — with parameters
    // -----------------------------------------------------------------------

    public function testParametersAreRenderedInGetParameters(): void
    {
        $descriptor = $this->makeDescriptor(parameters: [
            'status' => ['type' => 'string', 'description' => 'Filter by status', 'required' => false, 'in' => 'query'],
        ]);

        $output = $this->writer->render($descriptor);

        self::assertStringContainsString("'status'", $output);
        self::assertStringContainsString("'type'        => 'string'", $output);
        self::assertStringContainsString("'description' => 'Filter by status'", $output);
        self::assertStringContainsString("'required'    => false", $output);
    }

    public function testParametersWithConstraintsRendersAssertCollection(): void
    {
        $descriptor = $this->makeDescriptor(parameters: [
            'limit' => ['type' => 'integer', 'description' => 'Page limit', 'required' => false, 'in' => 'query'],
        ]);

        $output = $this->writer->render($descriptor);

        self::assertStringContainsString('Assert\\Collection', $output);
        self::assertStringContainsString("'limit'", $output);
        self::assertStringContainsString('Assert\\Optional', $output);
    }

    public function testRequiredParameterRendersNotBlankInConstraints(): void
    {
        $descriptor = $this->makeDescriptor(parameters: [
            'order_id' => ['type' => 'string', 'description' => 'Order ID', 'required' => true, 'in' => 'path'],
        ]);

        $output = $this->writer->render($descriptor);

        self::assertStringContainsString('Assert\\NotBlank', $output);
        self::assertStringContainsString("'allowExtraFields' => false", $output);
    }

    // -----------------------------------------------------------------------
    // getDescription() — special chars escaped
    // -----------------------------------------------------------------------

    public function testDescriptionWithSingleQuoteIsEscaped(): void
    {
        $descriptor = $this->makeDescriptor(description: "Roland's endpoint");
        $output = $this->writer->render($descriptor);

        self::assertStringContainsString("Roland\\'s endpoint", $output);
    }

    // -----------------------------------------------------------------------
    // Output ends with newline
    // -----------------------------------------------------------------------

    public function testRenderedOutputEndsWithNewline(): void
    {
        $output = $this->writer->render($this->makeDescriptor());

        self::assertStringEndsWith("\n", $output);
    }
}
