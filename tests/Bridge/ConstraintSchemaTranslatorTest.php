<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Bridge;

use Mosyca\Core\Bridge\ConstraintSchemaTranslator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mosyca\Core\Bridge\ConstraintSchemaTranslator
 */
final class ConstraintSchemaTranslatorTest extends TestCase
{
    private ConstraintSchemaTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new ConstraintSchemaTranslator();
    }

    public function testTranslateEmptyParametersIncludesTenantOnly(): void
    {
        $schema = $this->translator->translate([]);

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('tenant', $schema['properties']);
        self::assertSame(['tenant'], $schema['required']);
    }

    public function testTenantPropertyIsAlwaysString(): void
    {
        $schema = $this->translator->translate([]);

        self::assertIsArray($schema['properties']['tenant']);
        self::assertSame('string', $schema['properties']['tenant']['type']);
        self::assertSame('The target Mosyca instance/session.', $schema['properties']['tenant']['description']);
    }

    public function testTenantIsAlwaysFirstInRequired(): void
    {
        $schema = $this->translator->translate([
            'msg' => ['type' => 'string', 'required' => true],
        ]);

        self::assertIsArray($schema['required']);
        self::assertSame('tenant', $schema['required'][0]);
    }

    public function testTenantEnumInjectedWhenProvided(): void
    {
        $schema = $this->translator->translate([], ['default', 'shop-berlin']);

        self::assertIsArray($schema['properties']['tenant']);
        self::assertSame(['default', 'shop-berlin'], $schema['properties']['tenant']['enum']);
    }

    public function testTenantHasNoEnumWhenEnumIsEmpty(): void
    {
        $schema = $this->translator->translate([]);

        self::assertIsArray($schema['properties']['tenant']);
        self::assertArrayNotHasKey('enum', $schema['properties']['tenant']);
    }

    public function testTranslateStringParameter(): void
    {
        $schema = $this->translator->translate([
            'name' => ['type' => 'string', 'description' => 'A human name', 'required' => false],
        ]);

        self::assertIsArray($schema['properties']['name']);
        self::assertSame('string', $schema['properties']['name']['type']);
        self::assertSame('A human name', $schema['properties']['name']['description']);
        self::assertNotContains('name', $schema['required']);
    }

    public function testTranslateRequiredParameter(): void
    {
        $schema = $this->translator->translate([
            'order_id' => ['type' => 'string', 'required' => true],
        ]);

        self::assertContains('order_id', $schema['required']);
    }

    public function testTranslateOptionalParameter(): void
    {
        $schema = $this->translator->translate([
            'limit' => ['type' => 'integer', 'required' => false],
        ]);

        self::assertNotContains('limit', $schema['required']);
    }

    public function testTranslateIntAliases(): void
    {
        $schema = $this->translator->translate([
            'a' => ['type' => 'int', 'required' => false],
            'b' => ['type' => 'integer', 'required' => false],
        ]);

        self::assertIsArray($schema['properties']['a']);
        self::assertIsArray($schema['properties']['b']);
        self::assertSame('integer', $schema['properties']['a']['type']);
        self::assertSame('integer', $schema['properties']['b']['type']);
    }

    public function testTranslateBooleanAliases(): void
    {
        $schema = $this->translator->translate([
            'a' => ['type' => 'bool', 'required' => false],
            'b' => ['type' => 'boolean', 'required' => false],
        ]);

        self::assertIsArray($schema['properties']['a']);
        self::assertIsArray($schema['properties']['b']);
        self::assertSame('boolean', $schema['properties']['a']['type']);
        self::assertSame('boolean', $schema['properties']['b']['type']);
    }

    public function testTranslateArrayType(): void
    {
        $schema = $this->translator->translate([
            'items' => ['type' => 'array', 'required' => false],
        ]);

        self::assertIsArray($schema['properties']['items']);
        self::assertSame('array', $schema['properties']['items']['type']);
    }

    public function testUnknownTypeFallsBackToString(): void
    {
        $schema = $this->translator->translate([
            'x' => ['type' => 'object', 'required' => false],
        ]);

        self::assertIsArray($schema['properties']['x']);
        self::assertSame('string', $schema['properties']['x']['type']);
    }

    public function testTranslateEnumParameter(): void
    {
        $schema = $this->translator->translate([
            'status' => ['type' => 'string', 'required' => true, 'enum' => ['active', 'inactive', 'pending']],
        ]);

        self::assertIsArray($schema['properties']['status']);
        self::assertSame(['active', 'inactive', 'pending'], $schema['properties']['status']['enum']);
        self::assertContains('status', $schema['required']);
    }

    public function testExampleMappedToExamplesArray(): void
    {
        $schema = $this->translator->translate([
            'msg' => ['type' => 'string', 'required' => false, 'example' => 'hello world'],
        ]);

        self::assertIsArray($schema['properties']['msg']);
        self::assertSame(['hello world'], $schema['properties']['msg']['examples']);
    }

    public function testMultipleRequiredAndOptionalParams(): void
    {
        $schema = $this->translator->translate([
            'id' => ['type' => 'string', 'required' => true],
            'limit' => ['type' => 'integer', 'required' => false],
            'active' => ['type' => 'boolean', 'required' => true],
        ]);

        self::assertContains('id', $schema['required']);
        self::assertContains('active', $schema['required']);
        self::assertNotContains('limit', $schema['required']);
    }

    public function testSchemaStructureIsComplete(): void
    {
        $schema = $this->translator->translate([
            'message' => ['type' => 'string', 'required' => true],
        ]);

        self::assertArrayHasKey('type', $schema);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('required', $schema);
        self::assertSame('object', $schema['type']);
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['required']);
    }
}
