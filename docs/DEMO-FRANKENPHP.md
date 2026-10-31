# Demo with FrankenPHP

This bundle includes a runnable demo with FrankenPHP in:

- `demo/symfony8` — Symfony **8.1** on FrankenPHP **PHP 8.5** (`dunglas/frankenphp:1-php8.5-alpine`)

**PHP version policy (Symfony 8 demos):** use the newest PHP minor published in official FrankenPHP images when the demo’s `require.php` and Symfony 8 allow it (today: **8.5**). Bump the Dockerfile tag when FrankenPHP adds a newer PHP and constraints still match.

The demo uses:

- Caddy on HTTP `:80` inside the container
- **`Caddyfile`**: **worker** mode (`php_server { worker ... }`) for throughput
- **`Caddyfile.dev`**: classic `php_server` (**no worker**) — hot-reload friendly

Runtime selection is via **`FRANKENPHP_MODE`** (`classic` | `worker`), not `APP_ENV`. Compose still sets **`APP_ENV=dev`** / **`APP_DEBUG=1`** and mounts **`docker/php-dev.ini`**.

## Table of contents

- [Quick start](#quick-start)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Worker mode and kernel reset](#worker-mode-and-kernel-reset)
- [Demo services and data](#demo-services-and-data)
- [Timeout hierarchy](#timeout-hierarchy)
- [Development stack in demos](#development-stack-in-demos)
- [Troubleshooting](#troubleshooting)
- [Demo smoke (REQ-TEST-011)](#demo-smoke-req-test-011)
- [Playwright e2e and screenshots (REQ-DEMO-013)](#playwright-e2e-and-screenshots-req-demo-013)

## Quick start

From the bundle root:

```bash
make -C demo up-symfony8
```

Then open:

- Symfony 8.1: `http://localhost:8079`

## Switching classic vs worker (`FRANKENPHP_MODE`)

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Use the worker Caddyfile |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`) |

Set in `.env` / `.env.example`. Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Worker mode and kernel reset

This package is audited as safe when FrankenPHP keeps the Symfony kernel warm **without** resetting it between requests: the only per-request state is the settings memo, bound to the main request object, so a new request always reloads the rows. Full checklist: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). Try it: change "Rows per page" in `/admin/settings/sections/site`, reload `/` — the new value is there without restarting the worker.

## Demo services and data

| Service | Image | Purpose |
| --- | --- | --- |
| `php` | `dunglas/frankenphp:1-php8.5-alpine` (+ `pdo_mysql`, `sodium`) | Symfony 8.1 app on port `${PORT:-8079}`; the entrypoint creates the database, the Halite key (`var/secrets/.Halite.default.key`, mode 0600, never committed) and the schema (`doctrine:schema:update` — demo only, applications use migrations) |
| `db` | `mysql:8.0` (no host port, REQ-DEMO-006) | `demo_instance_setting_value` (`doctrine.table_prefix: demo_`) |

`make setup` (or `make seed`) runs `app:demo:seed`: sections **Site** (`App\Settings\SiteSettingsSection`), **Mailer** (encrypted DSN, an extra action) and **SMS** (encrypted auth token) get realistic values; secrets are obvious placeholders (`REDACTED-DEMO`). The home page (`/`) reads the values with `InstanceSettingsInterface` and `instance_setting()`. The settings UI is open without login (`security.allow_unauthenticated: true`, accepted only because the demo runs with `kernel.debug`).

## Timeout hierarchy

REQ-RUNTIME-001 does not apply to the bundle (no outbound HTTP or processes); the demo keeps the usual FrankenPHP
limits.

| Layer | Demo value |
| --- | --- |
| PHP `max_execution_time` / `max_input_time` | Caddyfile `php_ini` |
| Caddy `servers.timeouts.write` | Caddyfile |
| FrankenPHP `max_wait_time` | Caddyfile (wait for a free PHP thread) |

## Development stack in demos

The demo includes:

- **Symfony Debug** (`symfony/debug-bundle`)
- **Symfony Web Profiler** (`symfony/web-profiler-bundle`)
- **Doctrine ORM** (MySQL), **DoctrineEncryptBundle** (Halite), **UiKit** and **FormKit**
- **Nowo Twig Inspector** (`nowo-tech/twig-inspector-bundle`) and **Nowo Hot Reload** (`nowo-tech/hot-reload-bundle`) — required together on FrankenPHP demos (dev/test only; Caddyfile Mercure + `hot_reload`, plus `worker { watch }` in worker mode). Do not enable Hot Reload in production.
- **`APP_DEBUG=1`** in `.env.example`

Example `config/bundles.php`:

```php
<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class     => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class       => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class               => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class          => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class      => ['all' => true],
    Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle::class => ['all' => true],
    Nowo\UiKitBundle\NowoUiKitBundle::class                    => ['all' => true],
    Nowo\FormKitBundle\NowoFormKitBundle::class                => ['all' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class             => ['dev' => true, 'test' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class   => ['dev' => true, 'test' => true],
    Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle::class   => ['all' => true],
];
```

## Troubleshooting

- If app does not respond, run `make -C demo/symfony8 logs`.
- If routes/config changed, run `make -C demo/symfony8 cache-clear`.
- If dependencies are outdated, run `make -C demo/symfony8 update-bundle`.
- `Table '…demo_instance_setting_value' doesn't exist` right after `make up`: MySQL was still initialising; run `make -C demo/symfony8 setup`.
- Secrets fail to decrypt after deleting `var/`: the Halite key was regenerated; reseed (`make seed`) or restore the key.

## Demo smoke (REQ-TEST-011)

Boot the FrankenPHP demo, expect **HTTP 200** on the published port, then tear down:

```bash
make demo-smoke
# or: make -C demo demo-smoke
```

Smoke URL: `http://127.0.0.1:8079` (Symfony 8.1 demo). This target is also part of `make -C demo release-check` / root `make release-check`.

## Playwright e2e and screenshots (REQ-DEMO-013)

With the demo running:

```bash
make test-e2e            # or: make -C demo/symfony8 test-e2e
make demo-screenshots    # refreshes docs/images/demo/*.webp
```

Both run in the official Playwright image (`mcr.microsoft.com/playwright`, host network, one worker) and reseed the
settings first. The specs live in `demo/symfony8/e2e/tests/`: `demo.spec.ts` (home page values, tabs, save applied on
the next request, secret never rendered back, field validation error, export download without secrets in clear, forged
CSRF refused) and `screenshots.spec.ts` (two WebP captures with the demo masthead, the settings UI and the web debug
toolbar; PNGs are converted with `sharp` and deleted).
