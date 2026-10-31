# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/instance-settings-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A** (or already covered by Symfony) so the decision stays auditable.

## Table of contents

- [Baseline (always)](#baseline-always)
- [Interface / contract PSRs](#interface--contract-psrs)
- [Summary](#summary)

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (Nowo REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 map for package sources and tests. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | `InstanceSettings` and `ConfigPortability` take an optional `Psr\Log\LoggerInterface` (section / field names only, never values). |
| PSR-6 / PSR-16 Cache | **N/A** | No package-owned cache layer: reads are memoized per request on purpose (a shared cache would hold secrets and need cross-worker invalidation). |
| PSR-7 / PSR-17 HTTP messages | **N/A** | Symfony HttpFoundation is the correct surface for an admin UI. |
| PSR-18 HTTP client | **N/A** | No outbound HTTP client. |
| PSR-11 Container | **N/A** | No container locator in the public API; constructor injection and tagged iterators only. |
| PSR-14 Event dispatcher | **Adopted** | `InstanceSettings` dispatches `SettingsChangedEvent` through `Psr\EventDispatcher\EventDispatcherInterface` (the Symfony dispatcher implements it); listeners reset their own caches or audit changes. |
| PSR-15 HTTP middleware | **N/A** | Symfony kernel listener for access; no middleware pipeline. |
| PSR-20 Clock | **Adopted** | `updated_at` and `exported_at` come from an injected `Psr\Clock\ClockInterface` (framework `clock`, else `NativeClock`); tests use `MockClock`. |

## Summary

- **Adopted beyond baseline:** PSR-3 (logger), PSR-14 (event dispatcher) and PSR-20 (clock), all type-hinted and wired.
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.
- **Re-evaluate** when the package gains logging, HTTP, cache, clock, or event SPIs.

---

_REQ-CS-007 evaluation date: 2026-10-31._
