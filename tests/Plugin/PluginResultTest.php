<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Plugin;

use Mosyca\Core\Plugin\PluginResult;
use PHPUnit\Framework\TestCase;

final class PluginResultTest extends TestCase
{
    public function testOkResult(): void
    {
        $result = PluginResult::ok(['key' => 'value'], 'Success summary');

        self::assertTrue($result->success);
        self::assertSame(['key' => 'value'], $result->data);
        self::assertSame('Success summary', $result->summary);
        self::assertEmpty($result->links);
        self::assertEmpty($result->embedded);
    }

    public function testErrorResult(): void
    {
        $result = PluginResult::error('Something went wrong', ['code' => 404]);

        self::assertFalse($result->success);
        self::assertSame('Something went wrong', $result->summary);
        self::assertSame(['code' => 404], $result->data);
    }

    public function testErrorWithoutContextDefaults(): void
    {
        $result = PluginResult::error('Oops');

        self::assertFalse($result->success);
        self::assertIsArray($result->data);
        self::assertEmpty($result->data);
    }

    public function testWithLinksIsImmutable(): void
    {
        $original = PluginResult::ok([], 'ok');
        $withLinks = $original->withLinks(['self' => '/api/test']);

        self::assertNotSame($original, $withLinks);
        self::assertEmpty($original->links);
        self::assertSame(['self' => '/api/test'], $withLinks->links);
    }

    public function testWithEmbeddedIsImmutable(): void
    {
        $original = PluginResult::ok([], 'ok');
        $withEmbedded = $original->withEmbedded(['order' => ['id' => 1]]);

        self::assertNotSame($original, $withEmbedded);
        self::assertEmpty($original->embedded);
        self::assertSame(['order' => ['id' => 1]], $withEmbedded->embedded);
    }

    public function testToArrayContainsRequiredKeys(): void
    {
        $result = PluginResult::ok(['x' => 1], 'summary');
        $array = $result->toArray();

        self::assertArrayHasKey('success', $array);
        self::assertArrayHasKey('summary', $array);
        self::assertArrayHasKey('data', $array);
    }

    public function testToArrayOmitsEmptyLinksAndEmbedded(): void
    {
        $array = PluginResult::ok([], 'ok')->toArray();

        self::assertArrayNotHasKey('_links', $array);
        self::assertArrayNotHasKey('_embedded', $array);
        self::assertArrayNotHasKey('_meta', $array);
    }

    public function testToArrayIncludesLinksWhenPresent(): void
    {
        $array = PluginResult::ok([], 'ok')
            ->withLinks(['self' => '/api'])
            ->toArray();

        self::assertArrayHasKey('_links', $array);
        self::assertSame(['self' => '/api'], $array['_links']);
    }

    public function testToArrayIncludesEmbeddedWhenPresent(): void
    {
        $array = PluginResult::ok([], 'ok')
            ->withEmbedded(['item' => ['id' => 42]])
            ->toArray();

        self::assertArrayHasKey('_embedded', $array);
        self::assertSame(['item' => ['id' => 42]], $array['_embedded']);
    }

    public function testChaining(): void
    {
        $result = PluginResult::ok(['data' => true], 'ok')
            ->withLinks(['self' => '/api/resource'])
            ->withEmbedded(['related' => []]);

        self::assertTrue($result->success);
        self::assertNotEmpty($result->links);
        self::assertNotEmpty($result->embedded);
    }
}
