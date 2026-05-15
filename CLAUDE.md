# mosyca/core – Claude Code Context

PHP library. Namespace `Mosyca\Core\`. Symfony 7.1+ bundle.

---

## Nomenclature Rules (ENFORCED)

### Plugin vs Action — never confuse these

| Term | Definition |
|---|---|
| **Plugin** | A Mosyca Composer package (e.g. `mosyca/connector-shopware6`). A bundle of Actions. |
| **Action** | The single executable unit. Implements `ActionInterface`. Registered via `#[AsAction]`. |
| **ActionResult** | The response envelope returned by `execute()`. |

**Examples of correct usage:**
- "The Shopware plugin provides the `shopware:order:get-margin` action."
- "Register your action with `#[AsAction]`."
- "Actions must implement `ActionInterface::execute()`."

**Examples of WRONG usage (never write these):**
- ~~`PluginInterface`~~ → write `ActionInterface`
- ~~`PluginResult`~~ → write `ActionResult`
- ~~`#[AsPlugin]`~~ → write `#[AsAction]`
- ~~"The ping plugin"~~ → write "the ping action"

---

## Current Structure (V0.13b)

```
src/
  MosycaCoreBundle.php
  MosycaCore.php

  Action/
    ActionInterface.php           ← The core contract (10 methods)
    ActionResult.php              ← Unified output (ok/failure, withLinks, withEmbedded, withDepot, withLedger)
    ActionTrait.php               ← Default implementations for common methods
    ActionRegistry.php            ← Tagged service registry
    TemplateAwareActionInterface.php
    ScaffoldActionInterface.php   ← Marker — permanently excluded from Depot
    Attribute/
      AsAction.php                ← PHP 8 attribute (used by bundle auto-discovery)
    Builtin/
      PingAction.php              ← core:system:ping
      EchoAction.php              ← core:system:echo

  DependencyInjection/
    MosycaCoreExtension.php
    Compiler/
      ActionRegistrationPass.php    ← collects services tagged mosyca.action
      ActionCommandLoaderPass.php   ← wraps builtin loader in ActionChainCommandLoader

  Console/
    ConsoleAdapter.php              ← CommandLoaderInterface → one command per action
    ActionCommand.php               ← wraps ActionInterface as Symfony Command
    ActionChainCommandLoader.php    ← chains builtin + ConsoleAdapter
    Command/
      ActionListCommand.php         ← mosyca:action:list [--tag=]
      ActionShowCommand.php         ← mosyca:action:show <name>

  Renderer/
    OutputRendererInterface.php
    OutputRenderer.php              ← dispatches by format string
    Normalizer.php                  ← ActionResult → array + HATEOAS
    JsonRenderer.php
    YamlRenderer.php
    RawRenderer.php
    TableRenderer.php
    TwigRenderer.php
    McpRenderer.php

  Gateway/
    Resource/
      ActionResource.php            ← #[ApiResource] GET/POST /api/v1/{plugin_name}/{tenant}/{resource}/{action}
      McpToolResource.php           ← #[ApiResource] GET /api/mcp/tools
    Provider/
      ActionProvider.php            ← collection (filtered) + item (full schema)
      McpToolProvider.php           ← MCP list_tools format
    Processor/
      ActionRunProcessor.php        ← POST /api/v1/.../run → JsonResponse

  Bridge/
    ConstraintSchemaTranslator.php  ← getParameters() → JSON Schema Draft-07
    McpDiscoveryService.php         ← list_tools via ResourceRegistry (ADR 3.1, 3.2, 3.4)
    McpExecutionService.php         ← call_tool (tenant extraction, execute, toArray)
    Controller/
      McpRpcController.php          ← POST /api/v1/mcp/rpc — JSON-RPC 2.0 entry point

  Context/
    ExecutionContextInterface.php
    ExecutionContext.php
    ContextProvider.php             ← builds context from HTTP or CLI

  Ledger/
    AccessLog.php                   ← every request logged to access.jsonl
    ActionLog.php                   ← optional structured ledger per action

  Depot/
    DepotInterface.php              ← TTL-aware key/value cache (double opt-in)

  Vault/
    Acl/
      ActionAccessChecker.php       ← assertCanRun(ActionInterface $action)
    Clearance/
      ClearanceInterface.php
      ClearanceRegistry.php
      AbstractClearance.php
      Builtin/
        (built-in clearance levels)
    Entity/
      Operator.php
      McpToken.php
    Security/
      OperatorUserProvider.php
    Controller/
      AuthController.php
      VaultController.php
    Console/
      CreateOperatorCommand.php
      GenerateMcpTokenCommand.php
      ListOperatorsCommand.php
      SetClearanceCommand.php

config/
  services.yaml
  services_vault.yaml

examples/
  run.php                     ← php examples/run.php → prints ✅ pong
  render.php                  ← demonstrates all OutputRenderer formats

tests/
  Action/
    ActionInterfaceTest.php
    ActionResultTest.php
  Renderer/
    (snapshot tests per renderer)
  Console/
    ConsoleAdapterTest.php
    ConsoleAdapterFunctionalTest.php
    ActionCommandTest.php
    ActionListCommandTest.php
  Context/
    ExecutionContextTest.php
    ContextProviderTest.php
  Gateway/
    ActionProviderTest.php
    ActionRunProcessorTest.php
  Functional/
    TestKernel.php
    AutoDiscoveryTest.php
```

