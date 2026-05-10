<?php

declare(strict_types=1);

namespace Mosyca\Core\Plugin;

/**
 * Optional capability interface for plugins that declare named Twig templates.
 *
 * Implement this in addition to PluginInterface when your plugin ships
 * named templates that operators can select via --template=<label>.
 *
 * The framework checks instanceof before calling getTemplates() — existing
 * plugins that do NOT implement this interface are unaffected.
 *
 * Adding this interface to an existing plugin is backward-compatible.
 * Removing it from PluginInterface kept the core contract stable.
 *
 * @see PluginTrait  provides a default getTemplates() → [] implementation
 */
interface TemplateAwarePluginInterface extends PluginInterface
{
    /**
     * Named Twig templates this plugin declares for 'text' format.
     *
     * Keys are short labels (e.g. 'slack', 'report').
     * Values are template paths relative to the connector templates directory
     * (e.g. 'order/margin-slack').
     *
     * Shown by mosyca:plugin:show and GET /api/plugins/{plugin} so operators
     * know which --template= values are available.
     *
     * @return array<string, string> ['label' => 'path/to/template']
     */
    public function getTemplates(): array;
}
