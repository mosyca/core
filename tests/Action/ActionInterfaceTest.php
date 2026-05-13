<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Action;

use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\Builtin\PingAction;
use Mosyca\Core\Context\ExecutionContextInterface;
use PHPUnit\Framework\TestCase;

final class ActionInterfaceTest extends TestCase
{
    private PingAction $plugin;
    private ExecutionContextInterface $context;

    protected function setUp(): void
    {
        $this->plugin = new PingAction();
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->context->method('getTenantId')->willReturn('default');
        $this->context->method('isAclBypassed')->willReturn(false);
    }

    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(ActionInterface::class, $this->plugin);
    }

    public function testGetNameFollowsConvention(): void
    {
        // Convention: {plugin_name}:{resource}:{action} — all lowercase, hyphens allowed
        self::assertMatchesRegularExpression(
            '/^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*:[a-z][a-z0-9-]*$/',
            $this->plugin->getName(),
        );
    }

    public function testGetDescriptionIsNonEmpty(): void
    {
        self::assertNotEmpty($this->plugin->getDescription());
    }

    public function testGetUsageIsNonEmpty(): void
    {
        self::assertNotEmpty($this->plugin->getUsage());
    }

    public function testGetParametersHaveRequiredSchema(): void
    {
        foreach ($this->plugin->getParameters() as $schema) {
            self::assertArrayHasKey('type', $schema);
            self::assertArrayHasKey('description', $schema);
            self::assertArrayHasKey('required', $schema);
        }
    }

    public function testGetRequiredScopesIsEmptyForPing(): void
    {
        self::assertSame([], $this->plugin->getRequiredScopes());
    }

    public function testGetTagsContainsCoreTag(): void
    {
        self::assertContains('core', $this->plugin->getTags());
    }

    public function testIsNotMutating(): void
    {
        self::assertFalse($this->plugin->isMutating());
    }

    public function testGetDefaultFormatIsValid(): void
    {
        self::assertContains(
            $this->plugin->getDefaultFormat(),
            ['json', 'yaml', 'table', 'text', 'mcp', 'raw'],
        );
    }

    public function testExecuteReturnsActionResult(): void
    {
        $result = $this->plugin->execute([], $this->context);

        self::assertInstanceOf(ActionResult::class, $result);
    }

    public function testExecuteSucceeds(): void
    {
        $result = $this->plugin->execute([], $this->context);

        self::assertTrue($result->success);
        self::assertSame('✅ pong', $result->summary);
    }

    public function testExecuteEchosMessage(): void
    {
        $result = $this->plugin->execute(['message' => 'hello'], $this->context);

        self::assertTrue($result->success);
        self::assertIsArray($result->data);
        self::assertSame('hello', $result->data['echo']);
    }

    public function testExecuteWithoutMessageEchosNull(): void
    {
        $result = $this->plugin->execute([], $this->context);

        self::assertIsArray($result->data);
        self::assertNull($result->data['echo']);
    }
}