---

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

---

## Conventions

**Action naming:** `{plugin}:{resource}:{action}` — all lowercase, hyphens allowed.
Example: `shopware:order:get-margin`, `core:system:ping`

**ActionResult:** Always return `ActionResult::ok()` or `ActionResult::failure()`.
Never throw exceptions for business errors. `ActionResult::error()` is deprecated.

**New actions go in:** `src/Action/Builtin/` (framework builtins) or `examples/` (demos).
Connector actions live in `connector-*/src/Action/`.

**DI tag:** `mosyca.action` (do NOT use the old `mosyca.plugin` tag).

**PHPStan level 8:** No `mixed` leaking without explicit handling.
Use `@param array<string, mixed>` not bare `array`.

**API Platform resource discovery:** Applications consuming mosyca/core must
add the Gateway resource directory to `api_platform.yaml`:
```yaml
api_platform:
  resource_class_directories:
    - '%kernel.project_dir%/vendor/mosyca/core/src/Gateway/Resource'
```

---

## Completed Slices

```
V0.1  ActionInterface, ActionResult, ActionRegistry, examples, tooling
V0.2  MosycaCoreBundle, DI extension, ActionRegistrationPass, auto-discovery
V0.3  OutputRenderer pipeline (6 formats: json/yaml/raw/table/text/mcp)
V0.4  ConsoleAdapter, ActionCommand, ActionChainCommandLoader,
      ActionCommandLoaderPass, ActionListCommand, ActionShowCommand
V0.5  REST Gateway via API Platform 3.4: ActionResource, McpToolResource,
      ActionProvider (collection + item + JSON Schema), McpToolProvider,
      ActionRunProcessor (POST /run → JsonResponse)
V0.6  @mosyca/bridge (Node.js MCP server, stdio transport)
V0.7  Vault: Operator entity, GBAC clearances, ActionAccessChecker, REST auth
V0.8  Depot (TTL cache), ActionLog (structured ledger), ScaffoldActionInterface
V0.9  ACL architecture: ExecutionContextInterface, ContextProvider,
      ActionResult::failure(), new route /api/v1/{plugin_name}/{tenant}/{resource}/{action}
V0.10 Nomenclature correction: Plugin→Action rename (pure rename, no behaviour change)
V0.11 True-REST Gateway via ResourceMetadataFactory decorator (AbstractResource,
      ResourceRegistry, ResourceRegistrationPass, SystemResource, MosycaResource,
      ResourceStateProvider/Processor stub, services.yaml wiring)
V0.12 Execution Adapters (ResourceStateProvider + ResourceStateProcessor fully
      implemented, ActionRegistry::getByClass())
V0.13 MCP Bridge PHP-native: ConstraintSchemaTranslator, McpDiscoveryService,
      McpExecutionService — direct ResourceRegistry + ActionRegistry access,
      no REST/API Platform (ADR 3.1), flat tool names (ADR 3.2), tenant injection (ADR 3.4)
V0.13b MCP HTTP Endpoint: McpRpcController — JSON-RPC 2.0 at POST /api/v1/mcp/rpc,
      wraps Discovery + Execution services, no AbstractController, no API Platform
V0.13d Protocol Hardening: notification detection (HTTP 204), JSON-RPC schema
      validation trait (justinrainbow/json-schema), MCP guardrails in CLAUDE.md
```

---

## MCP & JSON-RPC 2.0 Strictness Guardrails

### Rule 1 — Notification Protocol (JSON-RPC 2.0 §5)

A **Notification** is a request where the `id` member is **absent** (not just null).
The server **MUST NOT** send any response to a Notification.

