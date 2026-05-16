# Plugin DX Checklist

Use this checklist before releasing a plugin or submitting a PR. Every item is non-negotiable.

---

## Package Structure

- [ ] `composer.json` declares `"mosyca/core": "@dev"` (or `"^0.x"` for released versions)
- [ ] `autoload.psr-4` maps `Mosyca\{Name}\` to `src/`
- [ ] `autoload-dev.psr-4` maps `Mosyca\{Name}\Tests\` to `tests/`
- [ ] Bundle class present in `src/{Name}Plugin.php` or `src/{Name}Bundle.php`
- [ ] Extension class in `src/DependencyInjection/{Name}Extension.php`
- [ ] `src/config/services.yaml` present and loaded by the Extension

---

## Services Configuration

- [ ] `_defaults: autowire: true, autoconfigure: true` set in `services.yaml`
- [ ] Wildcard import covers all `src/` classes (with Bundle and Extension excluded)
- [ ] Named `VaultAwareHttpClient $xxxClient` binding defined for each integration type
- [ ] `$allowedUris` set to the **minimum required** base URIs (never `['https://']`)
- [ ] `$refreshers: !tagged_iterator mosyca.vault.refresher` present (even if the plugin has no refresher)

---

## Resource

- [ ] One `AbstractResource` subclass per domain entity
- [ ] `getPluginNamespace()` returns the lowercase plugin slug (matches URL segment)
- [ ] `getName()` returns the singular lowercase resource slug
- [ ] `getOperations()` maps every operation to a valid `class-string` action FQCN

---

## Actions

- [ ] Every action implements `ActionInterface`
- [ ] Every action uses `ActionTrait`
- [ ] Every action carries `#[AsAction]` attribute
- [ ] `getName()` follows the `{namespace}:{resource}:{verb}` convention
- [ ] `getUsage()` contains meaningful Markdown documentation (Claude reads this)
- [ ] `getParameters()` describes all inputs accurately (type, required, example)
- [ ] Actions that call authenticated APIs inject `VaultManager` for the pre-flight check
- [ ] `SecretNotFoundException` is always caught and converted to `ActionResult::authRequired()`
- [ ] `ActionResult` data never contains raw credential values (Vault Rule V2)

---

## Vault Integration

- [ ] Integration type string is consistent across: `storeSecret`, `retrieveSecret`, `allowedUris`, `authRequired()`, and the `extra.vault.integration` option in HTTP calls
- [ ] `ActionResult::authRequired('{integration_type}')` is returned (not `failure()`) when credentials are missing
- [ ] Credentials are provisioned via `mosyca:vault:set {integration} {tenant}` and verified to work

---

## Security

- [ ] `HttpBinClientTest` (or equivalent) includes `testOutOfScopeUriIsRejected()` — the mandatory negative URI allowlist test
- [ ] Action tests include `testResultDataNeverContainsToken()` — asserts credential values are stripped
- [ ] No token values in exception messages, log entries, or ActionResult summaries

---

## Tests

- [ ] All test files in `tests/` with namespace `Mosyca\{Name}\Tests\`
- [ ] `phpunit.xml.dist` present, points `bootstrap` at `vendor/autoload.php`
- [ ] Action unit tests mock the client class (class must not be `final`)
- [ ] Client unit tests use `MockHttpClient` (no real HTTP)
- [ ] Integration test uses `SodiumSecretCipher` with `bin2hex(random_bytes(32))` test key
- [ ] No test uses `MOSYCA_VAULT_MASTER_KEY` from the environment (Vault Rule V3)

---

## Quality Gate

All three commands must exit 0 before merging or releasing:

```bash
# Run from the plugin's own directory
vendor/bin/phpunit                              # all green
vendor/bin/phpstan analyse --no-progress        # level 8, no errors
vendor/bin/php-cs-fixer check --no-interaction  # no violations
```

Standard `phpstan.neon`:
```yaml
parameters:
    level: 8
    paths:
        - src/
        - tests/
```

Standard `.php-cs-fixer.dist.php`:
```php
$finder = PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests']);
return (new PhpCsFixer\Config())
    ->setRules(['@Symfony' => true, '@Symfony:risky' => true, 'declare_strict_types' => true])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
```

---

## Releasing

- [ ] All quality gate checks pass
- [ ] `CHANGELOG.md` entry written
- [ ] Git tag pushed (`git tag v0.1.0 && git push --tags`)
- [ ] Packagist webhook triggered (or manual package update)
