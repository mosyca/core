<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Plugin\PluginResult;

final class JsonRenderer
{
    public function __construct(private readonly Normalizer $normalizer)
    {
    }

    public function render(PluginResult $result): string
    {
        return json_encode(
            $this->normalizer->normalize($result),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
    }
}
