<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Scaffold;

use Mosyca\Core\Scaffold\ScaffoldDescriptor;
use PHPUnit\Framework\TestCase;

final class ScaffoldDescriptorTest extends TestCase
{
    private function makeDescriptor(string $httpMethod, string $className = 'GetItemsAction'): ScaffoldDescriptor
    {
        return new ScaffoldDescriptor(
            httpMethod: $httpMethod,
            path: '/items',
            connector: 'myapp',
            className: $className,
            namespace: 'MyOrg\Connector\MyApp\Action\Scaffold',
            actionName: 'scaffold:myapp:items',
            description: 'List items',
            parameters: [],
        );
    }

    // -----------------------------------------------------------------------
    // isMutating()
    // -----------------------------------------------------------------------

    public function testGetIsNotMutating(): void
    {
        self::assertFalse($this->makeDescriptor('GET')->isMutating());
    }

    public function testHeadIsNotMutating(): void
    {
        self::assertFalse($this->makeDescriptor('HEAD')->isMutating());
    }

    public function testPostIsMutating(): void
    {
        self::assertTrue($this->makeDescriptor('POST')->isMutating());
    }

    public function testPutIsMutating(): void
    {
        self::assertTrue($this->makeDescriptor('PUT')->isMutating());
    }

    public function testPatchIsMutating(): void
    {
        self::assertTrue($this->makeDescriptor('PATCH')->isMutating());
    }

    public function testDeleteIsMutating(): void
    {
        self::assertTrue($this->makeDescriptor('DELETE')->isMutating());
    }

    public function testIsMutatingIsCaseInsensitive(): void
    {
        // httpMethod is stored as-is — isMutating() uses strtoupper internally
        self::assertFalse($this->makeDescriptor('get')->isMutating());
        self::assertTrue($this->makeDescriptor('post')->isMutating());
    }

    // -----------------------------------------------------------------------
    // getFileName()
    // -----------------------------------------------------------------------

    public function testGetFileNameReturnsClassNameWithPhpExtension(): void
    {
        $descriptor = $this->makeDescriptor('GET', 'GetRevenueMonthlyAction');

        self::assertSame('GetRevenueMonthlyAction.php', $descriptor->getFileName());
    }

    public function testGetFileNameForSimpleClass(): void
    {
        $descriptor = $this->makeDescriptor('GET', 'GetItemsAction');

        self::assertSame('GetItemsAction.php', $descriptor->getFileName());
    }

    // -----------------------------------------------------------------------
    // Readonly properties
    // -----------------------------------------------------------------------

    public function testPropertiesAreStoredCorrectly(): void
    {
        $descriptor = new ScaffoldDescriptor(
            httpMethod: 'POST',
            path: '/orders/{id}/cancel',
            connector: 'shopware',
            className: 'PostOrdersCancelAction',
            namespace: 'MyOrg\Connector\Shopware\Action\Scaffold',
            actionName: 'scaffold:shopware:orders-cancel',
            description: 'Cancel an order',
            parameters: ['order_id' => ['type' => 'string', 'required' => true, 'in' => 'path', 'description' => '']],
        );

        self::assertSame('POST', $descriptor->httpMethod);
        self::assertSame('/orders/{id}/cancel', $descriptor->path);
        self::assertSame('shopware', $descriptor->connector);
        self::assertSame('PostOrdersCancelAction', $descriptor->className);
        self::assertSame('MyOrg\Connector\Shopware\Action\Scaffold', $descriptor->namespace);
        self::assertSame('scaffold:shopware:orders-cancel', $descriptor->actionName);
        self::assertSame('Cancel an order', $descriptor->description);
        self::assertArrayHasKey('order_id', $descriptor->parameters);
    }
}
