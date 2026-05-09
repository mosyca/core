<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Plugin;

use Mosyca\Core\Examples\PingPlugin;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginResult;
use PHPUnit\Framework\TestCase;

final class PluginInterfaceTest extends TestCase
{
    private PingPlugin $plugin;

    protected function setUp(): void
    {
        $this->plugin = new PingPlugin();
    }

    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(PluginInterface::class, $this->plugin);
    }

    public function testGetNameFollowsConvention(): void
    {
        // Convention: {connector}:{resource}:{action} — all lowercase, hyphens allowed
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

    public function testExecuteReturnsPluginResult(): void
    {
        $result = $this->plugin->execute([]);

        self::assertInstanceOf(PluginResult::class, $result);
    }

    public function testExecuteSucceeds(): void
    {
        $result = $this->plugin->execute([]);

        self::assertTrue($result->success);
        self::assertSame('✅ pong', $result->summary);
    }

    public function testExecuteEchosMessage(): void
    {
        $result = $this->plugin->execute(['message' => 'hello']);

        self::assertTrue($result->success);
        self::assertIsArray($result->data);
        self::assertSame('hello', $result->data['echo']);
    }

    public function testExecuteWithoutMessageEchosNull(): void
    {
        $result = $this->plugin->execute([]);

        self::assertIsArray($result->data);
        self::assertNull($result->data['echo']);
    }
}
