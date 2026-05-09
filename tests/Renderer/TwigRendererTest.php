<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Renderer;

use Mosyca\Core\Renderer\Normalizer;
use Mosyca\Core\Renderer\TwigRenderer;
use Twig\Sandbox\SecurityError;

final class TwigRendererTest extends RendererTestCase
{
    private TwigRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TwigRenderer(new Normalizer());
    }

    public function testRendersDefaultTemplate(): void
    {
        $output = $this->renderer->render($this->sampleResult(), null);

        self::assertStringContainsString('Order #1234', $output);
        self::assertStringContainsString('margin_absolute', $output);
    }

    public function testRendersInlineTemplate(): void
    {
        $output = $this->renderer->render(
            $this->sampleResult(),
            '{{ summary }}',
        );

        self::assertStringContainsString('Order #1234', $output);
    }

    public function testInlineTemplateCanAccessData(): void
    {
        $output = $this->renderer->render(
            $this->sampleResult(),
            'margin: {{ data.margin_absolute }}',
        );

        self::assertStringContainsString('47.3', $output);
    }

    public function testSandboxBlocksFileAccess(): void
    {
        $this->expectException(SecurityError::class);

        $this->renderer->renderInline(
            '{{ source("/etc/passwd") }}',
            ['summary' => 'test', 'success' => true, 'data' => []],
        );
    }

    public function testSandboxBlocksInclude(): void
    {
        $this->expectException(SecurityError::class);

        $this->renderer->renderInline(
            '{% include "/etc/passwd" %}',
            ['summary' => 'test', 'success' => true, 'data' => []],
        );
    }
}
