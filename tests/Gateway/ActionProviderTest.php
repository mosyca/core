<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Gateway;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Gateway\Provider\ActionProvider;
use Mosyca\Core\Gateway\Resource\ActionResource;
use PHPUnit\Framework\TestCase;

final class ActionProviderTest extends TestCase
{
    private ActionRegistry $registry;
    private ActionProvider $provider;

    protected function setUp(): void
    {
        $this->registry = new ActionRegistry();
        $this->provider = new ActionProvider($this->registry);
    }

    public function testCollectionReturnsAllActions(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping', ['core']));
        $this->registry->register($this->makeAction('mosyca:system:echo', ['core']));

        $result = $this->provider->provide(new GetCollection());

        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertInstanceOf(ActionResource::class, $result[0]);
        self::assertInstanceOf(ActionResource::class, $result[1]);
    }

    public function testCollectionFiltersByTag(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping', ['core']));
        $this->registry->register($this->makeAction('shopware:order:list', ['ecommerce']));

        $result = $this->provider->provide(new GetCollection(), context: ['filters' => ['tag' => 'ecommerce']]);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('shopware:order:list', $result[0]->name);
    }

    public function testCollectionFiltersByConnector(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping', ['core']));
        $this->registry->register($this->makeAction('shopware:order:list', ['ecommerce']));

        $result = $this->provider->provide(new GetCollection(), context: ['filters' => ['connector' => 'shopware']]);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('shopware:order:list', $result[0]->name);
    }

    public function testCollectionFiltersByMutating(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping', [], mutating: false));
        $this->registry->register($this->makeAction('core:data:delete', [], mutating: true));

        $result = $this->provider->provide(new GetCollection(), context: ['filters' => ['mutating' => 'true']]);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('core:data:delete', $result[0]->name);
    }

    public function testItemReturnsActionResource(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping', ['core']));

        $result = $this->provider->provide(
            new Get(),
            uriVariables: ['plugin_name' => 'mosyca', 'tenant' => 'default', 'resource' => 'system', 'action' => 'ping'],
        );

        self::assertInstanceOf(ActionResource::class, $result);
        self::assertSame('mosyca:system:ping', $result->name);
        self::assertSame('mosyca', $result->plugin_name);
        self::assertSame('system', $result->resource);
        self::assertSame('ping', $result->action);
    }

    public function testItemReturnsNullForUnknownAction(): void
    {
        $result = $this->provider->provide(
            new Get(),
            uriVariables: ['plugin_name' => 'does', 'tenant' => 'default', 'resource' => 'not', 'action' => 'exist'],
        );

        self::assertNull($result);
    }

    public function testItemIncludesFullDetails(): void
    {
        $action = $this->makeAction('mosyca:system:ping', ['core'], parameters: [
            'message' => ['type' => 'string', 'description' => 'A message', 'required' => false],
        ]);
        $this->registry->register($action);

        $result = $this->provider->provide(
            new Get(),
            uriVariables: ['plugin_name' => 'mosyca', 'tenant' => 'default', 'resource' => 'system', 'action' => 'ping'],
        );

        self::assertInstanceOf(ActionResource::class, $result);
        self::assertNotEmpty($result->usage);
        self::assertArrayHasKey('message', $result->parameters);
        self::assertNotEmpty($result->jsonSchema);
        self::assertSame('object', $result->jsonSchema['type']);
    }

    public function testCollectionItemsDoNotIncludeUsage(): void
    {
        $this->registry->register($this->makeAction('mosyca:system:ping', ['core']));

        $results = $this->provider->provide(new GetCollection());

        self::assertIsArray($results);
        self::assertSame('', $results[0]->usage);
    }

    public function testCollectionPopulatesNameSegments(): void
    {
        $this->registry->register($this->makeAction('shopware:order:get-margin', ['ecommerce']));

        $results = $this->provider->provide(new GetCollection());

        self::assertIsArray($results);
        self::assertSame('shopware', $results[0]->plugin_name);
        self::assertSame('order', $results[0]->resource);
        self::assertSame('get-margin', $results[0]->action);
    }

    public function testJsonSchemaBuiltFromParameters(): void
    {
        $action = $this->makeAction('mosyca:system:test', [], parameters: [
            'limit' => ['type' => 'integer', 'description' => 'Page size', 'required' => true],
            'active' => ['type' => 'boolean', 'required' => false],
        ]);
        $this->registry->register($action);

        $result = $this->provider->provide(
            new Get(),
            uriVariables: ['plugin_name' => 'mosyca', 'tenant' => 'default', 'resource' => 'system', 'action' => 'test'],
        );

        self::assertInstanceOf(ActionResource::class, $result);
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
    private function makeAction(
        string $name,
        array $tags = [],
        bool $mutating = false,
        array $parameters = [],
    ): ActionInterface {
        $action = $this->createMock(ActionInterface::class);
        $action->method('getName')->willReturn($name);
        $action->method('getDescription')->willReturn("Description of {$name}");
        $action->method('getUsage')->willReturn("Usage of {$name}");
        $action->method('getParameters')->willReturn($parameters);
        $action->method('getRequiredScopes')->willReturn([]);
        $action->method('getTags')->willReturn($tags);
        $action->method('isMutating')->willReturn($mutating);
        $action->method('getDefaultFormat')->willReturn('json');
        $action->method('getDefaultTemplate')->willReturn(null);
        $action->method('execute')->willReturn(ActionResult::ok([], 'ok'));

        return $action;
    }
}
