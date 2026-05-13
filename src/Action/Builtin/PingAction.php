<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Builtin;

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Action\TemplateAwareActionInterface;
use Mosyca\Core\Context\ExecutionContextInterface;

#[AsAction]
final class PingAction implements TemplateAwareActionInterface
{
    use ActionTrait;

    public function getName(): string
    {
        return 'core:system:ping';
    }

    public function getDescription(): string
    {
        return 'Returns pong. Confirms the action contract works end-to-end.';
    }

    public function getUsage(): string
    {
        return <<<'USAGE'
        Health-check action. Takes an optional message and echoes it back.

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

    public function execute(array $args, ExecutionContextInterface $context): ActionResult
    {
        // Built-in health-check: no domain ACL vector required (AC 1 illustration).
        return ActionResult::ok(
            data: ['pong' => 'pong', 'echo' => $args['message'] ?? null],
            summary: '✅ pong',
        );
    }
}
