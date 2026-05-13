<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Renderer;

use Mosyca\Core\Renderer\TableRenderer;

final class TableRendererTest extends RendererTestCase
{
    private TableRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TableRenderer();
    }

    public function testRendersBoxTable(): void
    {
        $output = $this->renderer->render($this->sampleResult());

        self::assertStringContainsString('margin_absolute', $output);
        self::assertStringContainsString('47.3', $output);
    }

    public function testUsesBoxDrawingCharacters(): void
    {
        $output = $this->renderer->render($this->sampleResult());

        self::assertStringContainsString('┌', $output);
        self::assertStringContainsString('│', $output);
        self::assertStringContainsString('└', $output);
    }

    public function testRendersNullAsPlaceholder(): void
    {
        $result = \Mosyca\Core\Action\ActionResult::ok(['value' => null], 'ok');
        $output = $this->renderer->render($result);

        self::assertStringContainsString('—', $output);
    }
}
