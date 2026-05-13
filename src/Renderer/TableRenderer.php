<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Action\ActionResult;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

final class TableRenderer
{
    public function render(ActionResult $result): string
    {
        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setStyle('box');
        $table->setHeaders(['Key', 'Value']);

        if (\is_array($result->data) && !empty($result->data)) {
            foreach ($result->data as $key => $value) {
                $table->addRow([(string) $key, $this->formatValue($value)]);
            }
        } else {
            $table->addRow(['result', $this->formatValue($result->data)]);
        }

        $table->render();

        return rtrim($output->fetch());
    }

    private function formatValue(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (null === $value) {
            return '—';
        }
        if (\is_array($value)) {
            return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }

        return (string) $value;
    }
}
