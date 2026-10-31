# Instance Settings Bundle demos

## Symfony 8 (FrankenPHP + MySQL)

```bash
cd demo/symfony8
cp .env.example .env   # if needed
make up      # prints "Demo started at: http://localhost:8079"
make setup   # encryption key, schema, demo settings
```

- App: http://localhost:8079 (reads the settings with `instance_setting()` / `InstanceSettingsInterface`)
- Settings UI: http://localhost:8079/admin/settings (sections Site, Mailer, SMS; import / export)
- Default `FRANKENPHP_MODE=worker` (change in `.env` and recreate)
- `make test-e2e` / `make demo-screenshots` (Playwright, REQ-DEMO-013)

The demo opens the settings UI without login (`security.allow_unauthenticated: true`, accepted only because the demo
runs with `kernel.debug`). Never copy that to production.

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
