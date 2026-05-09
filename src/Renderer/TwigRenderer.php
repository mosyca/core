<?php

declare(strict_types=1);

namespace Mosyca\Core\Renderer;

use Mosyca\Core\Plugin\PluginResult;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;

final class TwigRenderer
{
    private readonly Environment $namedEnv;

    public function __construct(
        private readonly Normalizer $normalizer,
        string $templatesPath = '',
    ) {
        $path = '' !== $templatesPath ? $templatesPath : \dirname(__DIR__, 2).'/templates';
        $this->namedEnv = new Environment(
            new FilesystemLoader($path),
            ['autoescape' => false, 'strict_variables' => false],
        );
    }

    public function render(PluginResult $result, ?string $template): string
    {
        $context = $this->normalizer->normalize($result);

        if (null !== $template && $this->isInline($template)) {
            return $this->renderInline($template, $context);
        }

        $name = ($template ?? 'core/default').'.txt.twig';

        return $this->namedEnv->render($name, $context);
    }

    /**
     * Render an untrusted (operator-supplied) inline template in a Twig sandbox.
     *
     * The sandbox blocks file access functions (source, include, embed) and
     * restricts available tags, filters, and functions to a safe allowlist.
     *
     * @param array<string, mixed> $context
     */
    public function renderInline(string $template, array $context): string
    {
        $key = 'inline';
        $env = new Environment(
            new ArrayLoader([$key => $template]),
            ['autoescape' => false],
        );

        $policy = new SecurityPolicy(
            allowedTags: ['if', 'else', 'elseif', 'endif', 'for', 'endfor', 'set'],
            allowedFilters: [
                'abs', 'capitalize', 'date', 'default', 'e', 'escape',
                'first', 'join', 'keys', 'last', 'length', 'lower',
                'number_format', 'replace', 'reverse', 'round',
                'slice', 'sort', 'title', 'trim', 'upper',
            ],
            allowedMethods: [],
            allowedProperties: [],
            allowedFunctions: ['range', 'max', 'min', 'date'],
        );
        $env->addExtension(new SandboxExtension($policy, sandboxed: true));

        return $env->render($key, $context);
    }

    private function isInline(string $template): bool
    {
        return str_contains($template, '{{') || str_contains($template, '{%');
    }
}
