# Configuration

## Table of contents

- [Full reference](#full-reference)
- [doctrine](#doctrine)
- [web_ui](#web_ui)
  - [Layout and CSS stack (UiKit)](#layout-and-css-stack-uikit)
- [security](#security)
- [portability](#portability)
- [files](#files)
- [brand](#brand)
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
        entity_manager: null           # Doctrine ORM entity manager name (null = default)
    web_ui:
        enabled: true                  # false = no admin UI (no routes, no controller); PHP / Twig / CLI stay
        path_prefix: /admin/settings   # absolute path; protect it with security.access_control
        layout_template: null          # null = nowo_ui_kit.layouts.admin
        templates: {}                  # page id => template: section, portability
    security:
        access_roles: [ROLE_ADMIN]     # any of them opens the UI; must not be empty (unless access_checker / allow_unauthenticated)
        area_roles: {}                 # section name or _portability => non-empty role list replacing access_roles
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
    brand:                             # brand bridge (1.5, spec 220 S3)
        enabled: false                 # opt-in: brand + appearance sections, BrandProviderInterface, UiKit provider; implies files.enabled
        public_dir: '%kernel.project_dir%/public'   # default asset paths are relative to it
        fallback_provider: null        # service id implementing Brand\BrandFallbackProviderInterface (seed)
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
```

Unknown keys are rejected. Security switches (`web_ui.enabled`, `security.allow_unauthenticated`, `portability.web_ui`, `portability.sealed_secrets`), `files.enabled` and `brand.enabled` must be literal booleans: `%env(bool:…)%` placeholders are refused because they would read as `true` while the container is built. Set `files.enabled` and `brand.enabled` in the host's `nowo_instance_settings` config (or from a bundle that prepends before InstanceSettings): they are read in InstanceSettings' own prepend step to map the file entity, and since 1.5 a value prepended later makes the container refuse to compile with a clear message (audit IO-I10; 1.4 failed later, with a Doctrine mapping error on the first image save).

## doctrine

| Key | Default | Description |
| --- | --- | --- |
| `table_prefix` | `''` | Prefix of the bundle table **and** its index names (REQ-ORM-002), applied by `TablePrefixListener` on `loadClassMetadata` to the bundle entity only. `app_` gives `app_instance_setting_value` and `app_uniq_instance_setting_value_section_name`. |
| `entity_manager` | `null` | Entity manager used by the repository (resolved per call from `doctrine`; a closed manager is reset). |

## web_ui

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Registers the controller, the access listener and the routes of the `nowo_instance_settings` loader. With `false` the bundle needs neither SecurityBundle nor form CSRF. |
| `path_prefix` | `/admin/settings` | URL prefix of every admin route. |
| `layout_template` | `null` | Layout the pages extend; `null` uses `nowo_ui_kit.layouts.admin` (REQ-UI-003). Must render the UiKit canonical blocks (`nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_styles`, `nowo_ui_scripts`) — extend `@NowoUiKit/layout/admin.html.twig` or use the UiKit `_bridge.html.twig`. |
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

## brand

Brand bridge (1.5, spec 220 S3, [USAGE](USAGE.md#brand-bridge)). Opt-in: with the default `enabled: false` nothing is
registered (no section, no service, no alias, no Twig function) and UiKit's appearance provider is untouched.

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Registers the `brand` and `appearance` sections, `Brand\InstanceBrandProvider` (aliased as `Nowo\Brand\Contract\BrandProviderInterface`), `Brand\InstanceBrandAssetReader` (`BrandAssetReaderInterface`), `nowo_brand()`, and takes over `nowo_ui_kit.appearance.provider` when UiKit's appearance is enabled and the host set no provider. Needs `nowo-tech/brand-contracts` (compile error otherwise). **Implies `files.enabled: true`** (the brand images are image fields). Literal boolean only. |
| `public_dir` | `%kernel.project_dir%/public` | Directory the default asset paths are relative to. Default assets are served by your web server as plain static files at `/<path>`. |
| `fallback_provider` | `null` | Service id of a `Brand\BrandFallbackProviderInterface`: values the two sections seed from (`nowo:instance-settings:seed-from-fallbacks`). |
| `defaults.name` | `null` | Brand name while none is stored. **Required** with `enabled: true`. At most 80 printable characters (no control characters, bidi controls or line separators). |
| `defaults.short_name` / `wordmark` | `null` | At most 12 / 40 characters; null = the contract defaults (the name cut at 12; the lower-cased name). |
| `defaults.eyebrow` | `null` | A literal string (≤ 120), or `{key, domain}`: a translation key translated in the viewer's locale while nothing is stored. A stored eyebrow is a literal. |
| `defaults.<slot>` | `null` | `logo_light`, `logo_dark`, `mark`, `app_icon`, `favicon`: a path relative to `public_dir` (letters, digits, `.`, `_`, `-`, `/`; no `..`, no leading `/`, no symbolic link out of `public_dir`) of a PNG, JPEG, WebP, GIF, SVG or ICO file. Read when the container compiles (type from the extension, dimensions, SHA-256; a missing or unreadable file fails the build), trusted (`isInlineSafe()`), and replaced by an upload of the same slot. The container is rebuilt in debug when the file changes; in production, clear the cache after replacing a committed default. |
| `extra_images.<id>` | — | Extra brand image (`[a-z][a-z0-9_]{0,57}`): field `extra_<id>`, slot `extra.<id>`, public. `label` / `help` (translation keys in the bundle domain; null = `brand.field.extra`), `max_width` / `max_height` (≤ 4096), `max_bytes` (≤ 5 MiB), `square`, `default` (as `defaults.<slot>`). |

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

No configuration key: `FallbackSeeder` (public service) and `nowo:instance-settings:seed-from-fallbacks` are always
registered and use `doctrine.entity_manager`. Sections opt in with `SectionFallbackProviderInterface`. See
[USAGE](USAGE.md#seeding-from-fallbacks-db-only-mode), including the non-transactional migration rule.

## Provider-dependent fields

No configuration key: `visible_when` is declared per field in code (`SettingField::withVisibleWhen()`) and needs
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
- `brand.enabled` is true without `nowo-tech/brand-contracts`, without `brand.defaults.name`, or with a default asset
  path that is unsafe, missing or not an image (1.5).

When the section definitions load (router warm-up, first request), a section declaring an image field throws a
`LogicException` without `files.enabled: true` (the file table is not mapped) or without `ext-gd` with PNG, JPEG and
WebP support (checked again for a container compiled on another machine): images are never stored without the
re-encode step.
