<?php

declare(strict_types=1);

namespace Mosyca\Core\Plugin;

use Mosyca\Core\Context\ExecutionContextInterface;

/**
 * PluginInterface – The Mosyca Core Contract.
 *
 * One implementation automatically becomes:
 *   - MCP Tool       (Claude Desktop / Claude Code)
 *   - CLI Command    (bin/console)
 *   - REST Endpoint  (API Platform)
 *   - PHP Service    (Symfony DI)
 *   - PWA Page       (Studio)
 *   - OpenAPI Entry  (auto-generated)
 *
 * Naming convention: "{connector}:{resource}:{action}"
 *   Examples: "shopware:order:get-margin", "spotify:playlist:add-track"
 *
 * REST route pattern: /api/v1/{plugin_name}/{tenant}/{resource}/{action}/run
 *   Examples: /api/v1/shopware/default/order/get-margin/run
 *             /api/v1/shopware/shop-berlin/order/get-margin/run
 *             /api/v1/core/default/system/ping/run
 *
 * Stability contract:
 *   - This interface is FROZEN after V1.0. No new required methods will ever be added.
 *   - New optional capabilities → new capability interface (e.g. TemplateAwarePluginInterface).
 *   - Use PluginTrait to automatically get defaults for all optional capability interfaces.
 */
interface PluginInterface
{
    /**
     * Unique plugin identifier.
     *
     * Used as:
     *   MCP Tool name:   shopware_order_get_margin
     *   CLI command:     shopware:order:get-margin
     *   REST route:      /api/plugins/shopware/order/get-margin/run
     *   PWA page:        /plugins/shopware/order/get-margin
     */
    public function getName(): string;

    /**
     * Short description (one line).
     *
     * Shown in MCP list_tools, bin/console list,
     * GET /api/plugins, Studio plugin list, OpenAPI summary.
     */
    public function getDescription(): string;

    /**
     * Full usage documentation.
     *
     * Claude Code reads this to understand WHEN to use this plugin,
     * WHAT it returns, WHICH error cases exist, and example inputs/outputs.
     * Write this as if explaining to a smart developer who has never seen
     * your code. Markdown is supported.
     */
    public function getUsage(): string;

    /**
     * Parameter schema.
     *
     * Becomes MCP inputSchema, CLI options, REST body validation,
     * OpenAPI parameter spec, and Studio "Try It" form fields.
     *
     * Format:
     * [
     *   'param_name' => [
     *     'type'        => 'string|integer|boolean|array',
     *     'description' => 'Human readable description',
     *     'required'    => true|false,
     *     'default'     => mixed,
     *     'example'     => mixed,
     *     'enum'        => ['value1', 'value2'],  // optional
     *   ]
     * ]
     *
     * @return array<string, array<string, mixed>>
     */
    public function getParameters(): array;

    /**
     * Required OAuth scopes / API permissions.
     *
     * Framework checks automatically whether the current OAuth token has
     * these scopes before execution. Missing scopes trigger re-auth.
     *
     * @return string[]
     */
    public function getRequiredScopes(): array;

    /**
     * Tags for grouping and discovery.
     *
     * Used in Studio plugin explorer, Exchange marketplace,
     * and bin/console list --tag=ecommerce.
     *
     * @return string[]
     */
    public function getTags(): array;

    /**
     * Is this plugin mutating (write) or readonly?
     *
     * true  → writes data, has side effects. CLI asks for confirmation.
     *         MCP warns Claude before execution. Ledger logs WARNING.
     * false → readonly, always safe to call.
     */
    public function isMutating(): bool;

    /**
     * Default output format.
     *
     * One of: 'json' | 'yaml' | 'table' | 'text' | 'mcp'
     * Can be overridden per request via --format or ?format=
     */
    public function getDefaultFormat(): string;

    /**
     * Default Twig template for 'text' format.
     *
     * Path relative to connector templates directory.
     * Return null to use the generic default template.
     */
    public function getDefaultTemplate(): ?string;

    /**
     * Execute the plugin.
     *
     * $args is validated against getParameters() before execute() is called.
     * You can rely on required params being present and correctly typed.
     *
     * $context carries the immutable execution context (tenant, user, ACL bypass flag).
     * Plugins must NOT import Symfony classes — use only ExecutionContextInterface.
     *
     * ACL vector pattern (domain-level authentication):
     * <code>
     *     if (!$context->isAclBypassed() && !$this->validatePin($args['pin'] ?? null)) {
     *         return PluginResult::failure(
     *             'Access denied. Domain authentication failed.',
     *             'ERROR_ACL_DENIED',
     *             'Provide the correct security_pin in the payload.',
     *         );
     *     }
     * </code>
     *
     * @param array<string, mixed>      $args    Validated input parameters
     * @param ExecutionContextInterface $context Immutable execution context
     *
     * Never throw for business errors — use PluginResult::failure() instead.
     * Only throw for truly unexpected system errors.
     */
    public function execute(array $args, ExecutionContextInterface $context): PluginResult;
}
