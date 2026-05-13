<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Renderer;

use Mosyca\Core\Action\ActionResult;
use PHPUnit\Framework\TestCase;

abstract class RendererTestCase extends TestCase
{
    protected function sampleResult(): ActionResult
    {
        return ActionResult::ok(
            data: ['margin_absolute' => 47.30, 'margin_percent' => 23.5],
            summary: 'Order #1234: margin 47.30€ (23.5%)',
        )
        ->withLinks(['self' => '/api/orders/abc123/margin', 'order' => '/api/orders/abc123'])
        ->withEmbedded(['order' => ['id' => 'abc123', 'orderNumber' => '1234']]);
    }

    protected function errorResult(): ActionResult
    {
        return ActionResult::error('Order not found.', ['order_id' => 'xyz']);
    }
}
