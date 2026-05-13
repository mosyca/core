<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Renderer;

use Mosyca\Core\Renderer\Normalizer;

final class NormalizerTest extends RendererTestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new Normalizer();
    }

    public function testNormalizesSuccessResult(): void
    {
        $normalized = $this->normalizer->normalize($this->sampleResult());

        self::assertTrue($normalized['success']);
        self::assertSame('Order #1234: margin 47.30€ (23.5%)', $normalized['summary']);
        self::assertIsArray($normalized['data']);
    }

    public function testConvertsLinksToHalFormat(): void
    {
        $normalized = $this->normalizer->normalize($this->sampleResult());

        self::assertArrayHasKey('_links', $normalized);
        self::assertSame(['href' => '/api/orders/abc123/margin'], $normalized['_links']['self']);
        self::assertSame(['href' => '/api/orders/abc123'], $normalized['_links']['order']);
    }

    public function testPreservesEmbedded(): void
    {
        $normalized = $this->normalizer->normalize($this->sampleResult());

        self::assertArrayHasKey('_embedded', $normalized);
        self::assertSame('abc123', $normalized['_embedded']['order']['id']);
    }

    public function testOmitsEmptyLinksAndEmbedded(): void
    {
        $result = \Mosyca\Core\Action\ActionResult::ok(['x' => 1], 'ok');
        $normalized = $this->normalizer->normalize($result);

        self::assertArrayNotHasKey('_links', $normalized);
        self::assertArrayNotHasKey('_embedded', $normalized);
    }
}