For HTTP transport: return `new Response('', Response::HTTP_NO_CONTENT)`.

Detection: `!\array_key_exists('id', $body)` — **NOT** `$body['id'] === null`.
A request with `"id": null` has the key present and IS a normal request.

```php
// ✅ Correct
if (!\array_key_exists('id', $body)) {
    return new Response('', Response::HTTP_NO_CONTENT);
}

// ❌ Wrong — treats explicit null id as a Notification
if ($body['id'] === null) { ... }
```

### Rule 2 — Object vs Array Serialization

`json_encode([])` → `[]` (JSON array — **WRONG** for capability descriptor maps)
`json_encode(new \stdClass())` → `{}` (JSON object — **CORRECT**)

Always use `new \stdClass()` for empty descriptor objects in MCP responses:

```php
// ✅ Correct
'capabilities' => ['tools' => new \stdClass()]

// ❌ Wrong — encodes as []
'capabilities' => ['tools' => []]
```

### Rule 3 — Error Envelope Format

All JSON-RPC error responses must include exactly:
- `jsonrpc: "2.0"`
- `id`: the request id (or `null` for parse errors before id extraction)
- `error.code`: integer from the reserved range (`-32700`, `-32601`, `-32602`, `-32603`)
- `error.message`: human-readable string

Never put error details in `result`. Never use HTTP 4xx/5xx for RPC errors.

### Rule 4 — Test Assertions

Use `JsonRpcSchemaValidatorTrait::assertValidJsonRpcResponse()` in every
controller test that decodes a response. The `call()` and `callRaw()` helpers in
`McpRpcControllerTest` invoke it automatically on every non-null response.

To verify exact wire format (e.g. `"tools":{}` not `"tools":[]`), assert on the
raw JSON string — decoded PHP values lose the distinction:

```php
self::assertStringContainsString('"tools":{}', str_replace(' ', '', (string) $responseJson));
```

---

## Commit Convention

Conventional Commits: `feat(v0.x): ...`, `fix: ...`, `test: ...`, `docs: ...`, `chore: ...`
Commit and push after every completed slice (see workspace CLAUDE.md).

---

## QA & Testing Guardrails

### Rule 1 — Design for Testability
All services and controllers MUST use Dependency Injection. Do not instantiate external dependencies or strictly coupled classes inside a method using the `new` keyword if it prevents mocking.

### Rule 2 — Deterministic Isolation
Unit tests MUST NOT rely on external state (live databases, external APIs, active file systems). Use appropriate mocks, stubs, or in-memory SQLite databases for isolation.

### Rule 3 — Schema over Manual Assertion
Do not manually assert complex data structures if a formal schema exists. Validate inputs and outputs against established schemas (e.g., JSON Schema for RPC).

---

## Security & Authentication Guardrails

### Rule 1 — Zero-Trust LLM Input
All input received from the MCP Client (and thus from an LLM) MUST be treated as strictly untrusted and potentially malicious.
- All tool inputs must be strictly type-checked and sanitized before execution.
- NEVER pass raw input directly to `shell_exec`, `system`, or filesystem functions without rigorous validation against a strict allowlist.

### Rule 2 — Secret Protection
Tokens (like JWTs), API keys, and passwords MUST NEVER be logged to `stdout`, `stderr`, or written to unencrypted local log files. Exceptions thrown during authentication processes must be caught and scrubbed of sensitive payloads before being returned as a JSON-RPC error.

---

# Mandatory Expert Review Protocol

Before proposing a solution or modifying any code, you MUST conduct a simulated internal discussion. You act as the Lead Architect, but you must explicitly generate a brief review from two internal personas: the **QA Engineering Expert** and the **Security Professional**.

### Step 1 — QA Review (QA Engineering Expert)
Evaluate the proposed changes for testability, edge cases, and deterministic isolation. Explicitly state:
- Which new tests must be written.
- Which existing tests must be updated.
- Any edge cases (empty input, boundary values, concurrent access) that require coverage.

### Step 2 — Security Review (Security Professional)
Evaluate the proposed changes for input validation, sanitization, least privilege, and secret protection. Explicitly state:
- Potential attack vectors introduced or affected by the change.
- Whether any new input path requires an allowlist or schema guard.
- Whether any secret, token, or credential could be exposed by the change.

### Step 3 — Architect Execution
Only after the QA and Security requirements from Steps 1 and 2 are explicitly stated may you proceed with implementing the code and the corresponding tests.
