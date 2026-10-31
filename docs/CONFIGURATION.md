# Configuration

## Table of contents

- [Full reference](#full-reference)
- [doctrine](#doctrine)
- [web_ui](#web_ui)
  - [Layout and CSS stack (UiKit)](#layout-and-css-stack-uikit)
- [security](#security)
- [portability](#portability)
- [FormKit profile](#formkit-profile)
- [Encryption (DoctrineEncryptBundle)](#encryption-doctrineencryptbundle)
- [Profiles](#profiles)
- [Container parameters](#container-parameters)
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
```

Unknown keys are rejected. Security switches (`web_ui.enabled`, `security.allow_unauthenticated`, `portability.web_ui`, `portability.sealed_secrets`) must be literal booleans: `%env(bool:…)%` placeholders are refused because they would read as `true` while the container is built.

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
| `area_roles` | `{}` | Map `<area> => [roles]` that **replaces** `access_roles` for one area: a section name (`mailer: [ROLE_SUPER_ADMIN]`) or `_portability` (the import / export page, which can download sealed secrets). Lists must not be empty. Sections the user cannot open are not shown as tabs. |
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

Secret fields use DoctrineEncrypt's **default profile** (the `#[Encrypted]` attribute of `SettingValue::$secretValue`). Configure at least one profile and keep its key out of the repository:

```yaml
nowo_doctrine_encrypt:
    default_profile: default
    profiles:
        default:
            encryptor_class: Halite
            secret_key_env_var: '%env(APP_ENCRYPT_KEY)%'     # or secret_directory_path: '%kernel.project_dir%/var/secrets'
```

Sealed exports use the same key: two instances that share it can exchange secrets; any other instance refuses them.

## Profiles

REQ-CFG-001 does not apply: the bundle has one configuration tree. Variants are expressed as **sections** (one per concern), not as named profiles.

## Container parameters

`nowo_instance_settings.doctrine.table_prefix`, `.web_ui.enabled`, `.web_ui.path_prefix`, `.web_ui.layout_template`, `.web_ui.templates`, `.security.allow_unauthenticated`, `.portability.schema`.

## Fail-closed checks

The container refuses to compile when:

- `NowoDoctrineEncryptBundle` is not registered (secrets would be stored in clear);
- the UI is enabled and `security.allow_unauthenticated` is false but SecurityBundle is missing;
- the UI is enabled without form CSRF (`framework.csrf_protection`, `framework.form.csrf_protection`, `symfony/security-csrf`);
- `security.allow_unauthenticated` is true without `kernel.debug`;
- `security.access_roles` (or an `area_roles` list) is empty without a custom checker;
- a security switch is an env placeholder instead of a literal boolean.
