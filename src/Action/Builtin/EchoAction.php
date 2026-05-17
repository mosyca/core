<?php

declare(strict_types=1);

namespace Mosyca\Core\Action\Builtin;

use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ActionTrait;
use Mosyca\Core\Action\Attribute\AsAction;
use Mosyca\Core\Action\TemplateAwareActionInterface;
use Mosyca\Core\Context\ExecutionContextInterface;

#[AsAction]
final class EchoAction implements TemplateAwareActionInterface
{
    use ActionTrait;

    public function getName(): string
    {
        return 'mosyca:system:echo';
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
        // Built-in debug action: no domain ACL vector required (AC 1 illustration).
        return ActionResult::ok(
            data: $args,
            summary: 'echo: '.($args['message'] ?? '(empty)'),
        );
    }
}
