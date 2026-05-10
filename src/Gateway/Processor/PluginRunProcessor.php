<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Mosyca\Core\Depot\DepotInterface;
use Mosyca\Core\Ledger\AccessLog;
use Mosyca\Core\Ledger\PluginLog;
use Mosyca\Core\Plugin\PluginRegistry;
use Mosyca\Core\Plugin\PluginResult;
use Mosyca\Core\Plugin\ScaffoldPluginInterface;
use Mosyca\Core\Renderer\OutputRendererInterface;
use Mosyca\Core\Vault\Acl\PluginAccessChecker;
use Mosyca\Core\Vault\Clearance\ClearanceRegistry;
use Mosyca\Core\Vault\Entity\Operator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * API Platform state processor for POST /api/plugins/{connector}/{resource}/{action}/run.
 *
 * Executes the named plugin with the args from the request body and returns a
 * JSON response rendered via OutputRenderer.
 *
 * V0.8 additions:
 *   - Generates request_id (UUID v4) per call
 *   - Access Log: always written, fixed schema, no request-arg fields
 *   - Depot: write on depotEligible=true AND call.depot=true AND !isScaffold
 *   - Scaffold guard: always strips depot eligibility
 *   - Plugin Log: written when ledgerPayload != null AND operator logLevel allows
 *
 * @implements ProcessorInterface<null, Response>
 */
