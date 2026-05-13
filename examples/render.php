<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Renderer\JsonRenderer;
use Mosyca\Core\Renderer\McpRenderer;
use Mosyca\Core\Renderer\Normalizer;
use Mosyca\Core\Renderer\OutputRenderer;
use Mosyca\Core\Renderer\RawRenderer;
use Mosyca\Core\Renderer\TableRenderer;
use Mosyca\Core\Renderer\TwigRenderer;
use Mosyca\Core\Renderer\YamlRenderer;

// Parse CLI args: --format=json --template=core/default
$opts = getopt('', ['format:', 'template:']) ?: [];
$format = is_string($opts['format'] ?? null) ? $opts['format'] : 'json';
$template = is_string($opts['template'] ?? null) ? $opts['template'] : null;

// Sample result with data, links, and embedded
$result = ActionResult::ok(
    data: [
        'margin_absolute' => 47.30,
        'margin_percent' => 23.5,
        'order_total' => 201.10,
        'cost_total' => 153.80,
    ],
    summary: 'Order #1234: margin 47.30€ (23.5%)',
)
->withLinks([
    'self' => '/api/orders/abc123/margin',
    'order' => '/api/orders/abc123',
])
->withEmbedded([
    'order' => ['id' => 'abc123', 'orderNumber' => '1234', 'amountTotal' => 201.10],
]);

$normalizer = new Normalizer();
$renderer = new OutputRenderer(
    json: new JsonRenderer($normalizer),
    yaml: new YamlRenderer($normalizer),
    raw: new RawRenderer(),
    table: new TableRenderer(),
    twig: new TwigRenderer($normalizer),
    mcp: new McpRenderer(),
);

echo $renderer->render($result, $format, $template).\PHP_EOL;
