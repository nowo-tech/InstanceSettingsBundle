# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/instance-settings-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.0` (unreleased, branch `feat/1.0.0`) |
| Audit date | 2026-10-31 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`, 0 KO / 0 WARN); unit test of the request-bound memo; demo run in worker mode |
| Re-audit (2026-11-11, 1.13.1) | REQ-RUNTIME-002 sweep (A2) and audit MB-K-D2 follow-up. Gap: outside HTTP `InstanceSettings::isMemoizedFor()` was true whenever there was no main request, so the rows / image-metadata memo built for the first message or command was reused until `kernel.reset`; the private `SettingsEntityManager` was dropped only by `kernel.reset`. That leaked stale values (e.g. a rotated SMS / mail provider token) wherever the services resetter does not run: `messenger:consume --no-reset`, long-running console commands, hosts that remove or bypass `services_resetter` or drive `http_kernel` directly (stock `Kernel::handle()` runs the resetter before every request, also with `FRANKENPHP_RESET_KERNEL=false`). Affected 1.0.0 – 1.13.0 (private manager: 1.11.0 – 1.13.0). Fixed: `EventListener\WorkerBoundaryResetListener` resets both at the main `kernel.request` / `kernel.terminate`, Messenger `WorkerMessageReceived` / `Handled` / `Failed` and `console.command` / `console.terminate` (references `IGNORE_ON_UNINITIALIZED_REFERENCE`, end-of-unit priority -1024 after the committed events are dispatched). Worker test: `tests/Integration/Functional/WorkerBoundaryResetTest.php` (one kernel, no resetter; N = 600 messages alternating `general.site_name` / `mailer.from_name`, each changed by raw SQL before the message, Handled / Failed alternating, private manager closed every 50 messages, memory margin 4 MiB; N = 200 console commands; a boundary never builds the services). Fails on 1.13.0. Igor 0 KO / 0 WARN; PHPStan clean |
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
| Mutable state in shared services | ✅ | `InstanceSettings` rows memo, bound to the main request (`WeakReference`), cleared at every request / Messenger / console boundary by `WorkerBoundaryResetListener` (1.13.1), by `kernel.reset` and after saves; `SettingsSectionRegistry` section map (immutable definitions); route loader load-once flag (cache warm-up) |
| Static properties / `static` locals | ✅ | None |
| `ResetInterface` / `kernel.reset` | ✅ | `InstanceSettings::reset()` (tag `kernel.reset`) — also correct without it (scenario B) |
| Request / user captured in services | ✅ | `AccessGuard` reads `RequestStack::getMainRequest()` and the token per call and trusts the token only with a firewall context; the memo keeps only a weak reference to the request |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Doctrine | ✅ | Since 1.11 a bundle-owned private entity manager (`SettingsEntityManager`) on the source manager's connection: cleared after every write unit, replaced when closed or when the connection changed, dropped at every request / Messenger / console boundary (1.13.1) and on `kernel.reset`; the application's managers are never flushed, cleared or reset. `HINT_REFRESH` reads (no stale identity-map values from an earlier request), DoctrineEncrypt decrypts on load. Covered by a kept-alive kernel test through `http_kernel` (no services resetter): a failed bundle write, then the next request saves again; memory flat over 300 requests |
| Resources held open | ✅ | None (upload / CLI files closed in `finally`) |
| Memory growth across requests | ✅ | The memo is replaced, not appended, on each request |
| Output, headers, `exit` | ✅ | Responses / console output only |
| PHPStan FrankenPHP rulesets / Igor | ✅ | 0 errors / 0 KO (annotated: entity field writes, Doctrine unit-of-work scheduling, the synchronous transaction closure, compile-time DI calls) |

## Services reviewed

| Service | Mutable state | Scenario A | Scenario B |
|---------|---------------|------------|------------|
| `InstanceSettings` | rows memo + weak reference to its main request + image metadata memo (1.4, cleared with the rows); reset by `WorkerBoundaryResetListener` at every request / message / command boundary (1.13.1), by `kernel.reset` and saves | ✅ | ✅ (new main request ⇒ reload) |
| `ImageProcessor`, `DoctrineSettingFileStore`, `SettingFileController`, `SettingFileUrls`, `PurgeFilesCommand` (1.4) | none (`readonly`; image bytes live only in the current call / response; the store detaches the rows it writes) | ✅ | ✅ |
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
- Messenger workers and long-running commands: the bundle's `WorkerBoundaryResetListener` clears the settings memo and
  its private entity manager at every message (`WorkerMessageReceived` / `Handled` / `Failed`) and command
  (`console.command` / `console.terminate`) boundary, so consumers see changes from the next message even with
  `--no-reset` (1.13.1; before, only `kernel.reset` did, which `--no-reset` skips).
- Host rule (REQ-RUNTIME-002 rule 7): a host that disables or bypasses the services resetter must also clear its own
  entity managers at every boundary; the bundle never touches them.

## Re-audit triggers

Re-run this audit when a change adds a property to a service, a cache, an event listener that keeps data, a
persistent connection, or any read of `$_SERVER` / `$_ENV` / static state at runtime.
