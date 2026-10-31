# Instance Settings Bundle – Symfony 8 demo

Symfony 8.1 application on FrankenPHP (worker mode by default) and MySQL showing `nowo-tech/instance-settings-bundle`:

- three host sections (`App\Settings\SiteSettingsSection`, `MailerSettingsSection`, `SmsSettingsSection`) — the same
  extension point a mailer or SMS bundle uses;
- secrets (mailer DSN, SMS auth token) encrypted at rest with DoctrineEncryptBundle (Halite key in `var/secrets`);
- the UiKit admin pages under `/admin/settings` and the import / export page;
- the home page reading the values on every request.

## Setup

```bash
make up      # containers + composer install, prints "Demo started at: http://localhost:8079"
make setup   # key, schema (doctrine:schema:update — demo only, use migrations in an application), seed
```

## Commands

- `php bin/console app:demo:seed` – seed demo settings (idempotent)
- `php bin/console nowo:instance-settings:export --include-secrets -o var/export.json` – sealed export
- `php bin/console nowo:instance-settings:import var/export.json --dry-run` – validate an export
- `make test` – `lint:container` + `lint:twig`
- `make test-e2e` / `make demo-screenshots` – Playwright (REQ-DEMO-013)
