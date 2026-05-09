<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Renderer;

use Mosyca\Core\Renderer\JsonRenderer;
use Mosyca\Core\Renderer\McpRenderer;
use Mosyca\Core\Renderer\Normalizer;
use Mosyca\Core\Renderer\OutputRenderer;
use Mosyca\Core\Renderer\RawRenderer;
use Mosyca\Core\Renderer\TableRenderer;
use Mosyca\Core\Renderer\TwigRenderer;
use Mosyca\Core\Renderer\YamlRenderer;

final class OutputRendererTest extends RendererTestCase
{
    private OutputRenderer $renderer;

    protected function setUp(): void
    {
        $normalizer = new Normalizer();
        $this->renderer = new OutputRenderer(
            json: new JsonRenderer($normalizer),
            yaml: new YamlRenderer($normalizer),
            raw: new RawRenderer(),
            table: new TableRenderer(),
            twig: new TwigRenderer($normalizer),
            mcp: new McpRenderer(),
        );
    }

    public function testDispatchesToJson(): void
    {
        $output = $this->renderer->render($this->sampleResult(), 'json');
        $decoded = json_decode($output, true);

        self::assertIsArray($decoded);
        self::assertTrue($decoded['success']);
    }

    public function testDispatchesToYaml(): void
    {
        $output = $this->renderer->render($this->sampleResult(), 'yaml');

        self::assertStringContainsString('success: true', $output);
    }

    public function testDispatchesToTable(): void
    {
        $output = $this->renderer->render($this->sampleResult(), 'table');

        self::assertStringContainsString('┌', $output);
    }

    public function testDispatchesToMcp(): void
    {
        $output = $this->renderer->render($this->sampleResult(), 'mcp');

        self::assertStringStartsWith('✅', $output);
    }

    public function testDispatchesToText(): void
    {
        $output = $this->renderer->render($this->sampleResult(), 'text');

        self::assertStringContainsString('Order #1234', $output);
    }

    public function testThrowsOnUnknownFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->renderer->render($this->sampleResult(), 'xml');
    }
}
