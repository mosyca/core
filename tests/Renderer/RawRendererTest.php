<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Renderer;

use Mosyca\Core\Renderer\RawRenderer;

final class RawRendererTest extends RendererTestCase
{
    private RawRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new RawRenderer();
    }

    public function testRendersPhpExport(): void
    {
        $output = $this->renderer->render($this->sampleResult());

        self::assertStringContainsString('array', $output);
        self::assertStringContainsString('success', $output);
        self::assertStringContainsString('summary', $output);
    }

    public function testIsEvalable(): void
    {
        $output = $this->renderer->render($this->sampleResult());
        $result = eval('return '.$output.';');

        self::assertIsArray($result);
        self::assertTrue($result['success']);
    }
}