final class PluginRunProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly OutputRendererInterface $renderer,
        private readonly AccessLog $accessLog,
        private readonly PluginLog $pluginLog,
        private readonly ClearanceRegistry $clearanceRegistry,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?PluginAccessChecker $accessChecker = null,
        private readonly ?DepotInterface $depot = null,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $requestId = self::generateRequestId();
        $startMs = (int) (microtime(true) * 1000);

        $connector = (string) ($uriVariables['connector'] ?? '');
        $resource = (string) ($uriVariables['resource'] ?? '');
        $action = (string) ($uriVariables['action'] ?? '');
        $pluginName = $connector.':'.$resource.':'.$action;

        $errorCode = null;
        $httpStatus = Response::HTTP_OK;
        /** @var PluginResult|null $result */
        $result = null;
        $response = null;

        try {
            if (!$this->registry->has($pluginName)) {
                throw new NotFoundHttpException(\sprintf("Plugin '%s' not found.", $pluginName));
            }

            $plugin = $this->registry->get($pluginName);

            // V0.7: ACL check — null when Vault is not configured (dev / no-auth mode)
            $this->accessChecker?->assertCanRun($plugin);

            // Read request body — API Platform passes `input: false`, so $data is null.
            $request = $context['request'] ?? null;
            $body = [];
            if ($request instanceof Request) {
                $raw = json_decode($request->getContent(), true);
                $body = \is_array($raw) ? $raw : [];
            }

            /** @var array<string, mixed> $args */
            $args = \is_array($body['args'] ?? null) ? $body['args'] : [];

            $format = null;
            if (isset($body['_format']) && \is_string($body['_format'])) {
                $format = $body['_format'];
            } elseif ($request instanceof Request && \is_string($request->query->get('format'))) {
                $format = $request->query->get('format');
            }
            $format ??= $plugin->getDefaultFormat();

            // _template_string = inline Twig; _template = named template path.
            $template = null;
            if (isset($body['_template_string']) && \is_string($body['_template_string'])) {
                $template = $body['_template_string'];
            } elseif (isset($body['_template']) && \is_string($body['_template'])) {
                $template = $body['_template'];
            } elseif ($request instanceof Request && \is_string($request->query->get('template'))) {
                $template = $request->query->get('template');
            }

            // — V0.8: Depot read (cache hit check) —
            $callWantsDepot = true === ($body['depot'] ?? false);
            $depotKey = null;

            if ($callWantsDepot && null !== $this->depot) {
                $operatorName = $this->currentOperator()?->getUsername() ?? 'anonymous';
                $depotKey = $this->depot->buildKey($operatorName, $connector, $pluginName, $args);
                $cached = $this->depot->get($depotKey);

                if (null !== $cached) {
                    // Serve from depot — skip plugin execution
                    $result = PluginResult::ok($cached['data'] ?? $cached, \is_string($cached['summary'] ?? null) ? $cached['summary'] : '');
                    $rendered = $this->renderer->render($result, $format, $template);
                    $decoded = json_decode($rendered, true);

                    $response = new JsonResponse(
                        \is_array($decoded) ? $decoded : ['output' => $rendered],
                        Response::HTTP_OK,
                    );

                    return $response;
                }
            }

            // — Execute plugin —
            $result = $plugin->execute($args);

            // — V0.8: Scaffold guard — permanently strip depot eligibility —
            if ($plugin instanceof ScaffoldPluginInterface) {
                $result = $result->withoutDepot();
            }

            // — V0.8: Depot write (double opt-in) —
            if ($callWantsDepot && null !== $this->depot && null !== $depotKey && $result->depotEligible) {
                $callerMaxTtl = isset($body['depotTtl']) && \is_int($body['depotTtl']) ? $body['depotTtl'] : \PHP_INT_MAX;
                $ttl = min($result->depotTtl, $callerMaxTtl);

                $this->depot->set($depotKey, [
                    'data' => $result->data,
                    'summary' => $result->summary,
                ], $ttl);
            }

            $httpStatus = $result->success ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY;
            if (!$result->success) {
                $errorCode = 'plugin_error';
            }

            $rendered = $this->renderer->render($result, $format, $template);
            $decoded = json_decode($rendered, true);

            $response = new JsonResponse(
                \is_array($decoded) ? $decoded : ['output' => $rendered],
                $httpStatus,
            );
        } catch (NotFoundHttpException $e) {
            $errorCode = 'not_found';
            $httpStatus = Response::HTTP_NOT_FOUND;

            throw $e;
        } catch (AccessDeniedHttpException $e) {
            $errorCode = 'acl_denied';
            $httpStatus = Response::HTTP_FORBIDDEN;

            throw $e;
        } catch (\Throwable $e) {
            $errorCode = 'plugin_error';
            $httpStatus = Response::HTTP_INTERNAL_SERVER_ERROR;

            throw $e;
        } finally {
            // — V0.8: Access Log — always written, no request-arg fields —
            $durationMs = (int) (microtime(true) * 1000) - $startMs;
            $this->writeAccessLog(
                requestId: $requestId,
                connector: $connector,
                pluginName: $pluginName,
                durationMs: $durationMs,
                success: null !== $result && $result->success,
                errorCode: $errorCode,
                httpStatus: $httpStatus,
            );
        }

        // — V0.8: Plugin Log — opt-in, written after response built —
        if (null !== $result->ledgerPayload) {
            $this->writePluginLog($requestId, $pluginName, $result);
        }

        return $response;
    }

    private function writeAccessLog(
        string $requestId,
        string $connector,
        string $pluginName,
        int $durationMs,
        bool $success,
        ?string $errorCode,
        int $httpStatus,
    ): void {
        $operator = $this->currentOperator();

        $this->accessLog->write([
            'ts' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'request_id' => $requestId,
            'operator' => $operator?->getUsername() ?? 'anonymous',
            'clearance' => $operator?->getClearance() ?? 'none',
            'connector' => $connector,
            'plugin' => $pluginName,
            'duration_ms' => $durationMs,
            'success' => $success,
            'error_code' => $errorCode,
            'http_status' => $httpStatus,
        ]);
    }

    private function writePluginLog(string $requestId, string $pluginName, PluginResult $result): void
    {
        $operator = $this->currentOperator();
        $logLevel = 'info';

        if (null !== $operator) {
            $clearance = $this->clearanceRegistry->get($operator->getClearance());
            $logLevel = null !== $clearance ? $clearance->logLevel : 'info';
        }

        $this->pluginLog->write(
            requestId: $requestId,
            pluginName: $pluginName,
            entryLevel: $result->ledgerLevel,
            payload: $result->ledgerPayload ?? [],
            operatorLogLevel: $logLevel,
        );
    }

    /**
     * Generate a UUID v4 request ID without requiring symfony/uid.
     */
    private static function generateRequestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80); // variant 10xx

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function currentOperator(): ?Operator
    {
        $token = $this->tokenStorage?->getToken();
        $user = $token?->getUser();

        return $user instanceof Operator ? $user : null;
    }
}
