<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Functional;

use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Plugin\PluginRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AutoDiscoveryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testPluginsAreAutoDiscovered(): void
    {
        self::bootKernel();

        /** @var PluginRegistry $registry */
        $registry = self::getContainer()->get(PluginRegistry::class);

        self::assertCount(2, $registry->all());
        self::assertTrue($registry->has('core:system:ping'));
        self::assertTrue($registry->has('core:system:echo'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
    }

    public function testDiscoveredPluginsAreExecutable(): void
    {
        self::bootKernel();

        /** @var PluginRegistry $registry */
        $registry = self::getContainer()->get(PluginRegistry::class);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('isAclBypassed')->willReturn(false);
        $context->method('getTenantId')->willReturn('default');

        $result = $registry->get('core:system:ping')->execute([], $context);

        self::assertTrue($result->success);
        self::assertSame('✅ pong', $result->summary);
    }
}
