<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Bridge;

use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\Builtin\EchoAction;
use Mosyca\Core\Action\Builtin\PingAction;
use Mosyca\Core\Action\Builtin\Resource\SystemResource;
use Mosyca\Core\Bridge\ConstraintSchemaTranslator;
use Mosyca\Core\Bridge\McpDiscoveryService;
use Mosyca\Core\Resource\ResourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mosyca\Core\Bridge\McpDiscoveryService
 */
final class McpDiscoveryServiceTest extends TestCase
{
    private ActionRegistry $actionRegistry;
    private ResourceRegistry $resourceRegistry;
    private McpDiscoveryService $service;

    protected function setUp(): void
    {
        $this->actionRegistry = new ActionRegistry();
        $this->actionRegistry->register(new PingAction());
        $this->actionRegistry->register(new EchoAction());

        $this->resourceRegistry = new ResourceRegistry();
        $this->resourceRegistry->register(new SystemResource());

        $this->service = new McpDiscoveryService(
            $this->resourceRegistry,
            $this->actionRegistry,
            new ConstraintSchemaTranslator(),
        );
    }

    public function testListToolsReturnsTwoSystemTools(): void
    {
        $tools = $this->service->listTools();

        self::assertCount(2, $tools);
    }

    public function testListToolsReturnsFlatToolNames(): void
    {
        $tools = $this->service->listTools();
        $names = array_column($tools, 'name');

        self::assertContains('mosyca_system_ping', $names);
        self::assertContains('mosyca_system_echo', $names);
    }

    public function testListToolsIncludesDescription(): void
    {
        $tools = $this->service->listTools();
        $byName = array_column($tools, null, 'name');

        self::assertArrayHasKey('mosyca_system_ping', $byName);
        self::assertNotEmpty($byName['mosyca_system_ping']['description']);
    }

    public function testListToolsIncludesInputSchema(): void
    {
        $tools = $this->service->listTools();
        $byName = array_column($tools, null, 'name');

        self::assertArrayHasKey('inputSchema', $byName['mosyca_system_ping']);
        self::assertSame('object', $byName['mosyca_system_ping']['inputSchema']['type']);
    }

    public function testListToolsInjectsTenantInEverySchema(): void
    {
        $tools = $this->service->listTools();

        foreach ($tools as $tool) {
            self::assertArrayHasKey('properties', $tool['inputSchema']);
            self::assertArrayHasKey('tenant', $tool['inputSchema']['properties']);
        }
    }

    public function testListToolsTenantIsRequired(): void
    {
        $tools = $this->service->listTools();

        foreach ($tools as $tool) {
            self::assertIsArray($tool['inputSchema']['required']);
            self::assertContains('tenant', $tool['inputSchema']['required']);
        }
    }

    public function testListToolsTenantEnumInjectedWhenProvided(): void
    {
        $tools = $this->service->listTools(['default', 'shop-berlin']);
        $byName = array_column($tools, null, 'name');

        self::assertIsArray($byName['mosyca_system_ping']['inputSchema']['properties']['tenant']['enum']);
        self::assertSame(
            ['default', 'shop-berlin'],
            $byName['mosyca_system_ping']['inputSchema']['properties']['tenant']['enum'],
        );
    }

    public function testEchoToolHasRequiredMessageParam(): void
    {
        $tools = $this->service->listTools();
        $byName = array_column($tools, null, 'name');

        self::assertArrayHasKey('mosyca_system_echo', $byName);
        self::assertArrayHasKey('message', $byName['mosyca_system_echo']['inputSchema']['properties']);
        self::assertContains('message', $byName['mosyca_system_echo']['inputSchema']['required']);
    }

    public function testPingToolHasOptionalMessageParam(): void
    {
        $tools = $this->service->listTools();
        $byName = array_column($tools, null, 'name');

        self::assertArrayHasKey('mosyca_system_ping', $byName);
        self::assertArrayHasKey('message', $byName['mosyca_system_ping']['inputSchema']['properties']);
        // PingAction.message is optional — should NOT be in required
        self::assertNotContains('message', $byName['mosyca_system_ping']['inputSchema']['required']);
    }

    public function testEmptyRegistriesReturnEmptyList(): void
    {
        $service = new McpDiscoveryService(
            new ResourceRegistry(),
            new ActionRegistry(),
            new ConstraintSchemaTranslator(),
        );

        self::assertSame([], $service->listTools());
    }
}
