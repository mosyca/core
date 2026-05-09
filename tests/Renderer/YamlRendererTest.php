<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Renderer;

use Mosyca\Core\Renderer\Normalizer;
use Mosyca\Core\Renderer\YamlRenderer;
use Symfony\Component\Yaml\Yaml;

final class YamlRendererTest extends RendererTestCase
{
    private YamlRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new YamlRenderer(new Normalizer());
    }

    public function testRendersValidYaml(): void
    {
        $output = $this->renderer->render($this->sampleResult());
        $parsed = Yaml::parse($output);

        self::assertIsArray($parsed);
        self::assertTrue($parsed['success']);
    }

    public function testIncludesHalLinks(): void
    {
        $output = $this->renderer->render($this->sampleResult());
        $parsed = Yaml::parse($output);

        self::assertArrayHasKey('_links', $parsed);
        self::assertSame('/api/orders/abc123/margin', $parsed['_links']['self']['href']);
    }
}
