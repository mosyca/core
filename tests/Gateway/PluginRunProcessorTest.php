<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Gateway;

use ApiPlatform\Metadata\Post;
use Mosyca\Core\Gateway\Processor\PluginRunProcessor;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginRegistry;
use Mosyca\Core\Plugin\PluginResult;
use Mosyca\Core\Renderer\OutputRendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PluginRunProcessorTest extends TestCase
{
    private PluginRegistry $registry;
    private PluginRunProcessor $processor;

    protected function setUp(): void
    {
        $this->registry = new PluginRegistry();
        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('{"success":true,"summary":"ok","data":[]}');
        $this->processor = new PluginRunProcessor($this->registry, $renderer);
    }

    public function testThrowsNotFoundForUnknownPlugin(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'does', 'resource' => 'not', 'action' => 'exist'],
        );
    }

    public function testReturnsJsonResponseOnSuccess(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', PluginResult::ok(['pong' => 'pong'], '✅ pong')));

        $request = Request::create('/api/plugins/core/system/ping/run', 'POST', content: '{"args":{}}');
        $response = $this->processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'core', 'resource' => 'system', 'action' => 'ping'],
            context: ['request' => $request],
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testReturnsUnprocessableEntityOnPluginError(): void
    {
        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('{"success":false,"summary":"fail","data":null}');
        $processor = new PluginRunProcessor($this->registry, $renderer);

        $this->registry->register($this->makePlugin('core:system:fail', PluginResult::error('Something failed')));

        $request = Request::create('/api/plugins/core/system/fail/run', 'POST', content: '{"args":{}}');
        $response = $processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'core', 'resource' => 'system', 'action' => 'fail'],
            context: ['request' => $request],
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testPassesArgsToPlugin(): void
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn('core:system:echo');
        $plugin->method('getDefaultFormat')->willReturn('json');
        $plugin->method('getDefaultTemplate')->willReturn(null);
        $plugin->expects(self::once())
            ->method('execute')
            ->with(['message' => 'hello'])
            ->willReturn(PluginResult::ok(['message' => 'hello'], 'ok'));

        $this->registry->register($plugin);

        $request = Request::create('/api/plugins/core/system/echo/run', 'POST', content: '{"args":{"message":"hello"}}');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'core', 'resource' => 'system', 'action' => 'echo'],
            context: ['request' => $request],
        );
    }

    public function testUsesFormatFromRequestBody(): void
    {
        $plugin = $this->makePlugin('core:system:ping', PluginResult::ok([], 'ok'));
        $this->registry->register($plugin);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with(self::anything(), 'yaml', null)
            ->willReturn('success: true');
        $processor = new PluginRunProcessor($this->registry, $renderer);

        $request = Request::create('/api/plugins/core/system/ping/run', 'POST', content: '{"args":{},"_format":"yaml"}');
        $processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'core', 'resource' => 'system', 'action' => 'ping'],
            context: ['request' => $request],
        );
    }

    public function testWorksWithoutRequest(): void
    {
        $this->registry->register($this->makePlugin('core:system:ping', PluginResult::ok([], 'ok')));

        // No request in context — should still work using plugin defaults.
        $response = $this->processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'core', 'resource' => 'system', 'action' => 'ping'],
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    private function makePlugin(string $name, PluginResult $result): PluginInterface
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn($name);
        $plugin->method('getDefaultFormat')->willReturn('json');
        $plugin->method('getDefaultTemplate')->willReturn(null);
        $plugin->method('execute')->willReturn($result);

        return $plugin;
    }
}
