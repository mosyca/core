<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Action\ActionResult;

final class RawRenderer
{
    public function render(ActionResult $result): string
    {
        return (string) var_export($result->toArray(), true);
    }
}
