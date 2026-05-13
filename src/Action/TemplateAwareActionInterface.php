<?php

declare(strict_types=1);

namespace Mosyca\Core\Action;

/**
 * Optional capability interface for actions that declare named Twig templates.
 *
 * Implement this in addition to ActionInterface when your action ships
 * named templates that operators can select via --template=<label>.
 *
 * The framework checks instanceof before calling getTemplates() — existing
 * actions that do NOT implement this interface are unaffected.
 *
 * Adding this interface to an existing action is backward-compatible.
 * Removing it from ActionInterface kept the core contract stable.
 *
 * @see ActionTrait  provides a default getTemplates() → [] implementation
 */
interface TemplateAwareActionInterface extends ActionInterface
{
    /**
     * Named Twig templates this action declares for 'text' format.
     *
     * Keys are short labels (e.g. 'slack', 'report').
     * Values are template paths relative to the connector templates directory
     * (e.g. 'order/margin-slack').
     *
     * Shown by mosyca:action:show and GET /api/v1/plugins/{action} so operators
     * know which --template= values are available.
     *
     * @return array<string, string> ['label' => 'path/to/template']
     */
    public function getTemplates(): array;
}
