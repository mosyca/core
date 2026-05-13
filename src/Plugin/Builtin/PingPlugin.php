<?php

declare(strict_types=1);

namespace Mosyca\Core\Plugin\Builtin;

use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Plugin\Attribute\AsPlugin;
use Mosyca\Core\Plugin\PluginResult;
use Mosyca\Core\Plugin\PluginTrait;
use Mosyca\Core\Plugin\TemplateAwarePluginInterface;

#[AsPlugin]
final class PingPlugin implements TemplateAwarePluginInterface
{
    use PluginTrait;

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

    /** @return string[] */
    public function getTags(): array
    {
        return ['core', 'debug'];
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function execute(array $args, ExecutionContextInterface $context): PluginResult
    {
        // Built-in health-check: no domain ACL vector required (AC 1 illustration).
        return PluginResult::ok(
            data: ['pong' => 'pong', 'echo' => $args['message'] ?? null],
            summary: '✅ pong',
        );
    }
}
