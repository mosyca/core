<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Action\ActionResult;
use Symfony\Component\Yaml\Yaml;

final class YamlRenderer
{
    public function __construct(private readonly Normalizer $normalizer)
    {
    }

    public function render(ActionResult $result): string
    {
        return Yaml::dump(
            $this->normalizer->normalize($result),
            inline: 4,
            indent: 4,
        );
    }
}
