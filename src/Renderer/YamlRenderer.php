<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Plugin\PluginResult;
use Symfony\Component\Yaml\Yaml;

final class YamlRenderer
{
    public function __construct(private readonly Normalizer $normalizer)
    {
    }

    public function render(PluginResult $result): string
    {
        return Yaml::dump(
            $this->normalizer->normalize($result),
            inline: 4,
            indent: 4,
        );
    }
}
