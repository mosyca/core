<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Scaffold;

use Mosyca\Core\Scaffold\ParameterConstraintMapper;
use PHPUnit\Framework\TestCase;

/**
 * Covers every branching path in ParameterConstraintMapper::mapField().
 *
 * The mapper generates PHP source-code snippets (strings) — we assert on the
 * string content rather than evaluating the code, which keeps symfony/validator
 * out of core's test dependencies.
 */
final class ParameterConstraintMapperTest extends TestCase
{
    private ParameterConstraintMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ParameterConstraintMapper();
    }

    // -----------------------------------------------------------------------
    // Required fields
    // -----------------------------------------------------------------------

    public function testRequiredStringProducesNotBlankAndTypeSnippet(): void
    {
        $result = $this->mapper->mapField(['type' => 'string'], true);

        self::assertStringContainsString('new Assert\\NotBlank()', $result);
        self::assertStringContainsString("new Assert\\Type('string')", $result);
        // Multiple parts → array brackets
        self::assertStringStartsWith('[', $result);
        self::assertStringEndsWith(']', $result);
    }

    public function testRequiredIntegerProducesCorrectTypeSnippet(): void
    {
        $result = $this->mapper->mapField(['type' => 'integer'], true);

        self::assertStringContainsString('new Assert\\NotBlank()', $result);
        self::assertStringContainsString("new Assert\\Type('integer')", $result);
    }

    public function testRequiredBooleanProducesCorrectTypeSnippet(): void
    {
        $result = $this->mapper->mapField(['type' => 'boolean'], true);

        self::assertStringContainsString("new Assert\\Type('boolean')", $result);
    }

    public function testRequiredNumberMapsToFloatType(): void
    {
        $result = $this->mapper->mapField(['type' => 'number'], true);

        self::assertStringContainsString("new Assert\\Type('float')", $result);
    }

    public function testRequiredArrayMapsToArrayType(): void
    {
        $result = $this->mapper->mapField(['type' => 'array'], true);

        self::assertStringContainsString("new Assert\\Type('array')", $result);
    }

    public function testRequiredUnknownTypeFallsBackToString(): void
    {
        $result = $this->mapper->mapField(['type' => 'object'], true);

        self::assertStringContainsString("new Assert\\Type('string')", $result);
    }

    // -----------------------------------------------------------------------
    // Optional fields — wrapping logic
    // -----------------------------------------------------------------------

    public function testOptionalStringProducesSingleOptionalWrapper(): void
    {
        $result = $this->mapper->mapField(['type' => 'string'], false);

        self::assertSame("new Assert\\Optional(new Assert\\Type('string'))", $result);
    }

    public function testOptionalWithEmptySchemaStillProducesSingleOptionalWrapper(): void
    {
        // No type key → defaults to string
        $result = $this->mapper->mapField([], false);

        self::assertSame("new Assert\\Optional(new Assert\\Type('string'))", $result);
    }

    public function testOptionalWithMultipleConstraintsProducesArrayForm(): void
    {
        // string with enum → NotBlank is absent (optional), Type + Choice
        $result = $this->mapper->mapField(['type' => 'string', 'enum' => ['a', 'b']], false);

        self::assertStringStartsWith('new Assert\\Optional([', $result);
        self::assertStringContainsString("new Assert\\Type('string')", $result);
        self::assertStringContainsString("new Assert\\Choice(['a', 'b'])", $result);
    }

    public function testOptionalIntegerWithSingleTypeConstraintProducesSingleForm(): void
    {
        $result = $this->mapper->mapField(['type' => 'integer'], false);

        self::assertSame("new Assert\\Optional(new Assert\\Type('integer'))", $result);
    }

    // -----------------------------------------------------------------------
    // Enum constraint
    // -----------------------------------------------------------------------

    public function testEnumProducesChoiceConstraint(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'enum' => ['active', 'inactive']], false);

        self::assertStringContainsString("new Assert\\Choice(['active', 'inactive'])", $result);
    }

    public function testEnumValuesAreEscaped(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'enum' => ["it's", 'fine']], false);

        self::assertStringContainsString("'it\\'s'", $result);
    }

    // -----------------------------------------------------------------------
    // Format constraints
    // -----------------------------------------------------------------------

    public function testFormatUuidProducesUuidConstraint(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'format' => 'uuid'], false);

        self::assertStringContainsString('new Assert\\Uuid()', $result);
    }

    public function testFormatDateProducesDateConstraint(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'format' => 'date'], false);

        self::assertStringContainsString('new Assert\\Date()', $result);
    }

    public function testFormatDateTimeProducesDateTimeConstraint(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'format' => 'date-time'], false);

        self::assertStringContainsString('new Assert\\DateTime()', $result);
    }

    public function testFormatEmailIsIgnored(): void
    {
        // 'email' is not in the supported format list — no extra constraint beyond Type
        $result = $this->mapper->mapField(['type' => 'string', 'format' => 'email'], false);

        self::assertStringNotContainsString('Assert\\Email', $result);
        self::assertStringContainsString("Assert\\Type('string')", $result);
    }

    public function testFormatBinaryIsIgnored(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'format' => 'binary'], false);

        self::assertStringNotContainsString('Assert\\Uuid', $result);
        self::assertStringNotContainsString('Assert\\Date', $result);
        self::assertStringNotContainsString('Assert\\DateTime', $result);
    }

    // -----------------------------------------------------------------------
    // Numeric range (minimum / maximum)
    // -----------------------------------------------------------------------

    public function testMinimumOnlyProducesRangeWithMin(): void
    {
        $result = $this->mapper->mapField(['type' => 'integer', 'minimum' => 1], true);

        self::assertStringContainsString('new Assert\\Range(min: 1)', $result);
        self::assertStringNotContainsString('max:', $result);
    }

    public function testMaximumOnlyProducesRangeWithMax(): void
    {
        $result = $this->mapper->mapField(['type' => 'integer', 'maximum' => 100], true);

        self::assertStringContainsString('new Assert\\Range(max: 100)', $result);
        self::assertStringNotContainsString('min:', $result);
    }

    public function testMinimumAndMaximumProducesRangeWithBoth(): void
    {
        $result = $this->mapper->mapField(['type' => 'integer', 'minimum' => 2000, 'maximum' => 2099], true);

        self::assertStringContainsString('new Assert\\Range(min: 2000, max: 2099)', $result);
    }

    public function testFloatMinimumProducesFloatLiteral(): void
    {
        $result = $this->mapper->mapField(['type' => 'number', 'minimum' => 0.5], false);

        self::assertStringContainsString('min: 0.5', $result);
    }

    // -----------------------------------------------------------------------
    // String length (minLength / maxLength)
    // -----------------------------------------------------------------------

    public function testMinLengthOnlyProducesLengthConstraint(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'minLength' => 3], false);

        self::assertStringContainsString('new Assert\\Length(min: 3)', $result);
        self::assertStringNotContainsString('max:', $result);
    }

    public function testMaxLengthOnlyProducesLengthConstraint(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'maxLength' => 255], false);

        self::assertStringContainsString('new Assert\\Length(max: 255)', $result);
        self::assertStringNotContainsString('min:', $result);
    }

    public function testMinAndMaxLengthProducesLengthWithBoth(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'minLength' => 8, 'maxLength' => 64], false);

        self::assertStringContainsString('new Assert\\Length(min: 8, max: 64)', $result);
    }

    // -----------------------------------------------------------------------
    // Pattern (regex)
    // -----------------------------------------------------------------------

    public function testPatternProducesRegexConstraint(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'pattern' => '^[A-Z]+$'], false);

        self::assertStringContainsString("new Assert\\Regex(pattern: '/^[A-Z]+$/')", $result);
    }

    public function testPatternIsWrappedInForwardSlashes(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'pattern' => '\d{4}'], false);

        // addslashes() escapes the backslash, so the generated PHP source contains
        // '/\\d{4}/' (two backslashes in the raw string = one backslash at runtime).
        self::assertStringContainsString('/\\\\d{4}/', $result);
    }

    // -----------------------------------------------------------------------
    // Combined: required field with multiple constraints
    // -----------------------------------------------------------------------

    public function testRequiredStringWithEnumStartsWithNotBlank(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'enum' => ['x', 'y']], true);

        // Array form: [NotBlank, Type, Choice]
        self::assertStringStartsWith('[new Assert\\NotBlank()', $result);
        self::assertStringContainsString("new Assert\\Choice(['x', 'y'])", $result);
    }

    public function testRequiredIntegerWithRangeProducesThreeConstraints(): void
    {
        $result = $this->mapper->mapField(['type' => 'integer', 'minimum' => 1, 'maximum' => 99], true);

        self::assertStringContainsString('new Assert\\NotBlank()', $result);
        self::assertStringContainsString("new Assert\\Type('integer')", $result);
        self::assertStringContainsString('new Assert\\Range(min: 1, max: 99)', $result);
    }

    public function testRequiredStringWithUuidFormatProducesThreeConstraints(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'format' => 'uuid'], true);

        self::assertStringContainsString('new Assert\\NotBlank()', $result);
        self::assertStringContainsString("new Assert\\Type('string')", $result);
        self::assertStringContainsString('new Assert\\Uuid()', $result);
    }

    public function testOptionalStringWithUuidAndLengthUsesArrayFormInOptional(): void
    {
        $result = $this->mapper->mapField(['type' => 'string', 'format' => 'uuid', 'maxLength' => 36], false);

        // 3 parts → Optional([...]) array form
        self::assertStringStartsWith('new Assert\\Optional([', $result);
        self::assertStringContainsString('new Assert\\Uuid()', $result);
        self::assertStringContainsString('new Assert\\Length(max: 36)', $result);
    }
}
