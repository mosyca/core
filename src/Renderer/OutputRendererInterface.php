<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Plugin\PluginResult;

/**
 * Central rendering service — injected into all interface adapters
 * (Console Adapter, Gateway, Bridge). Plugin authors never use this directly.
 */
interface OutputRendererInterface
{
    /**
     * Render a PluginResult to the requested format.
     *
     * @param string      $format   json|yaml|raw|table|text|mcp
     * @param string|null $template Named template path (e.g. 'core/default') or
     *                              inline Twig string (detected by {{ or {%).
     *                              Only used when format = 'text'.
     */
    public function render(
        PluginResult $result,
        string $format = 'json',
        ?string $template = null,
    ): string;
}
