# Mosyca Core

> **One Plugin. Every Interface. Any API.**

[![Packagist Version](https://img.shields.io/packagist/v/mosyca/core)](https://packagist.org/packages/mosyca/core)
[![PHP](https://img.shields.io/packagist/php-v/mosyca/core)](composer.json)
[![License](https://img.shields.io/packagist/l/mosyca/core)](LICENSE)

Mosyca Core is a PHP/Symfony framework where a single class — a **Plugin** — automatically becomes:

| Interface | How |
|---|---|
| 🖥️ CLI command | `bin/console shopware:order:get-margin --format=json` |
| 🌐 REST endpoint | `POST /api/plugins/shopware:order:get-margin/run` |
| 🤖 MCP tool | Claude calls it directly via Claude Desktop |
| 📄 OpenAPI entry | Auto-generated, always in sync |
| 🧩 PHP service | Injected anywhere via Symfony DI |

Write the plugin once. Never write an adapter again.

---

## Who is this for?

Mosyca is aimed at **senior PHP engineers and software architects** who already know
Symfony, API Platform, and Composer — and are tired of writing the same adapter
boilerplate every time a new interface is required for the same business logic.

**You will feel at home if you:**
- Have written a Symfony bundle or compiler pass before
- Use API Platform and know what a `ProviderInterface` is
- Maintain one or more external API integrations (Shopware, Stripe, ERPs, …)
- Want Claude (Desktop or Code) to call your PHP business logic via MCP — without
  hand-rolling a separate Node.js MCP server

**Mosyca is not for:**
- PHP / Symfony beginners (the framework assumes deep familiarity)
- Teams looking for a no-code solution
- Non-Symfony PHP stacks (Laravel, Slim, etc.)

---

## Requirements

- PHP 8.2+
- Symfony 7.1+

---

## Installation

```bash
composer require mosyca/core
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Mosyca\Core\MosycaCoreBundle::class => ['all' => true],
];
```

---

## Quick Start

### 1. Write a plugin

```php
<?php

namespace App\Plugin;

use Mosyca\Core\Plugin\PluginInterface;
use Mosyca\Core\Plugin\PluginResult;

final class OrderMarginPlugin implements PluginInterface
{
    public function getName(): string        { return 'shopware:order:get-margin'; }
    public function getDescription(): string { return 'Calculates gross margin for an order.'; }
    public function getUsage(): string       { return 'Pass an order_id and get margin data back.'; }
    public function getTags(): array         { return ['shopware', 'finance']; }
    public function isMutating(): bool       { return false; }
    public function getDefaultFormat(): string   { return 'json'; }
    public function getDefaultTemplate(): ?string { return null; }
    public function getRequiredScopes(): array    { return []; }

    public function getParameters(): array
    {
        return [
            'order_id' => [
                'type'        => 'string',
                'description' => 'Shopware 6 order UUID.',
                'required'    => true,
                'example'     => '018e-1234-abcd-efgh',
            ],
        ];
    }

    public function execute(array $args): PluginResult
    {
        // Your API call here.
        $margin = 42.5;

        return PluginResult::ok(
            ['order_id' => $args['order_id'], 'margin_percent' => $margin],
            "Margin for order {$args['order_id']}: {$margin}%",
        );
    }
}
```

### 2. Register as a service

```yaml
# config/services.yaml
services:
    App\Plugin\OrderMarginPlugin: ~
```

That's it. The plugin is auto-tagged and registered via `MosycaCoreBundle`.

### 3. Use it — everywhere at once

**CLI:**
```bash
bin/console shopware:order:get-margin --order_id=018e-1234 --format=table
bin/console shopware:order:get-margin --order_id=018e-1234 --format=yaml
bin/console help shopware:order:get-margin  # shows getUsage() + all parameters
```

**REST:**
```bash
curl -X POST https://yourapp.com/api/plugins/shopware/order/get-margin/run \
     -H "Content-Type: application/json" \
     -d '{"args": {"order_id": "018e-1234"}, "_format": "json"}'
```

**MCP (Claude Desktop):**
```
Ask Claude: "What's the margin on order 018e-1234?"
Claude calls: shopware_order_get_margin({"order_id": "018e-1234"})
Claude responds: "The margin is 42.5%."
```

**Plugin list:**
```bash
bin/console mosyca:plugin:list
bin/console mosyca:plugin:show shopware:order:get-margin
```

---

## Output Formats

Every plugin supports six output formats — switch with `--format` or `?format=`:

| Format | Description |
|--------|-------------|
| `json` | Pretty-printed JSON (default) |
| `yaml` | YAML |
| `table` | ASCII box table |
| `text` | Twig template (named or inline) |
| `raw` | PHP `var_export()` |
| `mcp` | Human-readable plain text optimised for Claude |

```bash
bin/console core:system:ping --format=table
bin/console core:system:ping --format=text --template="Pong: {{ data.pong }}"
```

---

## PluginResult

```php
// Success
return PluginResult::ok($data, 'Human-readable summary');

// With HAL links and embedded resources
return PluginResult::ok($data, 'Done')
    ->withLinks(['self' => '/api/orders/123'])
    ->withEmbedded(['customer' => $customerData]);

// Error (never throw for business errors)
return PluginResult::error('Order not found', ['order_id' => $id]);
```

---

## Built-in Plugins

| Plugin | Command | Description |
|--------|---------|-------------|
| `core:system:ping` | `bin/console core:system:ping` | Health check — returns pong |
| `core:system:echo` | `bin/console core:system:echo --message=hello` | Echoes all input parameters |

---

## REST API (via API Platform)

When `api-platform/core` is installed, every plugin gets REST endpoints automatically:

```
GET  /api/plugins                                        → list all plugins
GET  /api/plugins/{connector}/{resource}/{action}        → plugin detail + JSON Schema
POST /api/plugins/{connector}/{resource}/{action}/run    → execute plugin
GET  /api/mcp/tools                                      → MCP list_tools format
GET  /api/docs                                           → Swagger UI
GET  /api/docs.json                                      → OpenAPI 3.0 spec
```

---

## Development

```bash
git clone https://github.com/mosyca/core
cd core
composer install

# Tests
vendor/bin/phpunit

# Static analysis (level 8)
vendor/bin/phpstan analyse

# Code style
vendor/bin/php-cs-fixer fix
```

---

## Architecture

```
PluginInterface          ← implement this once
       │
       ├── MosycaCoreBundle    (Symfony Bundle + auto-discovery)
       ├── ConsoleAdapter      (bin/console command per plugin)
       ├── Gateway             (REST endpoint + OpenAPI via API Platform)
       ├── OutputRenderer      (json / yaml / table / text / raw / mcp)
       └── Bridge (V0.6)       (MCP server → Claude Desktop)
```

---

## Links

- **Source:** [github.com/mosyca/core](https://github.com/mosyca/core)
- **Packagist:** [packagist.org/packages/mosyca/core](https://packagist.org/packages/mosyca/core)
- **Issues:** [github.com/mosyca/core/issues](https://github.com/mosyca/core/issues)

---

## License

MIT © Roland Urban
