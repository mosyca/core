<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Plugin\PluginResult;

final class RawRenderer
{
    public function render(PluginResult $result): string
    {
        return (string) var_export($result->toArray(), true);
    }
}
