# mosyca/core – Claude Code Context

PHP library. Namespace `Mosyca\Core\`. Symfony 7.1+ bundle.

## Current Structure (V0.5)

```
src/
  MosycaCoreBundle.php
  MosycaCore.php

  Plugin/
    PluginInterface.php       ← The core contract (10 methods)
    PluginResult.php          ← Unified output (ok/error, withLinks, withEmbedded)
    PluginRegistry.php        ← Tagged service registry + filter()
    Attribute/
      AsPlugin.php            ← PHP 8 attribute (used by bundle auto-discovery)

  DependencyInjection/
    MosycaCoreExtension.php
    Compiler/
      PluginRegistrationPass.php   ← collects services tagged mosyca.plugin
      PluginCommandLoaderPass.php  ← wraps builtin loader in PluginChainCommandLoader

  Console/
    ConsoleAdapter.php             ← CommandLoaderInterface → one command per plugin
    PluginCommand.php              ← wraps PluginInterface as Symfony Command
    PluginChainCommandLoader.php   ← chains builtin + ConsoleAdapter
    Command/
      PluginListCommand.php        ← mosyca:plugin:list [--tag=]
      PluginShowCommand.php        ← mosyca:plugin:show <name>

  Renderer/
    OutputRendererInterface.php
    OutputRenderer.php             ← dispatches by format string
    Normalizer.php                 ← PluginResult → array + HATEOAS
    JsonRenderer.php
    YamlRenderer.php
    RawRenderer.php
    TableRenderer.php
    TwigRenderer.php
    McpRenderer.php

  Gateway/
    Resource/
      PluginResource.php           ← #[ApiResource] GET /api/plugins[/{name}]
      McpToolResource.php          ← #[ApiResource] GET /api/mcp/tools
    Provider/
      PluginProvider.php           ← collection (filtered) + item (full schema)
      McpToolProvider.php          ← MCP list_tools format
    Processor/
      PluginRunProcessor.php       ← POST /api/plugins/{name}/run

config/
  services.yaml

examples/
  PingPlugin.php              ← Reference implementation (core:system:ping)
  EchoPlugin.php              ← Echoes all input parameters (core:system:echo)
  run.php                     ← php examples/run.php → prints ✅ pong

tests/
  Plugin/
    PluginInterfaceTest.php
    PluginResultTest.php
  Renderer/
    (snapshot tests per renderer)
  Console/
    ConsoleAdapterTest.php
    PluginCommandTest.php
    PluginListCommandTest.php
    ConsoleAdapterFunctionalTest.php
  Gateway/
    PluginProviderTest.php
    PluginRunProcessorTest.php
```

## Quality Commands

```bash
# Run from core/ directory (or via /project:checks in Claude Code)
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

**API Platform resource discovery:** Applications consuming mosyca/core must
add the Gateway resource directory to `api_platform.yaml`:
```yaml
api_platform:
  resource_class_directories:
    - '%kernel.project_dir%/vendor/mosyca/core/src/Gateway/Resource'
```

## Completed Slices

```
V0.1  PluginInterface, PluginResult, PluginRegistry, examples, tooling
V0.2  MosycaCoreBundle, DI extension, PluginRegistrationPass, auto-discovery
V0.3  OutputRenderer pipeline (6 formats: json/yaml/raw/table/text/mcp)
V0.4  ConsoleAdapter, PluginCommand, PluginChainCommandLoader,
      PluginCommandLoaderPass, PluginListCommand, PluginShowCommand
V0.5  REST Gateway via API Platform 3.4: PluginResource, McpToolResource,
      PluginProvider (collection + item + JSON Schema), McpToolProvider,
      PluginRunProcessor (POST /run → JsonResponse)
```

## What V0.6 Adds (next slice — separate repo: bridge/)

```
@mosyca/bridge (npm)
  server.mjs (stdio transport)
  MosycaClient (fetch wrapper to Gateway)
  list_tools and call_tool MCP handlers
  Published to npmjs.com as @mosyca/bridge@0.1.0

Documentation (ships with V0.6)
  docs/30_getting_started.md
```

## Commit Convention

Conventional Commits: `feat(v0.x): ...`, `fix: ...`, `test: ...`, `docs: ...`, `chore: ...`
Commit and push after every completed slice (see workspace CLAUDE.md).
