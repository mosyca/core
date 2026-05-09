# mosyca/core – Claude Code Context

PHP library. Namespace `Mosyca\Core\`. No Symfony dependency in V0.1.

## Current Structure (V0.1)

```
src/
  Plugin/
    PluginInterface.php       ← The core contract (10 methods)
    PluginResult.php          ← Unified output (ok/error, withLinks, withEmbedded)
    PluginRegistry.php        ← In-memory registry, no DI yet
    Attribute/
      AsPlugin.php            ← PHP 8 attribute, used by Symfony Bundle in V0.2+

examples/
  PingPlugin.php              ← Reference implementation (core:system:ping)
  run.php                     ← php examples/run.php → prints ✅ pong

tests/
  Plugin/
    PluginInterfaceTest.php
    PluginResultTest.php
```

## Quality Commands

```bash
# Run from core/ directory
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress   # level 8
vendor/bin/php-cs-fixer check --no-interaction

# Fix CS issues (auto)
vendor/bin/php-cs-fixer fix --no-interaction

# All in one
vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress && vendor/bin/php-cs-fixer check --no-interaction && echo "✅ all green"

# Run the example
php examples/run.php
```

## Conventions

**Plugin naming:** `{connector}:{resource}:{action}` — all lowercase, hyphens allowed.
Example: `shopware:order:get-margin`, `core:system:ping`

**PluginResult:** Always return `PluginResult::ok()` or `PluginResult::error()`.
Never throw exceptions for business errors.

**New plugins go in:** `src/Plugin/` (framework) or `examples/` (demos).
Connector plugins live in `connector-*/src/Plugin/`.

**PHPStan level 8:** No `mixed` leaking without explicit handling.
Use `@param array<string, mixed>` not bare `array`.

## What V0.2 Adds (next slice)

```
src/
  Bundle/
    MosycaCoreBundle.php
    DependencyInjection/
      MosycaCoreExtension.php
  Plugin/
    PluginRegistry.php        ← rewritten to accept tagged Symfony services
```

After V0.2: `#[AsPlugin]` on a class → auto-registered in Symfony DI → no manual
`$registry->register(new FooPlugin())` needed.

## Commit Convention

Conventional Commits: `feat(v0.2): ...`, `fix: ...`, `test: ...`, `chore: ...`
Commit and push after every completed slice (see workspace CLAUDE.md).
