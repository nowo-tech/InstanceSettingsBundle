# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.8.0](#to-180)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
  - [To 1.2.0](#to-120-1)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
- [To 1.0.0](#to-100)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app settings entity](#migrating-from-an-in-app-settings-entity)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

Target **1.9.0**, from **1.8.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

1. **Nothing changes by default.** `web_ui.hidden_secrets` is `drop` and `web_ui.hidden_secrets_notice` is `false`:
   a secret typed into a hidden provider row is still ignored and no notice is shown.
2. **Opt in** to save such secrets with `web_ui.hidden_secrets: keep_typed`, or per section by implementing
   `SettingsSectionHiddenSecretsInterface`. Review the sections first: with `keep_typed`, a credential typed for an
   inactive provider is written (and announced by `SettingsChangedEvent`) even though that provider stays unselected.
3. **Notice:** `web_ui.hidden_secrets_notice: true` tells users, while hidden secrets are dropped, that they are not
   saved. Override `notice.hidden_secrets_dropped` (domain `NowoInstanceSettingsBundle`) for your own wording.
4. **Code that builds `SettingsSectionEmbedder` by hand** keeps working (two new optional constructor arguments at
   the end, defaults `HiddenSecrets::Drop` and `false`). The fallback embedder of a `SettingsController` built with
   the 1.5 signature always drops.
5. New translation key `notice.hidden_secrets_dropped` in all 7 locales: hosts that check key parity of their
   overrides may need it.

## To 1.8.0

From **1.7.1**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target **1.8.0**, from **1.7.x**:

```bash
composer update nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle
```

1. **UiKit ^2.43.** The bundle now requires `nowo-tech/ui-kit-bundle` ^2.43 (its image crop). Run `assets:install`
   (or your asset build) so `nowo-ui-image-crop.js` is published.
2. **Nothing changes by default.** `files.crop` is `false`: the admin markup is byte-identical to 1.7.1 and no script
   is added.
3. **Opt in** with `files.crop: true` (every image field, brand ones included) or per field with
   `SettingField::withCrop()`; `SettingField::withoutCrop()` keeps a field out. `withCrop()` options a field would refuse
   throw when the section definitions load; under `files.crop` a field the derived crop cannot fit is shown without
   it ([USAGE](USAGE.md#crop-step-18)).
4. **CSP.** With a strict Content-Security-Policy on the admin pages, add `img-src blob:`.
5. **Host templates.** A page template set with `SectionEmbedOptions::withTemplate()` that replaces the
   `nowo_ui_settings_section` block must load the script itself when `image_crop` is true:
   `{{ include(nowo_ui_partial('scripts'), {script_names: ['image-crop']}) }}`.
6. **Building `SettingImageType` by hand** (tests, a form factory without the container): the type now takes `(array|false $cropDefault = false,
   ?TranslatorInterface $translator = null)`; the defaults keep 1.7 behaviour.

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target **1.7.1**, from **1.7.0**: `composer update nowo-tech/instance-settings-bundle`. Nothing to change in the
configuration; with `files.store: filesystem`:

1. **Env paths.** `files.directory` and `seed.base_dir` may now be `%env(...)%`. The checks that the container applies
   to a literal value (local path; not under the public web root, any spelling) run when the store / seeder is built
   for an env value, so a wrong value fails at kernel warm-up or on the first use instead of `cache:clear`. The
   variable must exist wherever the kernel boots or `cache:warmup` runs (including an image build), or have an
   `env(NAME)` default under `parameters`. Both keys must be **absolute** paths (literal or env): a relative value is
   now refused, as it would resolve against each process's current directory (use `%kernel.project_dir%/…`).
2. **Git.** Add the variants to the host `.gitignore` and commit only the originals:

   ```gitignore
   /config/instance-files/**/.variants/
   ```

   (use your `files.directory`). The store also writes a self-ignoring `.variants/.gitignore` (`*`) from 1.7.1 on.
   If you committed `.variants/` with 1.7.0, `git rm -r --cached config/instance-files/*/.variants`.
3. **Committed images are adopted losslessly.** The next seed on a fresh database strips metadata from a committed
   file once (one git diff for a file that carried EXIF / XMP / ICC / text chunks or appended bytes) and then leaves it
   untouched. A JPEG that 1.7.0 already re-encoded is clean (GD's own `CREATOR` comment is kept) and stays as it is.
   Commit sRGB images: ICC profiles are dropped, as before.

## To 1.7.0

From **1.6.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target **1.7.0**, from **1.6.0**: nothing to do unless you opt in. Without `seed` and `files.store` the
services, schema and behaviour are unchanged (the database file store is byte for byte the same).

1. **Seed file (IS-3).** Commit a seed file and its images, e.g. `config/instance-seed.yaml` (format and example in
   [CONFIGURATION](CONFIGURATION.md#seed-file-format)), then point the bundle at it:

   ```yaml
   nowo_instance_settings:
       seed:
           file: '%kernel.project_dir%/config/instance-seed.yaml'
           # base_dir: '%kernel.project_dir%'   # every seed path must resolve inside it
   ```

   Run `php bin/console nowo:instance-settings:seed --dry-run`, then without `--dry-run`, in your bootstrap of fresh
   instances (dev, e2e, install) — it is fill-only and idempotent. YAML needs `symfony/yaml` (`.json` needs nothing).
   Secrets are refused in a seed file: keep them in the admin UI or seed them with `seed-from-fallbacks`.
2. **Filesystem image store (optional).** To keep the brand images as committed files instead of database blobs:

   ```yaml
   nowo_instance_settings:
       files:
           store: filesystem
           directory: '%kernel.project_dir%/config/instance-files'
   ```

   Images already stored in `instance_setting_file` are **not** moved. Before switching, export with files
   (`nowo:instance-settings:export --include-files` or the admin page); after switching, import that file, re-upload the
   images, or clear the fields and run the seed. An image of the same hash as the stored reference is written again
   when the new store does not hold it (audit JG-M2). The old blobs stay in `instance_setting_file` (`purge-files` of the
   filesystem store does not remove them). In production mount `files.directory` as a persistent, writable volume
   outside the public web root (the container refuses a directory under `public/` or `brand.public_dir`). Note the
   differences: no orphan retention (a replaced or cleared image is deleted at once), the file on disk is
   authoritative, and a file without a setting row is not served.
3. **Committed images are re-encoded when seeded** with the filesystem store (as uploads are): the committed file at
   the field's stable path may change once in git (EXIF / appended bytes dropped).
4. **`doctrine/dbal` ^4.0** is now a direct requirement. Hosts still on DBAL 3 stay on 1.6 until they upgrade
   DBAL. DoctrineBundle 3 needs DBAL 4, but ORM 3 also runs on DBAL 3.8+, so an ORM 3 host on DBAL 3 must upgrade DBAL
   first (audit JG-I18).
5. **`SettingFileController` built by hand** (not through the container): pass the new fourth argument
   (`InstanceSettingsInterface`); without it every file of the filesystem store answers 404. The database store is
   unaffected.
6. **`ImageUpload::fromPath()`** now refuses `scheme://` and `data:` paths (`upload_failed`). Only code that passed a
   stream wrapper path on purpose is affected.

## To 1.6.0

From **1.5.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target 1.6.0, from **1.5.0**. Nothing to do: both additions are opt-in and the bundle pages render as in 1.5.

- **Embedding sections in your own page (IS-1).** Replace a forward to `SettingsController::section` (which showed
  every tab and redirected to the bundle route) with `SettingsSectionEmbedder::handle()` in your controller:

  ```php
  public function __invoke(Request $request, SettingsSectionEmbedder $embedder, string $section): Response
  {
      return $embedder->handle($request, $section, SectionEmbedOptions::route('app_appearance', ['brand', 'appearance'])
          ->withLayout('admin/layout.html.twig'));
  }
  ```

  Keep your own firewall / `access_control` on the host route; the embedder adds the bundle checks on top (UI gate +
  section area + CSRF). Your route must accept GET and POST. Pass route names only (a path or URL is refused), call
  the embedder from your route's controller (not a Twig `render(controller())` fragment), and keep `routeParameters`
  to values from your code. A role that should open the embed needs the UI role (`access_roles`) too, not only
  `area_roles.<section>`.
- **Theme cards (IS-2).** Set `nowo_instance_settings.brand.preset_picker: cards` to show the preset fields as cards.
  The stored values and the export do not change. A host that overrides the section template with its own
  `form_theme ... only` must add `@NowoInstanceSettingsBundle/Form/theme_cards.html.twig` itself.
- Code that builds `SettingsController` by hand keeps working: without the new last argument (`SettingsSectionEmbedder`)
  `section()` builds the same embedder from the controller's arguments and its `AbstractController` service locator
  (form factory, Twig, router, request stack). The container wiring passes the service.

## To 1.5.0

From **1.4.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target **1.5.0**, from **1.4.0**:

```bash
composer update nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle
```

1. **UiKit ^2.40.** The constraint moves from `^2.23` to `^2.40` (the brand bridge uses UiKit's logo slots and its
   `provider_is_default` parameter). Nothing else changes for hosts that do not enable the brand bridge: same
   services, routes, forms, schema, and the admin pages render byte for byte as in 1.4.
2. **Opt in to the brand bridge** (spec 220 S3) only if you want the brand and appearance stored as settings:

   ```bash
   composer require nowo-tech/brand-contracts:^1.0
   ```

   ```yaml
   # config/packages/nowo_instance_settings.yaml
   nowo_instance_settings:
       brand:
           enabled: true
           defaults:
               name: Acme                                           # required
               eyebrow: { key: brand.eyebrow_default, domain: messages }   # or a literal string
               logo_light: brand/logo-light.svg                     # committed files under public/ (SVG / ICO allowed)
               mark: brand/mark.svg
               favicon: brand/favicon.svg
           extra_images:
               mascot: { label: brand.mascot, default: brand/mascot.png }
   ```

   - `brand.enabled: true` implies `files.enabled: true`: if you did not have image fields yet, add the
     `instance_setting_file` migration of [To 1.4.0](#to-140), `ext-gd` with PNG, JPEG and WebP, and the
     `PUBLIC_ACCESS` `access_control` line for `files.path_prefix` (brand images are public).
   - Set `brand.enabled` in the host configuration (or a bundle that prepends before InstanceSettings). A value
     prepended after InstanceSettings now fails at compile time with a clear message (audit IO-I10).
   - Two sections appear in the admin UI, `brand` and `appearance` (area roles `security.area_roles.brand` /
     `.appearance`, default the UI roles). A host section already named `brand` or `appearance` conflicts: rename it
     before enabling the bridge.
   - **UiKit's appearance provider switches to the bridge** when `nowo_ui_kit.appearance.enabled: true`, you set no
     `nowo_ui_kit.appearance.provider` (and no services alias of `nowo_ui_kit.appearance.provider`) and you kept
     `nowo_ui_kit.appearance.brand: auto` (the default). With `brand: off` UiKit keeps its config look while Pwa, mail,
     legal and SEO still read the stored brand (audit IU-L1). A host **decorator** of
     `nowo_ui_kit.appearance.config_provider` no longer applies once the bridge takes over (audit IU-I1): set
     `nowo_ui_kit.appearance.provider` to your own provider, or `brand: off`, to keep it. With an empty store
     the output equals UiKit's config provider when `brand.defaults.name` / `eyebrow` match
     `nowo_ui_kit.appearance.defaults.brand_name` / `brand_eyebrow`. A host provider always wins.
   - `BrandProviderInterface` and `BrandAssetReaderInterface` are aliased to the bridge. A host service or alias of
     `BrandProviderInterface` next to `brand.enabled: true` fails the build (two brands, audit IU-I2): keep one source.
   - Default asset URLs are `/<path>?v=<12 hex of the SHA-256>`, so a changed committed file gets a new URL (audit
     IU-I3). They are root-relative and ignore a request base path: an app served under a sub-path needs the files
     reachable at `/<path>` or uploads instead. Pwa, MailKit, LegalKit and SeoKit read them in their brand-aware releases.
   - Migrating from a host appearance entity: implement `Brand\BrandFallbackProviderInterface` (one read of the old
     row, only values that differ from the defaults), set `brand.fallback_provider` to its service id, run
     `bin/console nowo:instance-settings:seed-from-fallbacks --section=brand --section=appearance --dry-run`, then
     without `--dry-run`. Images are not seeded: keep them as config default assets or upload them.
3. **Custom `SettingsSectionInterface` implementations** are unaffected. `SettingField` gains an optional trailing
   constructor argument `$group`; code that builds fields with named arguments or the named constructors needs no
   change.

## To 1.4.0

From **1.3.1**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Image setting fields (spec 220 S2):

1. **No schema change unless you enable image fields.** Image fields are opt-in (`files.enabled`, default `false`):
   by default the bundle maps no new entity, `doctrine:migrations:diff` and `doctrine:schema:validate` see the 1.3
   schema, and no file route, file store or purge command is registered.
2. **Only hosts that set `nowo_instance_settings.files.enabled: true`** (to declare image fields) get the new table
   `instance_setting_file`. Generate a migration (`doctrine:migrations:diff`) or apply the DDL below (shown without
   `doctrine.table_prefix`; with a prefix, prefix the table and both index names):

   ```yaml
   nowo_instance_settings:
       files:
           enabled: true
   ```

   MySQL / MariaDB:

   ```sql
   CREATE TABLE instance_setting_file (id INT AUTO_INCREMENT NOT NULL, orphaned_at DATETIME DEFAULT NULL, section VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL, sha256 CHAR(64) NOT NULL, variant INT NOT NULL, mime_type VARCHAR(32) NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, content LONGBLOB NOT NULL, created_at DATETIME NOT NULL, INDEX idx_instance_setting_file_orphaned (orphaned_at), UNIQUE INDEX uniq_instance_setting_file_ref (section, name, sha256, variant), PRIMARY KEY (id));
   ```

   PostgreSQL:

   ```sql
   CREATE TABLE instance_setting_file (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, orphaned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, section VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL, sha256 CHAR(64) NOT NULL, variant INT NOT NULL, mime_type VARCHAR(32) NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, content BYTEA NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id));
   CREATE INDEX idx_instance_setting_file_orphaned ON instance_setting_file (orphaned_at);
   CREATE UNIQUE INDEX uniq_instance_setting_file_ref ON instance_setting_file (section, name, sha256, variant);
   ```

3. **Nothing else changes for hosts without image fields**: no new table, no new route, the same forms, exports stay
   envelope version 1.
4. **Hosts that add an image field** need `files.enabled: true` (a section declaring an image field without it fails
   when the definitions load), `ext-gd` with PNG, JPEG and WebP (the container refuses to compile without it once the
   flag is on), a `PUBLIC_ACCESS` `access_control` line for `files.path_prefix` (default `/_settings-file`) when their
   firewall covers it (and a firewall over that prefix for private image fields), and a scheduled
   `nowo:instance-settings:purge-files`. Behind a CDN, ignore the query string in the cache key for that prefix and
   purge the CDN when you withdraw an image. Raise `portability.max_upload_bytes` (up to 16 MiB) if exports include
   the images. A 1.3 instance cannot import a version 2 export.
5. **Custom `InstanceSettingsInterface` implementations** (rare: decorators, test doubles) must add
   `getFile(string $key): ?StoredSettingFile`. Listeners constructing `SettingsChangedEvent` themselves are unaffected
   (`fileChanges` is optional).

## To 1.3.1

From **1.3.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

From **1.3.0**: no configuration or schema change. Saves that emptied a provider whose default shows a required field
(and left that field empty) are now refused, as they would leave the default provider without its required value.

## To 1.3.0

From **1.2.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

From **1.2.x**: no configuration or schema change, no BC break. Requires `nowo-tech/ui-kit-bundle` ^2.23
(`composer update nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle -W`).

- Opt-in provider-dependent fields: `->withVisibleWhen('provider', ['smtp'])` on a `SettingField`
  ([USAGE](USAGE.md#provider-dependent-fields-visible_when)).
- Hosts that render a section with such fields register the UiKit `visible-when` Stimulus peer
  (`vendor/nowo-tech/ui-kit-bundle/src/Resources/assets/stimulus-peers/visible_when_controller.ts`, identifier
  `visible-when`). Without it every row stays visible and saving still follows the server rules.
- A section definition with an invalid `visible_when` (unknown / secret / dependent controlling field, value that is
  not a choice) now fails with a `LogicException` when the sections load.

## To 1.2.0

From **1.1.1**:

```bash
composer update nowo-tech/instance-settings-bundle
```

### To 1.2.0

From **1.1.x**: no configuration or schema change, no BC break.

```bash
composer update nowo-tech/instance-settings-bundle
```

New, all optional:

- `SectionFallbackProviderInterface` + `FallbackSeeder` + `nowo:instance-settings:seed-from-fallbacks`: copy the
  `%env()%` / YAML fallbacks of the kits (SmsKit / MailKit 1.2+) into the store once. See
  [Seeding from fallbacks](USAGE.md#seeding-from-fallbacks-db-only-mode).
- `SettingsSectionNoticesInterface`: warnings on the section page. A host that overrides
  `web_ui.templates.section` gets a new optional `notices` variable (list of UiKit labels); render it or ignore it.
- `SettingsChangedEvent::ORIGIN_SEED` (`seed`): listeners that switch on the origin should accept it.

Moving a host to DB-only mode, **in this order**:

1. Upgrade the bundle and the kits while the `%env()%` fallbacks are still wired in their configuration.
2. Run `php bin/console nowo:instance-settings:seed-from-fallbacks --dry-run`, then without `--dry-run` (or call
   `FallbackSeeder::seed()` from an upgrade step) **once**.
3. Check the settings pages (no warnings), then remove the env vars / YAML values right away. Removing them first would
   seed nothing; keeping them and seeding again would restore a field an admin cleared in the meantime.

From a Doctrine migration, use a **non-transactional** migration (`isTransactional(): false` or
`doctrine_migrations.transactional: false`): on MySQL, DDL commits implicitly and a transactional migration leaves DBAL
with a phantom transaction (`SAVEPOINT DOCTRINE_n does not exist`). The seeder detects it and throws a clear
`LogicException`. See [Calling the seeder from a migration](USAGE.md#calling-the-seeder-from-a-migration).

## To 1.1.1

From **1.1.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

The bundle now requires `nowo-tech/ui-kit-bundle` ^2.21. No configuration or code change:

```bash
composer update nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle --with-dependencies
```

## To 1.1.0

From **1.0.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

## To 1.0.0

Initial release: there is nothing to upgrade from.

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
