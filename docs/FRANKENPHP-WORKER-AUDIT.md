# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/instance-settings-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.0` (unreleased, branch `feat/1.0.0`) |
| Audit date | 2026-10-31 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`, 0 KO / 0 WARN); unit test of the request-bound memo; demo run in worker mode |
| **Verdict** | ✅ **Viable** — one memo bound to the main request (reloaded on every new request even without a reset), an immutable section map and the route loader's load-once flag; no request or user data survives a request |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once per worker and serves many requests with the same container. Two
scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs.**
- **B — no reset at all:** any state kept in a service survives into the next request.

A bundle safe under **B** is safe under **A**, classic mode and PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `InstanceSettings` rows memo, bound to the main request (`WeakReference`), cleared by `kernel.reset` and after saves; `SettingsSectionRegistry` section map (immutable definitions); route loader load-once flag (cache warm-up) |
| Static properties / `static` locals | ✅ | None |
| `ResetInterface` / `kernel.reset` | ✅ | `InstanceSettings::reset()` (tag `kernel.reset`) — also correct without it (scenario B) |
| Request / user captured in services | ✅ | `AccessGuard` reads `RequestStack::getMainRequest()` and the token per call and trusts the token only with a firewall context; the memo keeps only a weak reference to the request |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Doctrine | ✅ | Entity manager resolved per call from the registry (closed one reset), `HINT_REFRESH` reads (no stale identity-map values from an earlier request), DoctrineEncrypt decrypts on load |
| Resources held open | ✅ | None (upload / CLI files closed in `finally`) |
| Memory growth across requests | ✅ | The memo is replaced, not appended, on each request |
| Output, headers, `exit` | ✅ | Responses / console output only |
| PHPStan FrankenPHP rulesets / Igor | ✅ | 0 errors / 0 KO (annotated: entity field writes, Doctrine unit-of-work scheduling, the synchronous transaction closure, compile-time DI calls) |

## Services reviewed

| Service | Mutable state | Scenario A | Scenario B |
|---------|---------------|------------|------------|
| `InstanceSettings` | rows memo + weak reference to its main request; reset by `kernel.reset` and saves | ✅ | ✅ (new main request ⇒ reload) |
| `SettingsSectionRegistry` | section map built once from stateless tagged services (`@igor-ignore` with justification) | ✅ | ✅ |
| `InstanceSettingsRouteLoader` | `loaded` flag (cache warm-up only) | ✅ | ✅ |
| `DoctrineSettingValueRepository`, `ValueNormalizer`, `ConfigPortability`, `JsonUploadReader`, `DoctrineEncryptSecretSealer`, `TablePrefixListener` | none (`readonly`) | ✅ | ✅ |
| `SettingsController`, `SettingsPageFactory`, `SettingsPages`, form types | none (forms built per request) | ✅ | ✅ |
| `AccessGuard`, `InstanceSettingsAccessListener`, access checkers | none (`readonly`) | ✅ | ✅ |
| `InstanceSettingsTwigExtension`, CLI commands | none | ✅ | ✅ |

## Findings

No worker-mode findings under scenario **B**. A change saved in one worker is visible to every worker on its next
request because no value is cached across requests (deliberately no shared cache: it would hold decrypted secrets and
need cross-worker invalidation).

## Usage recommendations in worker mode

- Read settings where you use them (`$settings->getString(...)` in the method), not in a service constructor: a
  constructor runs once per worker and would keep the first value.
- Services that build expensive objects from settings (mailer transports, API clients) should rebuild them on
  `SettingsChangedEvent` or compare a cheap fingerprint per request.
- Messenger workers: `kernel.reset` runs between messages, so long-running consumers see changes from the next message.

## Re-audit triggers

Re-run this audit when a change adds a property to a service, a cache, an event listener that keeps data, a
persistent connection, or any read of `$_SERVER` / `$_ENV` / static state at runtime.
