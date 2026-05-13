<?php

declare(strict_types=1);

namespace Mosyca\Core\Action;

/**
 * Default implementations for the optional parts of ActionInterface.
 *
 * Action authors who use this trait are automatically safe when the framework
 * adds new optional methods with defaults in future minor releases.
 *
 * Required methods that MUST be implemented per action (no default possible):
 *   getName(), getDescription(), getUsage(), getParameters(), isMutating(), execute()
 *
 * Usage:
 *   final class MyAction implements ActionInterface
 *   {
 *       use ActionTrait;
 *       // only implement the required methods above
 *   }
 *
 * With TemplateAwareActionInterface:
 *   final class MyAction implements TemplateAwareActionInterface
 *   {
 *       use ActionTrait;
 *       // override getTemplates() to return your named templates
 *   }
 *
 * Stability contract:
 *   - ActionInterface: FROZEN after V1.0 — no new required methods ever.
 *   - New optional capabilities → new capability interface (e.g. TemplateAwareActionInterface).
 *   - ActionTrait: provides defaults for all capability interfaces shipped with core.
 *     Using this trait protects you from breaking changes on minor upgrades.
 */
trait ActionTrait
{
    /**
     * Required OAuth scopes / API permissions.
     * Default: none (most actions need no OAuth scope check).
     *
     * @return string[]
     */
    public function getRequiredScopes(): array
    {
        return [];
    }

    /**
     * Tags for grouping and discovery.
     * Default: none.
     *
     * @return string[]
     */
    public function getTags(): array
    {
        return [];
    }

    /**
     * Default output format.
     * Default: 'json'.
     */
    public function getDefaultFormat(): string
    {
        return 'json';
    }

    /**
     * Default Twig template for 'text' format.
     * Default: null (uses the generic core/default.txt.twig).
     */
    public function getDefaultTemplate(): ?string
    {
        return null;
    }

    /**
     * Named Twig templates declared by this action.
     * Default: none.
     *
     * Override this when implementing TemplateAwareActionInterface.
     *
     * @return array<string, string> ['label' => 'path/to/template']
     */
    public function getTemplates(): array
    {
        return [];
    }
}
