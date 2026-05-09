<?php

declare(strict_types=1);

namespace Mosyca\Core\Plugin\Builtin;

use Mosyca\Core\Plugin\Attribute\AsPlugin;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginResult;

#[AsPlugin]
final class PingPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'core:system:ping';
    }

    public function getDescription(): string
    {
        return 'Returns pong. Confirms the plugin contract works end-to-end.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Health-check plugin. Takes an optional message and echoes it back.

        ## Returns
        - pong: always "pong"
        - echo: the message you sent, or null if omitted

        ## Example
        Input:  { "message": "hello" }
        Output: { "pong": "pong", "echo": "hello" }
        USAGE;
    }

    public function getParameters(): array
    {
        return [
            'message' => [
                'type' => 'string',
                'description' => 'Optional message to echo back.',
                'required' => false,
                'default' => null,
                'example' => 'hello',
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

    public function execute(array $args): PluginResult
    {
        return PluginResult::ok(
            data: ['pong' => 'pong', 'echo' => $args['message'] ?? null],
            summary: '✅ pong',
        );
    }
}
