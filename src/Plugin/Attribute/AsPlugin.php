<?php

declare(strict_types=1);

namespace Mosyca\Core\Plugin\Attribute;

/**
 * Marks a class as a Mosyca Plugin for auto-discovery.
 *
 * Used by the Symfony Bundle (V0.2+) to tag services automatically.
 * In V0.1 (no Symfony), it serves as documentation for the intent.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsPlugin
{
}
