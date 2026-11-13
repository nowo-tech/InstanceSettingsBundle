# Configuration

## Table of contents

- [Full reference](#full-reference)
- [doctrine](#doctrine)
- [web_ui](#web_ui)
  - [Layout and CSS stack (UiKit)](#layout-and-css-stack-uikit)
- [security](#security)
  - [Revealing a stored secret (1.15)](#revealing-a-stored-secret-115)
- [portability](#portability)
- [files](#files)
  - [Filesystem store](#filesystem-store)
  - [Crop step](#crop-step)
- [seed](#seed)
  - [Seed file format](#seed-file-format)
  - [Host-side importer API](#host-side-importer-api)
- [brand](#brand)
  - [Error page illustrations (1.13)](#error-page-illustrations-113)
- [FormKit profile](#formkit-profile)
- [Encryption (DoctrineEncryptBundle)](#encryption-doctrineencryptbundle)
- [Profiles](#profiles)
- [Container parameters](#container-parameters)
- [Seeding from fallbacks](#seeding-from-fallbacks)
- [Provider-dependent fields](#provider-dependent-fields)
- [Fail-closed checks](#fail-closed-checks)

## Full reference

```yaml
# config/packages/nowo_instance_settings.yaml (defaults shown)
nowo_instance_settings:
    doctrine:
        table_prefix: ''               # letters, digits, underscores; 'app_' => app_instance_setting_value
        entity_manager: null           # 1.11: source manager of the bundle's private one (null = the one mapping the entity)
    web_ui:
        enabled: true                  # false = no admin UI (no routes, no controller); PHP / Twig / CLI stay
        path_prefix: /admin/settings   # absolute path; protect it with security.access_control
        layout_template: null          # null = nowo_ui_kit.layouts.admin
        hidden_secrets: drop           # 1.9: drop | keep_typed (save a secret typed into a hidden provider row)
        hidden_secrets_notice: false   # 1.9: notice above sections with provider-dependent secrets while dropped
        unlayered_styles: false        # 1.13: true = link the unlayered bundle stylesheet (hosts on UiKit's nowo-ui.unlayered.css)
        section_layout: stacked        # 1.14: stacked | tabs (field groups of a section as UiKit subtabs, ?settings_group=<code>)
        section_group_parameter: settings_group  # 1.14: query parameter of the open subtab
        templates: {}                  # page id => template: section, portability
    security:
        access_roles: [ROLE_ADMIN]     # any of them opens the UI; must not be empty (unless access_checker / allow_unauthenticated)
        area_roles: {}                 # section name or _portability => non-empty role list replacing access_roles ("_reveal" is refused)
        reveal_roles: [ROLE_DEVELOPER] # 1.15: roles that may reveal a stored secret of a revealable field ([] refuses every reveal)
        reveal_rate_limiter: null      # 1.15: service id of a RateLimiterFactory consuming one token per reveal
        access_checker: null           # service id implementing InstanceSettingsAccessCheckerInterface
        allow_unauthenticated: false   # DEV / DEMO only; refused unless kernel.debug is true
    portability:
        web_ui: true                   # import / export page (the CLI commands are always available)
        schema: nowo-instance-settings # envelope "schema"; imports with another schema are refused
        max_upload_bytes: 1048576      # import size cap (1024 … 16777216)
        sealed_secrets: true           # allow exporting / importing secrets sealed with the instance key
        max_file_bytes: 8388608        # image fields (1.4): decoded bytes of all the files of one envelope (0 … 16777216)
    files:                             # image fields (1.4)
        enabled: false                 # opt-in: maps the file table, registers the store, route and purge command; needs ext-gd
        path_prefix: /_settings-file   # file route prefix; must be outside web_ui.path_prefix
        orphan_retention: 30           # days a replaced / removed image stays servable before purge-files deletes it (0 … 3650)
        store: doctrine                # 1.7: doctrine (instance_setting_file table) | filesystem (stable files, no blob)
        directory: '%kernel.project_dir%/config/instance-files'   # 1.7: directory of the filesystem store
        crop: false                    # 1.8: crop step of every image field: false | true | { shape, quality, labels }
    seed:                              # host seed file (1.7)
        file: null                     # default file of nowo:instance-settings:seed (.yaml, .yml, .json)
        base_dir: '%kernel.project_dir%'   # every seed file / image path must resolve inside it
    brand:                             # brand bridge (1.5, spec 220 S3)
        enabled: false                 # opt-in: brand + appearance sections, BrandProviderInterface, UiKit provider; implies files.enabled
        public_dir: '%kernel.project_dir%/public'   # default asset paths are relative to it
        fallback_provider: null        # service id implementing Brand\BrandFallbackProviderInterface (seed)
        preset_picker: select          # 1.6: select | cards (preset / preset_dark as theme cards with swatches)
        defaults:
            name: null                 # required with enabled: true (≤ 80 characters)
            short_name: null           # ≤ 12 (null = the name cut at 12)
            eyebrow: null              # a string, or { key: …, domain: messages } (translated in the viewer's locale)
            wordmark: null             # ≤ 40 (null = the lower-cased name)
            logo_light: null           # default assets: paths under public_dir (PNG, JPEG, WebP, GIF, SVG, ICO)
            logo_dark: null
            mark: null
            app_icon: null
            favicon: null
        extra_images: {}               # id => { label, help, max_width, max_height, max_bytes, square, default }
        error_illustrations:           # 1.13: UiKit error page illustrations from uploaded extra images (files.store: filesystem)
            base: null                 # extra image id used for every status without its own error_<N> upload (e.g. mascot)
            per_code: true             # use the uploaded extra images error_<N> (400 … 599) for their status
```

Unknown keys are rejected. Security switches (`web_ui.enabled`, `security.allow_unauthenticated`, `portability.web_ui`, `portability.sealed_secrets`), `files.enabled` and `brand.enabled` must be literal booleans: `%env(bool:…)%` placeholders are refused because they would read as `true` while the container is built. Set `files.enabled` and `brand.enabled` in the host's `nowo_instance_settings` config (or from a bundle that prepends before InstanceSettings): they are read in InstanceSettings' own prepend step to map the file entity, and since 1.5 a value prepended later makes the container refuse to compile with a clear message (audit IO-I10; 1.4 failed later, with a Doctrine mapping error on the first image save).

## doctrine

| Key | Default | Description |
| --- | --- | --- |
| `table_prefix` | `''` | Prefix of the bundle table **and** its index names (REQ-ORM-002), applied by `TablePrefixListener` on `loadClassMetadata` to the bundle entity only. `app_` gives `app_instance_setting_value` and `app_uniq_instance_setting_value_section_name`. |
| `entity_manager` | `null` | Source entity manager of the bundle's private one (1.11). `null`: the manager that maps `SettingValue` (the default one; mapping prepended as before). A literal name: the entities are mapped on that manager only (removed from the others, `auto_mapping` and a default metadata driver such as DoctrineBundle 3.4 `auto_discover_entities` included; the latter fixed in Unreleased) and the build fails when it does not exist. Either way the bundle reads and writes through its own private manager on that connection: it never flushes, clears or resets yours. |

## web_ui

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Registers the controller, the access listener and the routes of the `nowo_instance_settings` loader. With `false` the bundle needs neither SecurityBundle nor form CSRF. |
| `path_prefix` | `/admin/settings` | URL prefix of every admin route. |
| `layout_template` | `null` | Layout the pages extend; `null` uses `nowo_ui_kit.layouts.admin` (REQ-UI-003). Must render the UiKit canonical blocks (`nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_styles`, `nowo_ui_scripts`) — extend `@NowoUiKit/layout/admin.html.twig` or use the UiKit `_bridge.html.twig`. |
| `hidden_secrets` | `drop` | 1.9: a secret typed into a provider-dependent row hidden for the submitted provider. `drop`: ignored (only an explicit "clear" deletes the stored secret). `keep_typed`: saved whichever provider is selected (never `required`, its own constraints run; blank keeps). A section implementing `SettingsSectionHiddenSecretsInterface` overrides it. See [USAGE](USAGE.md#provider-dependent-fields-visible_when). |
| `hidden_secrets_notice` | `false` | 1.9: while a section drops hidden secrets and has a provider-dependent secret, show a notice above its form (key `notice.hidden_secrets_dropped`, `NowoInstanceSettingsBundle` domain; override the text in the host translations). |
| `unlayered_styles` | `false` | 1.13 (audit LM29-I1): the bundle stylesheet (colour input, image cards) is `nowo-instance-settings.css`, inside UiKit's cascade layer `nowo-ui` (it declares UiKit's layer order `theme, base, nowo-ui, components, utilities` when it is the first stylesheet naming them): host utilities and unlayered host CSS win, as over UiKit's own rules. `true` links `nowo-instance-settings.unlayered.css` (the same rules, outside any layer, as in 1.12) for hosts that link UiKit's `nowo-ui.unlayered.css`: a layered rule never wins over an unlayered one. Host templates that include `settings/_styles.html.twig` can pass `unlayered: true` instead. |
| `section_layout` | `stacked` | 1.14: how a section page lays out its field groups (`SettingsSectionGroupsInterface::getGroups()`, else the fields' `withGroup()` groups). `stacked`: one column, markup unchanged. `tabs`: one UiKit subtab per group (UiKit's `tabs` runtime from the layout's default scripts; without JavaScript every group is a visible fieldset), the open tab in `?settings_group=<code>` (kept by the redirect after a save), the first tab with an error open after a failed save, an error badge per tab. One form: Save saves every group, write-only secrets left blank keep their value. A section with fewer than two groups renders stacked. `SectionEmbedOptions::withSectionLayout()` overrides it per embed. Run `assets:install` (`nowo-instance-settings-tabs.js`). See [USAGE](USAGE.md#field-groups-and-subtabs-114). |
| `section_group_parameter` | `settings_group` | 1.14 (audit MP-L1): query parameter of the open subtab in the tabs layout, namespaced so a host page keeps its own `?group=`. Letters, digits and underscores (not starting with a digit). Read on GET, set by the redirect after a save (an existing one in a host route URL is replaced, never duplicated) and printed as `data-nowo-instance-settings-group-parameter` on the tabs root for `nowo-instance-settings-tabs.js`. `SectionEmbedOptions::withGroupParameter()` overrides it per embed. |
| `templates` | `{}` | Per-page overrides: `section`, `portability`. Point at a template that extends `@!NowoInstanceSettingsBundle/settings/<page>.html.twig` and overrides one block (see [TEMPLATES](TEMPLATES.md)). |

### Layout and CSS stack (UiKit)

The pages are UiKit scaffolds: the CSS stack, icon set and theme are configured **once** for every Nowo bundle in `nowo_ui_kit` (REQ-UI-003), not per bundle.

```yaml
# Bootstrap 5 host (project layout + its CSS)
nowo_ui_kit:
    css_framework: bootstrap5
    icon_set: bootstrap-icons
    layouts:
        admin: 'admin/layout.html.twig'
```

```yaml
# Tailwind host
nowo_ui_kit:
    css_framework: tailwind
    theme:
        classes: { btn_primary: 'btn btn-primary', card: 'rounded-xl border p-4' }
```

```yaml
# Foundation host
nowo_ui_kit:
    css_framework: foundation
```

```yaml
# Own design system: only nowo-ui-* classes; map them (or the --nowo-ui-* tokens) in your CSS
nowo_ui_kit:
    css_framework: custom
    icon_set: svg_inline
    theme:
        tokens: { primary: 'var(--brand-500)', surface: 'var(--paper)' }
```

A one-file bridge maps a project layout whose content block has another name:

```twig
{# templates/admin/nowo_bridge.html.twig — nowo_ui_kit.layouts.admin: 'admin/nowo_bridge.html.twig' #}
{% extends 'admin/layout.html.twig' %}
{% use '@NowoUiKit/layout/_bridge.html.twig' %}
{% block stylesheets %}{{ parent() }}{{ block('nowo_ui_bridge_assets') }}{% endblock %}
{% block body %}{{ block('nowo_ui_bridge_body') }}{% endblock %}
{% block javascripts %}{{ parent() }}{{ block('nowo_ui_bridge_end') }}{% endblock %}
```

Bundle pages add no CSS / JS of their own; host assets stack with `{{ parent() }}`.

## security

| Key | Default | Description |
| --- | --- | --- |
| `access_roles` | `[ROLE_ADMIN]` | The user must hold **any** of these roles to open the UI. An empty list is refused at compile time (it would deny everybody) unless `access_checker` is set or `allow_unauthenticated` is true. |
| `area_roles` | `{}` | Map `<area> => [roles]` that **replaces** `access_roles` for one area: a section name (`mailer: [ROLE_SUPER_ADMIN]`) or `_portability` (the import / export page, which can download sealed secrets). Lists must not be empty. Sections the user cannot open are not shown as tabs and are left out of (and refused in) the user's web export / import. When any section is listed, **`_portability` must be listed too** (compile error otherwise) unless `portability.web_ui: false` or a custom `access_checker` is used. |
| `reveal_roles` | `[ROLE_DEVELOPER]` | **1.15.** Roles that may reveal a stored secret of a field declared `revealable: true`, asked **on top of** the section's own area (both are needed). They never fall back to `access_roles`, and `area_roles._reveal` is refused (compile error) so there is one place to look. An empty list refuses every reveal, which is how you turn the feature off for the whole instance. Ignored when `access_checker` is set: the custom checker decides the `_reveal` area (`InstanceSettingsAccessCheckerInterface::AREA_REVEAL`). |
| `reveal_rate_limiter` | `null` | **1.15. Required as soon as any field declares `revealable: true`** (owner decision of 2026-11-13: a revealable secret is never served without a brake). Service id of a Symfony `RateLimiterFactory` (`framework.rate_limiter.<name>` registers `limiter.<name>`, needs `symfony/rate-limiter`) consuming one token per reveal, keyed by the SHA-256 of the actor's user identifier. Without it a revealable field fails closed when the section definitions load (router warm-up / `cache:warmup`, else the first request) with a message naming the field and this option. A host with **no** revealable field needs nothing: no limiter, no dependency, no change. |
| `access_checker` | `null` | Service id implementing `InstanceSettingsAccessCheckerInterface` (`canAccess(?object $user, ?string $area): bool`; `$area` null = the UI itself). Replaces the role checker. |
| `allow_unauthenticated` | `false` | **DEV / DEMO only.** Opens the UI without login; the container refuses it unless `kernel.debug` is true. |

Anonymous users never pass the role checker. The token is trusted only when a firewall matched the main request, so protect the prefix with a firewall **and** `access_control` (two layers):

```yaml
# config/packages/security.yaml
security:
    firewalls:
        main:
            lazy: true
            form_login: ~        # or any authenticator
    access_control:
        - { path: ^/admin/settings, roles: ROLE_ADMIN }
```

### Revealing a stored secret (1.15)

A secret field is write-only by default: the admin form never renders it back ("leave blank to keep"). A host that
declares a field `revealable: true` adds an explicit **Reveal** action to its row, for a user holding `reveal_roles`:

```php
// A credential the admin has to read somewhere else: revealable.
yield SettingField::secret('client_secret', 'oauth.client_secret', revealable: true);
// A key whose plaintext nobody needs again: left write-only (the default).
yield SettingField::secret('signing_key', 'oauth.signing_key');
```

```yaml
nowo_instance_settings:
    security:
        access_roles: [ROLE_ADMIN]
        reveal_roles: [ROLE_DEVELOPER]          # only these users may reveal; [] refuses every reveal
        reveal_rate_limiter: limiter.instance_settings_reveal   # required with any revealable field

framework:
    rate_limiter:
        instance_settings_reveal:
            policy: fixed_window
            limit: 10
            interval: '1 hour'
```

**The limiter is not optional.** As soon as a section declares `revealable: true`, the bundle refuses to run without
`security.reveal_rate_limiter`: the section definitions fail to load (router warm-up, so `cache:warmup` fails during
the build / deploy, and otherwise the first request) with

```
Instance settings section "oauth" declares the revealable secret field "client_secret", but
nowo_instance_settings.security.reveal_rate_limiter is not set: a revealable secret is never served without a rate
limit. Declare a limiter in the host (framework: rate_limiter: instance_settings_reveal: { policy: fixed_window,
limit: 10, interval: '1 hour' }, which needs symfony/rate-limiter) and set
nowo_instance_settings.security.reveal_rate_limiter: limiter.instance_settings_reveal. To do without it, drop
revealable: true from the field, or switch every reveal off with nowo_instance_settings.security.reveal_roles: [].
```

The two ways out are in the message: drop the opt-in from the field, or set `security.reveal_roles: []`, the kill
switch — with every reveal refused, a missing limiter blocks nothing, so switching the feature off is never harder
than switching it on. `symfony/rate-limiter` stays a **dev / suggested** dependency: a host that declares no
revealable field never needs it. `security.allow_unauthenticated: true` and a custom `security.access_checker` both
count as "reveals are possible" (the bundle cannot tell who the checker allows), so they need the limiter too.

How it works, and what you accept by turning it on:

- The control is a button, not a hidden value: the plaintext is **never** rendered into the page. Clicking Reveal
  POSTs to `nowo_instance_settings_secret_reveal`
  (`<web_ui.path_prefix>/sections/{section}/secrets/{field}/reveal`, POST only, with this section's CSRF token) and the
  endpoint answers `{"key": "<section>.<field>", "value": "<secret>"}` with `Cache-Control: private, no-store`,
  `nosniff`, `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex`. One call reveals the single named field, never
  a payload of several secrets, and the value is never part of a URL.
- Every check is server-side and runs again on every call: the UI gate, the section's area, `reveal_roles`, the field's
  own `revealable: true` and the rate limiter (which is required, see below). A field without the opt-in, a plain field, a look-alike field name (the
  names are compared byte for byte with the declared ones) and a user without the roles are all refused by the
  endpoint, not only hidden in the UI.
- Every reveal is audited: a `Event\SecretRevealedEvent` (PSR-14: section, field, actor, time, whether a secret was
  stored) and a log line at `info`. A refusal is logged at `warning` with its reason. Neither ever carries the value
  (REQ-OBS-001). Listen to the event to write the reveal into your own audit trail:

  ```php
  #[AsEventListener]
  public function __invoke(SecretRevealedEvent $event): void
  {
      $this->audit->record('instance_settings.secret_revealed', $event->key(), $event->actor, $event->revealedAt);
  }
  ```

- **The trade-off.** A revealed secret is plaintext in a browser: it can be screenshotted, read over the shoulder,
  pasted into the wrong window, or kept by a browser extension. The script drops it again on Hide, after one minute,
  and whenever the tab is hidden, but it cannot undo any of those. That is why the opt-in is per field and defaults to
  false: turn it on for a credential the admin genuinely has to read elsewhere (an OAuth client secret), and leave it
  off for encryption keys, webhook signing keys and anything a person never needs to see again. With
  `security.allow_unauthenticated: true` (dev / demo only) everybody may reveal, as everybody may already read and
  change every setting.
- The endpoint belongs to the admin UI: it exists only with `web_ui.enabled: true`. Without JavaScript the row stays
  write-only, as before 1.15.

## portability

| Key | Default | Description |
| --- | --- | --- |
| `web_ui` | `true` | Shows the import / export page and its header action; `false` removes its routes (the controller also answers 404). |
| `schema` | `nowo-instance-settings` | Envelope `schema` written on export and required on import (`^[a-z0-9][a-z0-9._-]{0,99}$`). Use a product-specific value (`acme-instance-config`) so files from another application are refused. |
| `max_upload_bytes` | `1048576` | Size cap of the uploaded file and of the CLI import file (the reader stops after `max + 1` bytes). |
| `sealed_secrets` | `true` | Enables the "Include the secrets" export option and the `secrets` block on import. With `false`, secrets never leave the instance and a file with `secrets` is refused. |
| `max_file_bytes` | `8388608` | Image fields (1.4): cap of the decoded bytes of every file of one envelope (export and import, `files_too_large`). Each file is also capped by its field's `maxBytes`. `max_upload_bytes` stays the outer bound of the JSON file: base64 adds a third, so raise it (up to 16 MiB) for instances that export their images. |

## files

Image setting fields (1.4, [USAGE](USAGE.md#image-fields)). Opt-in: with the default `enabled: false` the bundle maps
no file table (the schema is the one of 1.3), registers no file store, file controller or purge command, and adds no
file route.

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Maps the file entity (`<prefix>instance_setting_file`, Doctrine mapping `NowoInstanceSettingsFiles`) and registers the file store, the file controller and `nowo:instance-settings:purge-files`. Required by image fields: a section declaring one without it fails when the section definitions load. With `true` the container refuses to compile without `ext-gd` (PNG, JPEG, WebP). Literal boolean only (the mapping is decided before the configuration is processed). The file route is still added only when a section declares an image field. |
| `path_prefix` | `/_settings-file` | Prefix of the file route `{prefix}/{section}/{field}/{hash12}[-{variant}].{ext}`. Must be outside `web_ui.path_prefix` (and not contain it): public images must not depend on the admin firewall. The container refuses an overlap. Add a `PUBLIC_ACCESS` `access_control` line for it when your firewall covers it. **Private (non-public) image fields need a firewall over this prefix** (the same context as the admin UI): the controller trusts the user only when a firewall matched the request, so without one every private image is a 404. For a CDN, ignore the query string in the cache key for this prefix and purge the CDN when you withdraw an image ([USAGE](USAGE.md#image-fields)). |
| `orphan_retention` | `30` | Days a replaced or removed image (and its variants) stays in the database and servable, so e-mails already sent keep showing it, before `nowo:instance-settings:purge-files` deletes it. `0` deletes on the next run. |
| `crop` | `false` | 1.8: UiKit's crop step (2.43) on every image field: `false`, `true` or `{shape, quality, labels}` (see [Crop step](#crop-step)). |

```yaml
# config/packages/security.yaml
security:
    firewalls:
        main:                                   # no pattern, or one that covers the file prefix as well:
            lazy: true                          # private image fields need the firewall over it
    access_control:
        - { path: ^/_settings-file/, roles: PUBLIC_ACCESS }   # public image fields; private ones check the section area themselves
        - { path: ^/admin/settings, roles: ROLE_ADMIN }
```

### Filesystem store

1.7 (owner decision D-20261109-03). With `files.store: filesystem` the images are **not** stored as database blobs:
each image field keeps one stable file in `files.directory`, so an upload in development leaves a file ready to commit
next to the [seed file](#seed), git diffs stay readable, and production mounts the directory as a persistent volume.
The default (`doctrine`) is unchanged.

| Key | Default | Description |
| --- | --- | --- |
| `store` | `doctrine` | `doctrine`: the `instance_setting_file` table (1.4 behaviour, byte for byte). `filesystem`: `Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore`. The file table stays mapped either way (no schema change between the two). |
| `directory` | `%kernel.project_dir%/config/instance-files` | Base directory of the filesystem store, created (0755 minus the umask) on the first write. An absolute local path only (not blank, no `scheme://`, no NUL; 1.7.1: a relative path is refused, because it would resolve against the current directory, which differs between PHP-FPM, the CLI and workers). It **must not** be under the public web root: files are served by the file route only. Accepts `%env(...)%` (1.7.1): the container cannot see that value, so the store applies the same rules when it is built (see below). |

```yaml
# config/packages/nowo_instance_settings.yaml
nowo_instance_settings:
    brand: { enabled: true, defaults: { name: Beacon } }
    files:
        store: filesystem
        directory: '%kernel.project_dir%/config/instance-files'
    seed:
        file: '%kernel.project_dir%/config/instance-seed.yaml'
```

Layout (names come only from the section / field allowlist, never from the request or the client file name):

```text
config/instance-files/
    brand/
        logo_light.png                    # <field>.<png|jpg|webp>: extension from the sniffed type
        app_icon.png
        extra_email_mascot.webp           # brand.extra_images.email_mascot
        .variants/
            .gitignore                    # 1.7.1: "*", written by the store, makes the directory self-ignoring
            app_icon-<hash12>-512.png     # generated PNG variants, bound to the hash of the original
            app_icon-<hash12>-192.png
```

Behaviour:

- **Uploads** (admin form, API, import, seed) are checked and re-encoded by the usual pipeline, then written
  atomically (temporary file in the same directory, `chmod` 0644 minus the umask, `rename()`): a reader or a
  concurrent save never sees a half-written file. Replacing an image overwrites the stable file and removes the file of
  another extension and the old variants; clearing a field deletes its file, whatever its hash (there is no orphan
  retention with this store, so `files.orphan_retention` does not apply and e-mails already sent lose the old image).
- **Transactional (audit JG-M1).** Inside a save, an import or a seed the new files are only staged (temporary files)
  and clears are queued; renames and deletes happen after the **outermost** commit, and a rollback removes the staged
  files. A DBAL middleware on the **bundle's connection only** (the one of `doctrine.entity_manager`) follows a
  transaction the host opens around a bundle call (`$em->wrapInTransaction(...)`), savepoints included: the file
  changes wait for its real commit, and a rollback of the transaction, or to a savepoint that encloses the call
  (a nested `wrapInTransaction()` that failed and was caught), discards them (audit JG-M3). Transactions on other
  connections neither delay nor discard them (audit JG-L7). A rename that fails after the commit keeps the previous
  file (still served). Savepoints are followed when they go through the connection's `exec()`, as DBAL's nested
  transactions and `executeStatement('SAVEPOINT …')` do; a savepoint the host sends through `executeQuery()` or a
  prepared statement is not seen, so use DBAL's nesting (audit JG-I15). Do not attach the middleware to other
  connections by hand (audit JG-I17). Since 1.10 the same middleware is registered with every store, because
  `SettingsChangedEvent` follows the same transactions (dispatched after the outermost commit, dropped on rollback).
- **The file on disk is authoritative.** The setting row says the field is set; the URL carries the first 12 hex
  characters of the current file's SHA-256 (cache busting: a logo replaced under the same name gets a new URL, the old
  URL answers 404, and the strong `ETag` is the hash). A `git pull` that changed a committed logo is served at once.
  A file of a field **without a setting row** (committed, not seeded yet) is not served (404) until the field is set.
  A file that changed between lookup and read answers 404, never an empty cached body.
- **Metadata:** `<section>/.variants/<field>.meta` keeps the hash, type and dimensions of the original, trusted while
  the file's size, mtime, ctime and inode match it; lookups and wrong-hash requests do not read and hash the file. The
  bytes are still checked against the hash before they are served.
- **Confinement:** the base is resolved with `realpath()`; section and `.variants` directories must be real
  directories (a symlink is refused), a target that is a symlink is refused, and a symlinked file is never served.
  A file that is not PNG / JPEG / WebP by its content, whose extension disagrees with its content, or larger than
  5 MiB is not served. A directory that cannot be created or written is a clear `RuntimeException`.
- **Not under the public web root:** the container refuses a `files.directory` under `%kernel.project_dir%/public` or
  `brand.public_dir` (the web server would serve the images without the file route's access check and headers),
  whatever its spelling (`..`, `//`, `.`, a symlink): the path is normalised and its nearest existing ancestor
  resolved, also when it does not exist yet. The store checks again before it creates the directory (audit JG-L6).
  With an `%env(...)%` value (1.7.1) the container skips its checks (Symfony hands the configuration tree an empty
  string for the placeholder) and the store applies them when it is built, with the resolved value: a blank value, a
  `scheme://` wrapper or a directory under the public roots (any spelling, existing or not) is a `RuntimeException`
  then, before any file is read or written. The store is built when the kernel warms up, so a wrong value fails early.
  The base is normalised lexically for reads and writes alike (`a/missing/../files` is `a/files`).
  **The variable must exist wherever the kernel boots** — PHP-FPM, workers, the CLI, and the image build or deploy
  step that runs `cache:warmup` — or be given a default (`env(FILES_DIR): '%kernel.project_dir%/config/instance-files'`
  under `parameters`): with the filesystem store, the store is built during the warm-up, so a missing or invalid value
  stops the boot (fail-closed; the database store does not use the directory). An env value must be absolute, like a
  literal one.
- **Permissions:** files 0644 and directories 0755 minus the process umask, so non-public images are readable by
  other local users of the host; use a umask of `027` (process supervisor / container entrypoint) on a shared host.
- **`purge-files`** removes stale variants and metadata (of another hash, or of a field without a file) and `.tmp-*`
  files older than one hour; it never deletes an original (a committed file of a field that is not seeded yet must
  survive). Database blobs left in `instance_setting_file` from before a store switch are not purged by it.
- **Commit the originals, not `.variants/`.** Since 1.7.1 the store writes `.variants/.gitignore` (content `*`, which
  also ignores itself) when it creates or uses a variants directory, so variants and metadata never show up in
  `git status`; it never overwrites a `.gitignore` you put there. Add `<directory>/**/.variants/` to the host
  `.gitignore` as well (covers checkouts made before the first save, and directories created by 1.7.0). Variants and
  metadata are regenerated when the image is saved or seeded.

### Crop step

1.8 (owner decision D-20261109-09). `files.crop` puts UiKit's image crop step (UiKit 2.43) on the file input of every
image field, the brand ones included. The aspect, `min`, `max`, `format` and `max_bytes` are derived per field from
its rules ([USAGE](USAGE.md#crop-step-18)); here you set only what fits every field:

| Key | Default | Description |
| --- | --- | --- |
| `crop` | `false` | `true` turns it on with the defaults; an array turns it on with these keys. `{enabled: false, …}` keeps it off. |
| `crop.shape` | `null` (square) | `square` or `circle` (a visual mask: the stored image stays square). |
| `crop.quality` | `null` (0.92) | WebP / JPEG encoder quality, 0 to 1. |
| `crop.labels` | `{}` | Dialog texts over UiKit's translations `image_crop.*`: `title`, `instructions`, `stage`, `zoom`, `zoom_status`, `apply`, `cancel`, `close`, `too_large`, `optimizing`. |

```yaml
nowo_instance_settings:
    files:
        enabled: true
        crop:
            quality: 0.85
            labels: { apply: 'Use this crop' }
```

A field these options cannot crop (its derived crop does not fit its rules, or `shape: circle` forces an aspect 1 it does not take) is shown without them: without the crop, or with its own `withCrop()` options. Per-field `labels` merge with these per text. Other keys (`aspect`, `max`, `max_bytes`, …) are refused here: set them per field with `SettingField::withCrop()`,
and turn a field off with `SettingField::withoutCrop()`. Unknown labels or out-of-range values fail the container
build (`nowo_instance_settings.files.crop: …`). The server-side checks and re-encoding do not change. Add `img-src
blob:` to a strict CSP on the admin pages.

## seed

1.7 (IS-3). `nowo:instance-settings:seed` loads a **host seed file** — the initial values and images of an instance,
committed in the host repository — **fill-only**: a field that already has a stored value or image is never touched,
so fresh instances (dev, e2e, new installs) can run it on every boot; a second run is a no-op.

| Key | Default | Description |
| --- | --- | --- |
| `file` | `null` | Default seed file of the command (`.yaml`, `.yml` — needs `symfony/yaml` — or `.json`). Null: give the file as argument. |
| `base_dir` | `%kernel.project_dir%` | Every seed file and every image path must resolve (with `realpath()`, symlinks followed) inside this directory. An absolute local path only (1.7.1: a relative one is refused). Accepts `%env(...)%`: checked when the seeder is built; the variable must then exist wherever the seed runs. |

```bash
php bin/console nowo:instance-settings:seed --dry-run          # validate; list what would be seeded (names only)
php bin/console nowo:instance-settings:seed                    # seed.file
php bin/console nowo:instance-settings:seed config/e2e-seed.yaml --section=brand --section=appearance
```

Why a new command instead of an option of `seed-from-fallbacks`: the two sources differ (code-provided fallbacks vs a
committed file with images, path checks and all-or-nothing validation), both are fill-only, so they compose: run
`seed` then `seed-from-fallbacks` (or the other way round); the first one to fill a field wins.

Rules:

- **All-or-nothing validation before any write.** Unknown top-level key, section or field, a **secret field** (never in
  a committed file: set secrets in the admin UI or seed them from the environment with `seed-from-fallbacks`), an image
  field under `settings` or a plain field under `files`, a non-scalar or invalid value, a refused path or image: the
  command prints every problem as `<section>.<field>: <code> (<sentence>)` — never a value — writes nothing and
  exits 1.
- **Values** go through the section save filter (e.g. `appearance` lower-cases colours and drops one equal to its
  preset: reported as `default`) and the field validation, like a save. Blank values are skipped (`empty`).
- **Image paths** are relative to the seed file's directory: no absolute path, URL or stream wrapper (`http://`,
  `phar://`, `data:` …), no NUL byte; resolved with `realpath()` and refused outside `base_dir` (also through a
  symlink). The bytes go through the same checks as an upload (type from the content: PNG / JPEG / WebP only; the
  field's `maxBytes`, dimension limits, square rule, e.g. `app_icon` ≥ 512 px; animated WebP refused). **SVG is
  refused** (`svg_not_supported`: use PNG or WebP; owner decision spec 220). Images are stored by the configured
  store: rows of `instance_setting_file` with the database store. With the [filesystem store](#filesystem-store), a
  valid image that already sits at the field's stable path (and has no row yet) is **adopted** whatever path the seed
  gives, never overwritten by another file; otherwise the seed image is re-encoded into the stable file.
  **Adoption is lossless (1.7.1).** The committed file is checked like an upload (type, size, dimensions, square
  rule, a full decode), then metadata and trailing data are removed **without re-encoding the pixels**
  (`File\ImageMetadataStripper`, allow-lists): JPEG keeps only the decoding segments, a JFIF `APP0` without thumbnail,
  `APP14` Adobe and GD's own `CREATOR: gd-jpeg` comment, and drops every other `APPn` (EXIF, GPS, XMP, ICC, MPF,
  Photoshop), other comments and anything after `EOI`; PNG keeps the critical chunks and `tRNS`, `gAMA`, `cHRM`,
  `sRGB`, `sBIT`, `bKGD`, `pHYs` (each only at its spec length for the colour type; another length re-encodes), and
  drops text, `eXIf`, `tIME`, `iCCP`, APNG frame chunks, private chunks and anything
  after `IEND`; WebP keeps `VP8X` (ICC / EXIF / XMP flags cleared), `ALPH` and the image chunk, drops `ICCP`, `EXIF`,
  `XMP `, unknown chunks and trailing bytes, and rewrites the RIFF size. A file with nothing to remove is left **byte
  for byte** (no write, same mtime and inode), so seeding a fresh database again (an e2e reset) changes nothing on disk;
  a file with metadata is rewritten once without it. ICC profiles are dropped, as the re-encode drops them: commit
  sRGB images. The re-encode is still used when the structure cannot be stripped safely (a truncated or unknown
  segment, a PNG CRC mismatch, an unknown critical chunk…), and for a JPEG whose EXIF orientation is not 1 (dropping
  EXIF would turn it; the re-encode turns it upright once, then the file is stable). Uploads through the admin, the
  API and imports keep the re-encode. Lossless adoption removes metadata, not data hidden in the image streams (palette
  entries, IDAT or entropy-coded data, unused JPEG tables, a VP8 tail); uploads and imports are re-encoded, so only a
  committed file can carry such bytes, and they are still served as `image/*` with `nosniff` and a `sandbox` CSP.
- **Insert-only** in one transaction, each field re-checked there (an admin save that wins the race is kept). Audited
  like a save: one `Instance settings saved.` log line and one `SettingsChangedEvent` per section with origin `seed`
  and `fileChanges` (`old: null`, `new: <sha256>`), dispatched after the commit, then one summary line. Output, report
  and logs carry names and reason codes only.

### Seed file format

Versioned (`schema: nowo-instance-settings-seed`, `version: 1`); top-level keys `schema`, `version`, `settings`
(section => field => scalar) and `files` (section => image field => path relative to this file).

```yaml
# config/instance-seed.yaml
schema: nowo-instance-settings-seed
version: 1
settings:
    brand:
        name: Beacon
        short_name: Beacon
        eyebrow: Uptime for teams
        wordmark: beacon
    appearance:
        preset: ocean
        preset_dark: obsidian
        light_accent: '#0f766e'
        light_accent_deep: '#115e59'
        light_danger: '#b91c1c'
        light_warn: '#b45309'
        light_paper: '#f8fafc'
        light_ink: '#0f172a'
        light_surface: '#ffffff'
        dark_accent: '#2dd4bf'
        # … dark_accent_deep, dark_danger, dark_warn, dark_paper, dark_ink, dark_surface
        corner_style: rounded
        border_strength: subtle
        footer_fixed: false
files:
    brand:
        logo_light: instance-files/brand/logo_light.png
        logo_dark: instance-files/brand/logo_dark.png
        mark: instance-files/brand/mark.png
        app_icon: instance-files/brand/app_icon.png      # square, ≥ 512 px
        favicon: instance-files/brand/favicon.png        # square, ≥ 32 px
        extra_email_mascot: instance-files/brand/extra_email_mascot.webp   # brand.extra_images.email_mascot
```

The same structure as JSON (`instance-seed.json`) is accepted. The seed file itself is capped at 1 MiB.

### Host-side importer API

`Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder` is a public service; every method is fill-only, validates
all-or-nothing, audits like the command and returns a `SeedReport` (`seeded`, `skipped` => reason, `dryRun`) or throws
`SeedFileException` (`errors`: one line per problem):

```php
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;

$seeder->seedFile('/srv/app/config/instance-seed.yaml', sections: ['brand'], dryRun: false);
$seeder->seed(['settings' => ['brand' => ['name' => 'Beacon']], 'files' => ['brand' => ['logo_light' => 'brand/logo.png']]], '/srv/app/config');
// One image setting from a local file (absolute, or relative to seed.base_dir; must resolve inside it):
$seeder->seedImage('brand.logo_light', '/srv/app/config/instance-files/brand/logo_light.png');
```

To **replace** an image (not fill-only) use the regular save API, which works with both stores:

```php
use Nowo\InstanceSettingsBundle\File\ImageUpload;

$settings->save('brand', ['logo_light' => ImageUpload::fromPath('/path/to/logo.png')]);   // InstanceSettingsInterface
```

`ImageUpload::fromPath()` reads a local file with the field's byte cap; since 1.7 it refuses URLs and stream wrappers.
It does not confine the path to a directory: pass only paths your code chose.

## brand

Brand bridge (1.5, spec 220 S3, [USAGE](USAGE.md#brand-bridge)). Opt-in: with the default `enabled: false` nothing is
registered (no section, no service, no alias, no Twig function) and UiKit's appearance provider is untouched.

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Registers the `brand` and `appearance` sections, `Brand\InstanceBrandProvider` (aliased as `Nowo\Brand\Contract\BrandProviderInterface`), `Brand\InstanceBrandAssetReader` (`BrandAssetReaderInterface`), `nowo_brand()`, and takes over `nowo_ui_kit.appearance.provider` when UiKit's appearance is enabled, the host set no provider and kept `nowo_ui_kit.appearance.brand: auto` (with `brand: off` UiKit keeps its config look). Needs `nowo-tech/brand-contracts` (compile error otherwise). **Implies `files.enabled: true`** (the brand images are image fields). Literal boolean only. |
| `public_dir` | `%kernel.project_dir%/public` | Directory the default asset paths are relative to. Default assets are served by your web server as plain static files at `/<path>`. |
| `fallback_provider` | `null` | Service id of a `Brand\BrandFallbackProviderInterface`: values the two sections seed from (`nowo:instance-settings:seed-from-fallbacks`). |
| `preset_picker` | `select` | 1.6 (IS-2): `select` shows `appearance.preset` / `preset_dark` as a select of preset ids; `cards` as a radio group of theme cards with a swatch of each preset (light palette for `preset`, dark for `preset_dark`) and UiKit's preset names (`appearance.preset.<id>`, domain `NowoUiKitBundle`; host presets show a readable id). Values, validation and export are the same. See [USAGE](USAGE.md#theme-cards). |
| `defaults.name` | `null` | Brand name while none is stored. **Required** with `enabled: true`. At most 80 printable characters (no control characters, bidi controls or line separators). |
| `defaults.short_name` / `wordmark` | `null` | At most 12 / 40 characters; null = the contract defaults (the name cut at 12; the lower-cased name). |
| `defaults.eyebrow` | `null` | A literal string (≤ 120), or `{key, domain}`: a translation key translated in the viewer's locale while nothing is stored. A stored eyebrow is a literal. |
| `defaults.<slot>` | `null` | `logo_light`, `logo_dark`, `mark`, `app_icon`, `favicon`: a path relative to `public_dir` (letters, digits, `.`, `_`, `-`, `/`; no `..`, no leading `/`, no symbolic link out of `public_dir`) of a PNG, JPEG, WebP, GIF, SVG or ICO file. Read when the container compiles (type from the extension, dimensions, SHA-256; a missing or unreadable file fails the build), trusted (`isInlineSafe()`), served at `/<path>?v=<12 hex of its SHA-256>` (root-relative: a request base path is not added), and replaced by an upload of the same slot. The container is rebuilt in debug when the file changes; in production, clear the cache after replacing a committed default. |
| `extra_images.<id>` | — | Extra brand image (`[a-z][a-z0-9_]{0,57}`): field `extra_<id>`, slot `extra.<id>`, public. `label` / `help` (translation keys in the bundle domain; null = `brand.field.extra`), `max_width` / `max_height` (≤ 4096), `max_bytes` (≤ 5 MiB), `square`, `default` (as `defaults.<slot>`). |

| `error_illustrations.base` | `null` | 1.13: id of a declared `extra_images` entry (e.g. `mascot`) whose **uploaded** image illustrates every error status without its own uploaded `error_<N>` image. Another id fails the build; a non-null value with `files.store: doctrine` fails the build too. |
| `error_illustrations.per_code` | `true` | 1.13: the uploaded extra image `error_<N>` (declared as `extra_images.error_<N>`, N = 400 … 599) illustrates status N. |

### Error page illustrations (1.13)

With `brand.enabled`, `files.store: filesystem` and UiKit ≥ 2.47 (its `View\ErrorIllustrationProviderInterface`), the
bundle registers `Brand\BrandErrorIllustrationProvider` under the public service id
**`nowo_instance_settings.brand.error_illustration_provider`**. UiKit never depends on this bundle: point its error pages
at the service (UiKit refuses a missing service id at build time):

```yaml
# config/packages/nowo_instance_settings.yaml
nowo_instance_settings:
    files:
        store: filesystem
    brand:
        enabled: true
        extra_images:
            mascot:    { label: brand.mascot, default: brand/mascot.png }
            error_404: { label: brand.error_404, default: illustrations/error-404.png }
            error_503: { label: brand.error_503, default: illustrations/error-503.png }
            # … one entry per status you want to illustrate (error_400 … error_599)
        error_illustrations:
            base: mascot     # the uploaded mascot for every status without its own upload
            per_code: true   # default

# config/packages/nowo_ui_kit.yaml
nowo_ui_kit:
    error_pages:
        illustration_provider: nowo_instance_settings.brand.error_illustration_provider
        illustration_provider_budget_ms: 20
        illustrations:   # still used for every status the provider leaves to UiKit (null answer)
            404: illustrations/error-404
            default: illustrations/error-500
```

For status N the provider answers the **uploaded** `error_<N>` image, else the **uploaded** base image, else null (UiKit
then uses `error_pages.illustrations`: the code, then `default`). Configured `default` assets never count: they stay
with UiKit's map. See [USAGE](USAGE.md#error-page-illustrations-113). With `files.store: doctrine` the service is not
registered (the images live in the database, and an error page must not need it).

The brand images are always public (served anonymously by the file route): keep the `PUBLIC_ACCESS` line of
[files](#files). Access to the two sections follows `security.area_roles.brand` / `.appearance` (default: the UI roles).

## FormKit profile

The forms are FormKit types bound to the profile **`nowo_form_kit.profiles.instance_settings`** (REQ-TWIG-005 rule 11), prepended by the bundle with: translation domain `NowoInstanceSettingsBundle`, `auto_help: false`, `auto_placeholder: false`, no framework classes (the UiKit settings scaffold theme paints the fields). Define the profile yourself to change it; the bundle then prepends nothing:

```yaml
nowo_form_kit:
    profiles:
        instance_settings:
            translation_domain: NowoInstanceSettingsBundle
            auto_help: false
            auto_placeholder: false
            defaults:
                row_attr: { class: 'settings-row' }
```

## Encryption (DoctrineEncryptBundle)

Requires DoctrineEncryptBundle **^2.10**. Secret fields use DoctrineEncrypt's **default profile** (the `#[Encrypted]` attribute of `SettingValue::$secretValue`). Configure at least one profile and keep its key out of the repository:

```yaml
nowo_doctrine_encrypt:
    default_profile: default
    on_decrypt_failure: 'null'        # recommended (or 'throw'): never hand ciphertext to consumers (DoctrineEncrypt ^2.10)
    # generate_missing_key: false     # default outside kernel.debug: a missing key file is an error, not a new key
    profiles:
        default:
            encryptor_class: Halite   # or Defuse; MysqlAes (unauthenticated) is refused for sealed exports
            secret_key_env_var: '%env(APP_ENCRYPT_KEY)%'     # or secret_directory_path: '%kernel.project_dir%/var/secrets'
```

The bundle also guards itself: secrets are stored with a terminator, so a value DoctrineEncrypt could not decrypt reads
as "not set" whatever `on_decrypt_failure` says.

Sealed exports use the same key: two instances that share it can exchange secrets; any other instance refuses them.

## Profiles

REQ-CFG-001 does not apply: the bundle has one configuration tree. Variants are expressed as **sections** (one per concern), not as named profiles.

## Container parameters

`nowo_instance_settings.doctrine.table_prefix`, `.web_ui.enabled`, `.web_ui.path_prefix`, `.web_ui.layout_template`, `.web_ui.templates`, `.security.allow_unauthenticated`, `.portability.schema`, `.files.enabled` (effective: true with `brand.enabled` too), `.files.path_prefix`, `.files.orphan_retention`, `.files.mapped` (set by prepend: whether the file entity was mapped), `.brand.enabled` (1.5).

## Seeding from fallbacks

For a committed seed file with images, see [seed](#seed). No configuration key: `FallbackSeeder` (public service) and `nowo:instance-settings:seed-from-fallbacks` are always
registered and use `doctrine.entity_manager`. Sections opt in with `SectionFallbackProviderInterface`. See
[USAGE](USAGE.md#seeding-from-fallbacks-db-only-mode), including the non-transactional migration rule.

## Provider-dependent fields

`visible_when` is declared per field in code (`SettingField::withVisibleWhen()`); since 1.9 `web_ui.hidden_secrets`
and `web_ui.hidden_secrets_notice` (above) decide what happens to a secret typed into a hidden row. It needs
`nowo-tech/ui-kit-bundle` ^2.23 plus the UiKit `visible-when` Stimulus peer in the host. See
[USAGE](USAGE.md#provider-dependent-fields-visible_when).

## Fail-closed checks

The container refuses to compile when:

- `NowoDoctrineEncryptBundle` is not registered (secrets would be stored in clear);
- the UI is enabled and `security.allow_unauthenticated` is false but SecurityBundle is missing;
- the UI is enabled without form CSRF (`framework.csrf_protection`, `framework.form.csrf_protection`, `symfony/security-csrf`);
- `security.allow_unauthenticated` is true without `kernel.debug`;
- `security.access_roles` (or an `area_roles` list) is empty without a custom checker;
- `security.area_roles` restricts sections without an explicit `_portability` entry while the import / export page is on;
- a security switch is an env placeholder instead of a literal boolean;
- `files.path_prefix` overlaps `web_ui.path_prefix`;
- `files.enabled` is true and `ext-gd` lacks PNG, JPEG or WebP support;
- `files.enabled` or `brand.enabled` was prepended after this bundle, so the file entity is not mapped (1.5, audit IO-I10);
- `brand.enabled` is true and the host defines its own `BrandProviderInterface` service or alias (two brands, 1.5,
  audit IU-I2);
- `brand.enabled` is true without `nowo-tech/brand-contracts`, without `brand.defaults.name`, or with a default asset
  path that is unsafe, missing or not an image (1.5);
- `brand.error_illustrations.base` is not a declared `brand.extra_images` id, or is set while `files.store` is
  `doctrine` (1.13: an error page illustration is never read from the database).

When the section definitions load (router warm-up, first request), a section declaring an image field throws a
`LogicException` without `files.enabled: true` (the file table is not mapped) or without `ext-gd` with PNG, JPEG and
WebP support (checked again for a container compiled on another machine): images are never stored without the
re-encode step.
