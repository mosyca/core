<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Functional;

use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Context\ExecutionContextInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AutoDiscoveryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testActionsAreAutoDiscovered(): void
    {
        self::bootKernel();

        /** @var ActionRegistry $registry */
        $registry = self::getContainer()->get(ActionRegistry::class);

        self::assertCount(2, $registry->all());
        self::assertTrue($registry->has('mosyca:system:ping'));
        self::assertTrue($registry->has('mosyca:system:echo'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
    }

    public function testDiscoveredActionsAreExecutable(): void
    {
        self::bootKernel();

        /** @var ActionRegistry $registry */
        $registry = self::getContainer()->get(ActionRegistry::class);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('isAclBypassed')->willReturn(false);
        $context->method('getTenantId')->willReturn('default');

        $result = $registry->get('mosyca:system:ping')->execute([], $context);

        self::assertTrue($result->success);
        self::assertSame('✅ pong', $result->summary);
    }
}
