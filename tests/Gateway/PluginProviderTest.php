<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Gateway;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Mosyca\Core\Gateway\Provider\PluginProvider;
use Mosyca\Core\Gateway\Resource\PluginResource;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginRegistry;
use Mosyca\Core\Plugin\PluginResult;
use PHPUnit\Framework\TestCase;

final class PluginProviderTest extends TestCase
{
    private PluginRegistry $registry;
    private PluginProvider $provider;

    protected function setUp(): void
    {
        $this->registry = new PluginRegistry();
        $this->provider = new PluginProvider($this->registry);
    }

    public function testCollectionReturnsAllPlugins(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', ['core']));
        $this->registry->register($this->makePlugin('core:system:echo', ['core']));

        $result = $this->provider->provide(new GetCollection());

        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertInstanceOf(PluginResource::class, $result[0]);
        self::assertInstanceOf(PluginResource::class, $result[1]);
    }

    public function testCollectionFiltersByTag(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', ['core']));
        $this->registry->register($this->makePlugin('shopware:order:list', ['ecommerce']));

        $result = $this->provider->provide(new GetCollection(), context: ['filters' => ['tag' => 'ecommerce']]);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('shopware:order:list', $result[0]->name);
    }

    public function testCollectionFiltersByConnector(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', ['core']));
        $this->registry->register($this->makePlugin('shopware:order:list', ['ecommerce']));

        $result = $this->provider->provide(new GetCollection(), context: ['filters' => ['connector' => 'shopware']]);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('shopware:order:list', $result[0]->name);
    }

    public function testCollectionFiltersByMutating(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', [], mutating: false));
        $this->registry->register($this->makePlugin('core:data:delete', [], mutating: true));

        $result = $this->provider->provide(new GetCollection(), context: ['filters' => ['mutating' => 'true']]);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('core:data:delete', $result[0]->name);
    }

    public function testItemReturnsPluginResource(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', ['core']));

        $result = $this->provider->provide(new Get(), uriVariables: ['name' => 'core:system:ping']);

        self::assertInstanceOf(PluginResource::class, $result);
        self::assertSame('core:system:ping', $result->name);
        self::assertSame('core', $result->connector);
    }

    public function testItemReturnsNullForUnknownPlugin(): void
    {
        $result = $this->provider->provide(new Get(), uriVariables: ['name' => 'does:not:exist']);

        self::assertNull($result);
    }

    public function testItemIncludesFullDetails(): void
    {
        $plugin = $this->makePlugin('core:system:ping', ['core'], parameters: [
            'message' => ['type' => 'string', 'description' => 'A message', 'required' => false],
        ]);
        $this->registry->register($plugin);

        $result = $this->provider->provide(new Get(), uriVariables: ['name' => 'core:system:ping']);

        self::assertInstanceOf(PluginResource::class, $result);
        self::assertNotEmpty($result->usage);
        self::assertArrayHasKey('message', $result->parameters);
        self::assertNotEmpty($result->jsonSchema);
        self::assertSame('object', $result->jsonSchema['type']);
    }

    public function testCollectionItemsDoNotIncludeUsage(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', ['core']));

        $results = $this->provider->provide(new GetCollection());

        self::assertIsArray($results);
        self::assertSame('', $results[0]->usage);
    }

    public function testJsonSchemaBuiltFromParameters(): void
    {
        $plugin = $this->makePlugin('core:system:test', [], parameters: [
            'limit' => ['type' => 'integer', 'description' => 'Page size', 'required' => true],
            'active' => ['type' => 'boolean', 'required' => false],
        ]);
        $this->registry->register($plugin);

        $result = $this->provider->provide(new Get(), uriVariables: ['name' => 'core:system:test']);

        self::assertInstanceOf(PluginResource::class, $result);
        self::assertSame('integer', $result->jsonSchema['properties']['limit']['type']);
        self::assertSame('boolean', $result->jsonSchema['properties']['active']['type']);
        self::assertContains('limit', $result->jsonSchema['required']);
        self::assertNotContains('active', $result->jsonSchema['required']);
    }

    // -------------------------------------------------------------------------

    /**
     * @param string[]                            $tags
     * @param array<string, array<string, mixed>> $parameters
     */
    private function makePlugin(
        string $name,
        array $tags = [],
        bool $mutating = false,
        array $parameters = [],
    ): PluginInterface {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn($name);
        $plugin->method('getDescription')->willReturn("Description of {$name}");
        $plugin->method('getUsage')->willReturn("Usage of {$name}");
        $plugin->method('getParameters')->willReturn($parameters);
        $plugin->method('getRequiredScopes')->willReturn([]);
        $plugin->method('getTags')->willReturn($tags);
        $plugin->method('isMutating')->willReturn($mutating);
        $plugin->method('getDefaultFormat')->willReturn('json');
        $plugin->method('getDefaultTemplate')->willReturn(null);
        $plugin->method('execute')->willReturn(PluginResult::ok([], 'ok'));

        return $plugin;
    }
}
