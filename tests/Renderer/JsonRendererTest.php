<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Renderer;

use Mosyca\Core\Renderer\JsonRenderer;
use Mosyca\Core\Renderer\Normalizer;

final class JsonRendererTest extends RendererTestCase
{
    private JsonRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new JsonRenderer(new Normalizer());
    }

    public function testRendersValidJson(): void
    {
        $output = $this->renderer->render($this->sampleResult());

        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success']);
    }

    public function testIncludesHalLinks(): void
    {
        $output = $this->renderer->render($this->sampleResult());
        $decoded = json_decode($output, true);

        self::assertArrayHasKey('_links', $decoded);
        self::assertSame('/api/orders/abc123/margin', $decoded['_links']['self']['href']);
    }

    public function testRendersErrorResult(): void
    {
        $output = $this->renderer->render($this->errorResult());
        $decoded = json_decode($output, true);

        self::assertFalse($decoded['success']);
        self::assertSame('Order not found.', $decoded['summary']);
    }

    public function testIsPrettyPrinted(): void
    {
        $output = $this->renderer->render($this->sampleResult());

        self::assertStringContainsString("\n", $output);
    }
}
