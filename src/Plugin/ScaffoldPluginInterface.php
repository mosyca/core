<?php

declare(strict_types=1);

namespace Mosyca\Core\Plugin;

/**
 * Marker interface for Scaffold plugins.
 *
 * Scaffold plugins make raw API calls without result transformation.
 * They may return arbitrary remote API responses, potentially including PII.
 *
 * Any plugin implementing this interface is permanently excluded from Depot
 * caching regardless of what the PluginResult declares.
 * This is enforced centrally in PluginRunProcessor — it cannot be overridden.
 *
 * Introduced in V0.8 as a forward-declaration for V0.9 scaffold plugin support.
 */
interface ScaffoldPluginInterface extends PluginInterface
{
}
