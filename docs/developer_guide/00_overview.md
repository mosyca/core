# Mosyca Plugin Developer Guide — Overview

This guide teaches you how to build a Mosyca plugin (connector package) from scratch.

The canonical teaching artifact is [`mosyca/plugin-demo`](https://github.com/mosyca/plugin-demo) — a
minimal, exhaustively commented plugin that uses `https://httpbin.org` as its target API. Every file
in that repo is designed to be read as tutorial code.

---

## What is a Mosyca Plugin?

A Mosyca plugin is a **Symfony Bundle** distributed as a Composer package. It adds one or more
API integrations to a Mosyca-powered application.

One plugin = one integration. Examples:
- `mosyca/connector-shopware6` — Shopware 6 Admin API
- `mosyca/connector-shopify` — Shopify Admin API (OAuth 2.0)
- `mosyca/plugin-demo` — httpbin.org reference implementation

---

## The Three Mandatory Artifacts

Every plugin must provide exactly three types of objects:

### 1. Resource

```
AbstractResource
  └─ getPluginNamespace()  → 'demo'           (URL segment, package identity)
  └─ getName()             → 'httpbin'         (domain entity slug)
  └─ getOperations()       → [                 (maps operations to Actions)
       'public' => [action => FetchPublicAction::class, method => 'GET', path => '/public'],
       'fetch'  => [action => FetchProtectedAction::class, method => 'GET', path => '/fetch'],
     ]
```

A Resource is a **pure protocol adapter**. It contains no business logic. It tells the framework
how to expose your domain entity over REST, MCP, and CLI.

### 2. Action

```
ActionInterface  (+ ActionTrait)
  └─ getName()          → 'demo:httpbin:fetch'
  └─ getDescription()   → one-line summary
  └─ getUsage()         → full Markdown docs (Claude reads this)
  └─ getParameters()    → input schema (→ MCP inputSchema, OpenAPI, CLI options)
  └─ isMutating()       → true/false
  └─ execute()          → ActionResult
```

An Action is the **single executable unit**. One class = one operation. It declares its own
documentation, parameters, and return type. It has no access to HTTP or Symfony internals.

### 3. Bundle + Extension

The Bundle class (extends `Symfony\HttpKernel\Bundle\Bundle`) is the Composer package entry point.
The Extension loads `services.yaml`. Together they wire the plugin into the host application's DI
container.

---

## Auto-Discovery: How the Framework Finds Your Actions

Mosyca Core calls `registerForAutoconfiguration()` on the DI container:

```
ActionInterface  → auto-tagged 'mosyca.action'   → registered in ActionRegistry
AbstractResource → auto-tagged 'mosyca.resource'  → registered in ResourceRegistry
```

This means: as long as your services are declared with `autoconfigure: true` in `services.yaml`,
**you never need to manually tag an action or resource**. The `#[AsAction]` attribute is a
documentation marker — the actual tagging is done by the Core bundle at compile time.

---

## What One Action Becomes

Implement `ActionInterface` once, and the framework creates:

| Channel | Result |
|---|---|
| MCP tool | `demo_httpbin_fetch` (colons → underscores) |
| CLI command | `bin/console demo:httpbin:fetch` |
| REST endpoint | `GET /api/v1/demo/{tenant}/httpbin/fetch` |
| OpenAPI entry | Auto-generated from `getParameters()` |
| Studio page | `/actions/demo/httpbin/fetch` |

---

## Naming Conventions

| Thing | Convention | Example |
|---|---|---|
| Package name | `mosyca/plugin-{slug}` | `mosyca/plugin-demo` |
| PHP namespace | `Mosyca\{PascalCased}` | `Mosyca\Demo` |
| Plugin namespace | lowercase, no hyphens | `demo` |
| Resource name | singular lowercase noun | `httpbin` |
| Action name | `{ns}:{resource}:{verb}` | `demo:httpbin:fetch` |
| Bundle class | `{Name}Plugin` or `{Name}Bundle` | `DemoPlugin` |

---

## Next: Build the Demo Plugin Step by Step

→ Continue to [01_quickstart.md](01_quickstart.md)
