<?php

declare(strict_types=1);

namespace Mosyca\Core\Gateway\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Mosyca\Core\Plugin\PluginRegistry;
use Mosyca\Core\Renderer\OutputRendererInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * API Platform state processor for POST /api/plugins/{connector}/{resource}/{action}/run.
 *
 * Executes the named plugin with the args from the request body and returns a
 * JSON response rendered via OutputRenderer.
 *
 * V0.5 scope: Vault ACL, Depot storage, and Ledger logging are not yet
 * implemented — they will be wired in V0.7 / V0.8.
 *
 * @implements ProcessorInterface<null, Response>
 */
final class PluginRunProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly OutputRendererInterface $renderer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $name = ($uriVariables['connector'] ?? '')
            .':'.($uriVariables['resource'] ?? '')
            .':'.($uriVariables['action'] ?? '');

        if (!$this->registry->has($name)) {
            throw new NotFoundHttpException(\sprintf("Plugin '%s' not found.", $name));
        }

        $plugin = $this->registry->get($name);

        // Read request body — API Platform passes `input: false`, so $data is null.
        $request = $context['request'] ?? null;
        $body = [];
        if ($request instanceof Request) {
            $decoded = json_decode($request->getContent(), true);
            $body = \is_array($decoded) ? $decoded : [];
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

        $template = null;
        if (isset($body['_template']) && \is_string($body['_template'])) {
            $template = $body['_template'];
        } elseif ($request instanceof Request && \is_string($request->query->get('template'))) {
            $template = $request->query->get('template');
        }

        $result = $plugin->execute($args);
        $rendered = $this->renderer->render($result, $format, $template);

        // If the renderer returns valid JSON, decode it so the response is not double-encoded.
        $decoded = json_decode($rendered, true);

        return new JsonResponse(
            \is_array($decoded) ? $decoded : ['output' => $rendered],
            $result->success ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
