<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Action\ActionResult;

final class OutputRenderer implements OutputRendererInterface
{
    public function __construct(
        private readonly JsonRenderer $json,
        private readonly YamlRenderer $yaml,
        private readonly RawRenderer $raw,
        private readonly TableRenderer $table,
        private readonly TwigRenderer $twig,
        private readonly McpRenderer $mcp,
    ) {
    }

    public function render(
        ActionResult $result,
        string $format = 'json',
        ?string $template = null,
    ): string {
        return match ($format) {
            'json' => $this->json->render($result),
            'yaml' => $this->yaml->render($result),
            'raw' => $this->raw->render($result),
            'table' => $this->table->render($result),
            'text' => $this->twig->render($result, $template),
            'mcp' => $this->mcp->render($result),
            default => throw new \InvalidArgumentException("Unknown format '{$format}'. Supported: json, yaml, raw, table, text, mcp."),
        };
    }
}
