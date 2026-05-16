<?php

declare(strict_types=1);

namespace Mosyca\Core\Bridge\TenantSession;

/**
 * State machine for a pending tenant context-switch session.
 *
 * Transitions:
 *   PENDING → ACTIVE  (human approves the context switch)
 *   PENDING → DENIED  (human denies the context switch)
 *
 * ACTIVE and DENIED are terminal — no further transitions.
 */
enum TenantSessionState: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case DENIED = 'DENIED';
}
