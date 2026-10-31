# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app settings entity](#migrating-from-an-in-app-settings-entity)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

Nothing yet beyond the initial 1.0.0 content below.

## To 1.0.0 (initial release)

### Requirements

- PHP `>=8.2 <8.6`, Symfony 7.4 / 8.0 / 8.1, Doctrine ORM 3.3+.
- `nowo-tech/doctrine-encrypt-bundle` ^2.10 registered **and configured** (default profile): the container refuses to
  compile without it.

### Enable and configure

1. `composer require nowo-tech/instance-settings-bundle` (Flex registers the bundle and copies the recipe).
2. Import the routes (opt-in):

   ```yaml
   # config/routes/nowo_instance_settings.yaml
   nowo_instance_settings:
       resource: .
       type: nowo_instance_settings
   ```

3. Protect `web_ui.path_prefix` (`/admin/settings`) with `security.access_control`.
4. Generate and run a migration for `instance_setting_value` (`doctrine:migrations:diff`).
5. Contribute sections ([USAGE.md](USAGE.md#contributing-a-settings-section)).

### Migrating from an in-app settings entity

Applications with a singleton "instance settings" entity (one wide row, one trait per area) move area by area:

1. Write one section per area (`mailer`, `sms`, `mercure`, …) with the same defaults; mark encrypted columns
   `SettingField::secret()` and security-sensitive toggles `->notPortable()`.
2. Copy the stored values once, in a migration or a one-off command, through the API so secrets are re-encrypted:

   ```php
   $settings->save('mailer', ['from' => $legacy->getMailerFrom(), 'dsn' => $legacy->getMailerDsn()]);
   ```

3. Replace `$repository->getOrCreate()->getX()` reads with `$settings->getString('area.x')` / `getSecret()`.
4. Replace the area controllers / forms / templates with the bundle UI; keep domain-specific actions (test e-mail…) as
   section actions.
5. Replace the export / import controller and envelope with `ConfigPortability` (+ `PortabilityProviderInterface`
   services for data stored elsewhere); set `portability.schema` to your previous schema name only if the payload shape
   is the same (it usually is not: re-export after migrating).
6. Drop the old columns / entity in a later migration.

### Template contract

Initial contract: pages `settings/section.html.twig` and `settings/portability.html.twig`, both extending
`@NowoUiKit/page/settings.html.twig`; view models from `SettingsPageFactory` (see [TEMPLATES.md](TEMPLATES.md)).
Renaming a page, a `web_ui.templates` id or a view-model field will be a breaking change.

### Breaking changes

None (first release).
