<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Renderer;

use Mosyca\Core\Renderer\McpRenderer;

final class McpRendererTest extends RendererTestCase
{
    private McpRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new McpRenderer();
    }

    public function testStartsWithSuccessEmoji(): void
    {
        $output = $this->renderer->render($this->sampleResult());

        self::assertStringStartsWith('✅', $output);
    }

    public function testStartsWithErrorEmoji(): void
    {
        $output = $this->renderer->render($this->errorResult());

        self::assertStringStartsWith('❌', $output);
    }

    public function testIncludesSummary(): void
    {
        $output = $this->renderer->render($this->sampleResult());

        self::assertStringContainsString('Order #1234', $output);
    }

    public function testIncludesDataKeys(): void
    {
        $output = $this->renderer->render($this->sampleResult());

        self::assertStringContainsString('Margin Absolute', $output);
        self::assertStringContainsString('47.3', $output);
    }

    public function testIncludesLinks(): void
    {
        $output = $this->renderer->render($this->sampleResult());

        self::assertStringContainsString('/api/orders/abc123/margin', $output);
    }
}
