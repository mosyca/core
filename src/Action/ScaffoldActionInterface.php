<?php

declare(strict_types=1);

namespace Mosyca\Core\Action;

/**
 * Marker interface for Scaffold actions.
 *
 * Scaffold actions make raw API calls without result transformation.
 * They may return arbitrary remote API responses, potentially including PII.
 *
 * Any action implementing this interface is permanently excluded from Depot
 * caching regardless of what the ActionResult declares.
 * This is enforced centrally in ActionRunProcessor — it cannot be overridden.
 *
 * Introduced in V0.8 as a forward-declaration for V0.9 scaffold action support.
 */
interface ScaffoldActionInterface extends ActionInterface
{
}
