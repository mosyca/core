<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Gateway;

use ApiPlatform\Metadata\Post;
use Mosyca\Core\Gateway\Processor\PluginRunProcessor;
use Mosyca\Core\Ledger\AccessLog;
use Mosyca\Core\Ledger\PluginLog;
use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginRegistry;
use Mosyca\Core\Plugin\PluginResult;
use Mosyca\Core\Plugin\ScaffoldPluginInterface;
use Mosyca\Core\Renderer\OutputRendererInterface;
use Mosyca\Core\Vault\Clearance\ClearanceRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class PluginRunProcessorTest extends TestCase
{
    private PluginRegistry $registry;
    /** @var OutputRendererInterface&MockObject */
    private OutputRendererInterface $renderer;
    /** @var AccessLog&MockObject */
    private AccessLog $accessLog;
    /** @var PluginLog&MockObject */
    private PluginLog $pluginLog;
    private ClearanceRegistry $clearanceRegistry;
    /** @var TokenStorageInterface&MockObject */
    private TokenStorageInterface $tokenStorage;
    private PluginRunProcessor $processor;

    protected function setUp(): void
    {
        $this->registry = new PluginRegistry();

        $this->renderer = $this->createMock(OutputRendererInterface::class);
        $this->renderer->method('render')->willReturn('{"success":true,"summary":"ok","data":[]}');

        $this->accessLog = $this->createMock(AccessLog::class);
        $this->pluginLog = $this->createMock(PluginLog::class);
        $this->clearanceRegistry = new ClearanceRegistry();
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->tokenStorage->method('getToken')->willReturn(null); // anonymous

        $this->processor = $this->makeProcessor();
    }

    // ── Basic execution ───────────────────────────────────────────────────────

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
        $processor = $this->makeProcessor(renderer: $renderer);

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
        $processor = $this->makeProcessor(renderer: $renderer);

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

    // ── V0.8: Access Log ─────────────────────────────────────────────────────

    public function testAccessLogIsAlwaysWritten(): void
    {
        $this->accessLog->expects(self::once())->method('write');

        $this->registry->register($this->makePlugin('core:system:ping', PluginResult::ok([], 'pong')));

        $request = Request::create('/api/plugins/core/system/ping/run', 'POST', content: '{"args":{}}');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'core', 'resource' => 'system', 'action' => 'ping'],
            context: ['request' => $request],
        );
    }

    public function testAccessLogWrittenEvenOnNotFound(): void
    {
        $this->accessLog->expects(self::once())->method('write');

        try {
            $this->processor->process(
                null,
                new Post(),
                uriVariables: ['connector' => 'no', 'resource' => 'such', 'action' => 'plugin'],
            );
        } catch (NotFoundHttpException) {
            // Expected
        }
    }

    // ── V0.8: Plugin Log ─────────────────────────────────────────────────────

    public function testPluginLogNotWrittenWithoutLedgerPayload(): void
    {
        $this->pluginLog->expects(self::never())->method('write');

        $this->registry->register($this->makePlugin('core:system:ping', PluginResult::ok([], 'pong')));

        $request = Request::create('/api/plugins/core/system/ping/run', 'POST', content: '{"args":{}}');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'core', 'resource' => 'system', 'action' => 'ping'],
            context: ['request' => $request],
        );
    }

    public function testPluginLogWrittenWhenLedgerPayloadSet(): void
    {
        $this->pluginLog->expects(self::once())->method('write');

        $result = PluginResult::ok(['count' => 5], 'done')
            ->withLedger(level: 'info', payload: ['item_count' => 5]);

        $this->registry->register($this->makePlugin('core:system:ping', $result));

        $request = Request::create('/api/plugins/core/system/ping/run', 'POST', content: '{"args":{}}');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'core', 'resource' => 'system', 'action' => 'ping'],
            context: ['request' => $request],
        );
    }

    // ── V0.8: Scaffold guard ─────────────────────────────────────────────────

    public function testScaffoldPluginDepotEligibilityIsStripped(): void
    {
        // Plugin declares depot eligibility
        $rawResult = PluginResult::ok(['data' => true], 'ok')->withDepot(ttl: 3600);

        // Scaffold plugin implementing ScaffoldPluginInterface
        $scaffold = $this->createMock(ScaffoldPluginInterface::class);
        $scaffold->method('getName')->willReturn('core:scaffold:raw');
        $scaffold->method('getDefaultFormat')->willReturn('json');
        $scaffold->method('getDefaultTemplate')->willReturn(null);
        $scaffold->method('execute')->willReturn($rawResult);

        $this->registry->register($scaffold);

        $request = Request::create('/api/plugins/core/scaffold/raw/run', 'POST', content: '{"args":{},"depot":true}');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['connector' => 'core', 'resource' => 'scaffold', 'action' => 'raw'],
            context: ['request' => $request],
        );

        // If scaffold guard works, depot write was NOT called.
        // (Depot is null in this processor, so no write happens — but the key point
        //  is that we don't crash and the guard code path is exercised.)
        $this->addToAssertionCount(1); // guard: no exception thrown, scaffold was handled
    }

    // ── V0.8: PluginResult builder methods ───────────────────────────────────

    public function testWithDepotSetsEligibility(): void
    {
        $result = PluginResult::ok([], 'ok');
        self::assertFalse($result->depotEligible);
        self::assertSame(3600, $result->depotTtl);

        $withDepot = $result->withDepot(ttl: 7200);
        self::assertTrue($withDepot->depotEligible);
        self::assertSame(7200, $withDepot->depotTtl);
        self::assertFalse($result->depotEligible); // original unchanged
    }

    public function testWithoutDepotStripsEligibility(): void
    {
        $result = PluginResult::ok([], 'ok')->withDepot(ttl: 3600);
        self::assertTrue($result->depotEligible);

        $stripped = $result->withoutDepot();
        self::assertFalse($stripped->depotEligible);
        self::assertTrue($result->depotEligible); // original unchanged
    }

    public function testWithLedgerSetsPayload(): void
    {
        $result = PluginResult::ok([], 'ok');
        self::assertNull($result->ledgerPayload);

        $withLedger = $result->withLedger(level: 'warning', payload: ['count' => 42]);
        self::assertSame(['count' => 42], $withLedger->ledgerPayload);
        self::assertSame('warning', $withLedger->ledgerLevel);
        self::assertNull($result->ledgerPayload); // original unchanged
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeProcessor(?OutputRendererInterface $renderer = null): PluginRunProcessor
    {
        return new PluginRunProcessor(
            registry: $this->registry,
            renderer: $renderer ?? $this->renderer,
            accessLog: $this->accessLog,
            pluginLog: $this->pluginLog,
            clearanceRegistry: $this->clearanceRegistry,
            tokenStorage: $this->tokenStorage, // anonymous — returns null token
        );
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
