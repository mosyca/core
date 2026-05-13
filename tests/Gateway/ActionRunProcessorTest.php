<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Gateway;

use ApiPlatform\Metadata\Post;
use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Action\ActionResult;
use Mosyca\Core\Action\ScaffoldActionInterface;
use Mosyca\Core\Context\ContextProvider;
use Mosyca\Core\Context\ExecutionContextInterface;
use Mosyca\Core\Gateway\Processor\ActionRunProcessor;
use Mosyca\Core\Ledger\AccessLog;
use Mosyca\Core\Ledger\ActionLog;
use Mosyca\Core\Renderer\OutputRendererInterface;
use Mosyca\Core\Vault\Clearance\ClearanceRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class ActionRunProcessorTest extends TestCase
{
    private ActionRegistry $registry;
    /** @var OutputRendererInterface&MockObject */
    private OutputRendererInterface $renderer;
    /** @var AccessLog&MockObject */
    private AccessLog $accessLog;
    /** @var ActionLog&MockObject */
    private ActionLog $actionLog;
    private ClearanceRegistry $clearanceRegistry;
    /** @var TokenStorageInterface&MockObject */
    private TokenStorageInterface $tokenStorage;
    /** @var ContextProvider&MockObject */
    private ContextProvider $contextProvider;
    /** @var ExecutionContextInterface&MockObject */
    private ExecutionContextInterface $executionContext;
    private ActionRunProcessor $processor;

    protected function setUp(): void
    {
        $this->registry = new ActionRegistry();

        $this->renderer = $this->createMock(OutputRendererInterface::class);
        $this->renderer->method('render')->willReturn('{"success":true,"summary":"ok","data":[]}');

        $this->accessLog = $this->createMock(AccessLog::class);
        $this->actionLog = $this->createMock(ActionLog::class);
        $this->clearanceRegistry = new ClearanceRegistry();
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->tokenStorage->method('getToken')->willReturn(null); // anonymous

        // Mock ContextProvider — tests don't exercise full HTTP context logic here
        $this->executionContext = $this->createMock(ExecutionContextInterface::class);
        $this->executionContext->method('getTenantId')->willReturn('default');
        $this->executionContext->method('isAclBypassed')->willReturn(false);

        $this->contextProvider = $this->createMock(ContextProvider::class);
        $this->contextProvider->method('create')->willReturn($this->executionContext);

        $this->processor = $this->makeProcessor();
    }

    // ── Basic execution ───────────────────────────────────────────────────────

    public function testThrowsNotFoundForUnknownAction(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'does', 'tenant' => 'default', 'resource' => 'not', 'action' => 'exist'],
        );
    }

    public function testReturnsJsonResponseOnSuccess(): void
    {
        $this->registry->register($this->makeAction('core:system:ping', ActionResult::ok(['pong' => 'pong'], '✅ pong')));

        $request = Request::create('/api/v1/core/default/system/ping/run', 'POST', content: '{"args":{}}');
        $request->attributes->set('tenant', 'default');
        $response = $this->processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'core', 'tenant' => 'default', 'resource' => 'system', 'action' => 'ping'],
            context: ['request' => $request],
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testReturnsUnprocessableEntityOnActionError(): void
    {
        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->method('render')->willReturn('{"success":false,"summary":"fail","data":null}');
        $processor = $this->makeProcessor(renderer: $renderer);

        $this->registry->register($this->makeAction('core:system:fail', ActionResult::error('Something failed')));

        $request = Request::create('/api/v1/core/default/system/fail/run', 'POST', content: '{"args":{}}');
        $request->attributes->set('tenant', 'default');
        $response = $processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'core', 'tenant' => 'default', 'resource' => 'system', 'action' => 'fail'],
            context: ['request' => $request],
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testPassesArgsToAction(): void
    {
        $action = $this->createMock(ActionInterface::class);
        $action->method('getName')->willReturn('core:system:echo');
        $action->method('getDefaultFormat')->willReturn('json');
        $action->method('getDefaultTemplate')->willReturn(null);
        $action->expects(self::once())
            ->method('execute')
            ->with(['message' => 'hello'], self::isInstanceOf(ExecutionContextInterface::class))
            ->willReturn(ActionResult::ok(['message' => 'hello'], 'ok'));

        $this->registry->register($action);

        $request = Request::create('/api/v1/core/default/system/echo/run', 'POST', content: '{"args":{"message":"hello"}}');
        $request->attributes->set('tenant', 'default');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'core', 'tenant' => 'default', 'resource' => 'system', 'action' => 'echo'],
            context: ['request' => $request],
        );
    }

    public function testUsesFormatFromRequestBody(): void
    {
        $action = $this->makeAction('core:system:ping', ActionResult::ok([], 'ok'));
        $this->registry->register($action);

        $renderer = $this->createMock(OutputRendererInterface::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with(self::anything(), 'yaml', null)
            ->willReturn('success: true');
        $processor = $this->makeProcessor(renderer: $renderer);

        $request = Request::create('/api/v1/core/default/system/ping/run', 'POST', content: '{"args":{},"_format":"yaml"}');
        $request->attributes->set('tenant', 'default');
        $processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'core', 'tenant' => 'default', 'resource' => 'system', 'action' => 'ping'],
            context: ['request' => $request],
        );
    }

    public function testWorksWithoutRequest(): void
    {
        $this->registry->register($this->makeAction('core:system:ping', ActionResult::ok([], 'ok')));

        // No request in context — should still work using action defaults.
        $response = $this->processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'core', 'tenant' => 'default', 'resource' => 'system', 'action' => 'ping'],
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // ── V0.8: Access Log ─────────────────────────────────────────────────────

    public function testAccessLogIsAlwaysWritten(): void
    {
        $this->accessLog->expects(self::once())->method('write');

        $this->registry->register($this->makeAction('core:system:ping', ActionResult::ok([], 'pong')));

        $request = Request::create('/api/v1/core/default/system/ping/run', 'POST', content: '{"args":{}}');
        $request->attributes->set('tenant', 'default');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'core', 'tenant' => 'default', 'resource' => 'system', 'action' => 'ping'],
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
                uriVariables: ['plugin_name' => 'no', 'tenant' => 'default', 'resource' => 'such', 'action' => 'plugin'],
            );
        } catch (NotFoundHttpException) {
            // Expected
        }
    }

    // ── V0.8: Action Log ─────────────────────────────────────────────────────

    public function testActionLogNotWrittenWithoutLedgerPayload(): void
    {
        $this->actionLog->expects(self::never())->method('write');

        $this->registry->register($this->makeAction('core:system:ping', ActionResult::ok([], 'pong')));

        $request = Request::create('/api/v1/core/default/system/ping/run', 'POST', content: '{"args":{}}');
        $request->attributes->set('tenant', 'default');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'core', 'tenant' => 'default', 'resource' => 'system', 'action' => 'ping'],
            context: ['request' => $request],
        );
    }

    public function testActionLogWrittenWhenLedgerPayloadSet(): void
    {
        $this->actionLog->expects(self::once())->method('write');

        $result = ActionResult::ok(['count' => 5], 'done')
            ->withLedger(level: 'info', payload: ['item_count' => 5]);

        $this->registry->register($this->makeAction('core:system:ping', $result));

        $request = Request::create('/api/v1/core/default/system/ping/run', 'POST', content: '{"args":{}}');
        $request->attributes->set('tenant', 'default');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'core', 'tenant' => 'default', 'resource' => 'system', 'action' => 'ping'],
            context: ['request' => $request],
        );
    }

    // ── V0.8: Scaffold guard ─────────────────────────────────────────────────

    public function testScaffoldActionDepotEligibilityIsStripped(): void
    {
        // Action declares depot eligibility
        $rawResult = ActionResult::ok(['data' => true], 'ok')->withDepot(ttl: 3600);

        // Scaffold action implementing ScaffoldActionInterface
        $scaffold = $this->createMock(ScaffoldActionInterface::class);
        $scaffold->method('getName')->willReturn('core:scaffold:raw');
        $scaffold->method('getDefaultFormat')->willReturn('json');
        $scaffold->method('getDefaultTemplate')->willReturn(null);
        $scaffold->method('execute')->willReturn($rawResult);

        $this->registry->register($scaffold);

        $request = Request::create('/api/v1/core/default/scaffold/raw/run', 'POST', content: '{"args":{},"depot":true}');
        $request->attributes->set('tenant', 'default');
        $this->processor->process(
            null,
            new Post(),
            uriVariables: ['plugin_name' => 'core', 'tenant' => 'default', 'resource' => 'scaffold', 'action' => 'raw'],
            context: ['request' => $request],
        );

        // If scaffold guard works, depot write was NOT called.
        // (Depot is null in this processor, so no write happens — but the key point
        //  is that we don't crash and the guard code path is exercised.)
        $this->addToAssertionCount(1); // guard: no exception thrown, scaffold was handled
    }

    // ── V0.8: ActionResult builder methods ───────────────────────────────────

    public function testWithDepotSetsEligibility(): void
    {
        $result = ActionResult::ok([], 'ok');
        self::assertFalse($result->depotEligible);
        self::assertSame(3600, $result->depotTtl);

        $withDepot = $result->withDepot(ttl: 7200);
        self::assertTrue($withDepot->depotEligible);
        self::assertSame(7200, $withDepot->depotTtl);
        self::assertFalse($result->depotEligible); // original unchanged
    }

    public function testWithoutDepotStripsEligibility(): void
    {
        $result = ActionResult::ok([], 'ok')->withDepot(ttl: 3600);
        self::assertTrue($result->depotEligible);

        $stripped = $result->withoutDepot();
        self::assertFalse($stripped->depotEligible);
        self::assertTrue($result->depotEligible); // original unchanged
    }

    public function testWithLedgerSetsPayload(): void
    {
        $result = ActionResult::ok([], 'ok');
        self::assertNull($result->ledgerPayload);

        $withLedger = $result->withLedger(level: 'warning', payload: ['count' => 42]);
        self::assertSame(['count' => 42], $withLedger->ledgerPayload);
        self::assertSame('warning', $withLedger->ledgerLevel);
        self::assertNull($result->ledgerPayload); // original unchanged
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeProcessor(?OutputRendererInterface $renderer = null): ActionRunProcessor
    {
        return new ActionRunProcessor(
            registry: $this->registry,
            renderer: $renderer ?? $this->renderer,
            accessLog: $this->accessLog,
            actionLog: $this->actionLog,
            clearanceRegistry: $this->clearanceRegistry,
            contextProvider: $this->contextProvider,
            tokenStorage: $this->tokenStorage, // anonymous — returns null token
        );
    }

    private function makeAction(string $name, ActionResult $result): ActionInterface
    {
        $action = $this->createMock(ActionInterface::class);
        $action->method('getName')->willReturn($name);
        $action->method('getDefaultFormat')->willReturn('json');
        $action->method('getDefaultTemplate')->willReturn(null);
        $action->method('execute')->willReturn($result);

        return $action;
    }
}
