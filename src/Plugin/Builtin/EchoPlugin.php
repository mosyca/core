<?php

declare(strict_types=1);

namespace Mosyca\Core\Plugin\Builtin;

use Mosyca\Core\Plugin\Attribute\AsPlugin;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginResult;

#[AsPlugin]
final class EchoPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'core:system:echo';
    }

    public function getDescription(): string
    {
        return 'Echoes all input parameters back as output. Useful for debugging.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Returns every input parameter unchanged. Useful for verifying that
        parameter passing works end-to-end through MCP, CLI, or REST.

        ## Returns
        - All input parameters as-is

        ## Example
        Input:  { "message": "hello", "count": 3 }
        Output: { "message": "hello", "count": 3 }
        USAGE;
    }

    public function getParameters(): array
    {
        return [
            'message' => [
                'type' => 'string',
                'description' => 'Any string to echo back.',
                'required' => true,
                'example' => 'hello world',
            ],
        ];
    }

    public function getRequiredScopes(): array
    {
        return [];
    }

    public function getTags(): array
    {
        return ['core', 'debug'];
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function getDefaultFormat(): string
    {
        return 'json';
    }

    public function getDefaultTemplate(): ?string
    {
        return null;
    }

    public function getTemplates(): array
    {
        return [];
    }

    public function execute(array $args): PluginResult
    {
        return PluginResult::ok(
            data: $args,
            summary: 'echo: '.($args['message'] ?? '(empty)'),
        );
    }
}
