# Usage

## Table of contents

- [Contributing a settings section](#contributing-a-settings-section)
  - [Fields](#fields)
  - [Section metadata and order](#section-metadata-and-order)
  - [Extra actions next to the submit button](#extra-actions-next-to-the-submit-button)
  - [Reading the values in the contributing bundle](#reading-the-values-in-the-contributing-bundle)
  - [Reacting to changes](#reacting-to-changes)
  - [Rules for bundle authors](#rules-for-bundle-authors)
  - [Example: mailer section (MailKit)](#example-mailer-section-mailkit)
  - [Example: SMS provider section (SmsKit)](#example-sms-provider-section-smskit)
  - [Example: settings-backed Mercure hub](#example-settings-backed-mercure-hub)
- [Reading settings](#reading-settings)
- [Writing settings from code](#writing-settings-from-code)
- [Secrets](#secrets)
- [Export and import (config portability)](#export-and-import-config-portability)
  - [Envelope](#envelope)
  - [CLI](#cli)
  - [Portability providers](#portability-providers)
- [Admin UI](#admin-ui)
  - [Access](#access)
  - [Forms (FormKit)](#forms-formkit)
  - [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Translations](#translations)
- [Doctrine schema and migrations](#doctrine-schema-and-migrations)

## Contributing a settings section

A **section** is a group of settings with its own admin page (tab), its own key prefix (`<section>.<field>`) and its own entry in exports. The host application and any bundle can contribute sections; sections never depend on each other.

Implement `Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface` — or extend `AbstractSettingsSection`, which provides the label `<name>.label`, the domain `messages`, no intro and no icon — and register the class as a service. Every service implementing the interface is **autoconfigured** with the tag `nowo_instance_settings.section`; bundles that do not autoconfigure their services add the tag themselves.

```php
namespace App\Settings;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Symfony\Component\Validator\Constraints\Range;

#[AsSettingsSection(priority: 30)]           // optional: tab / export order (higher first)
final class SiteSettingsSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'site';                         // stable id: never rename once values are stored
    }

    public function getFields(): iterable
    {
        yield SettingField::string('name', 'Acme', 'site.name', required: true);
        yield SettingField::int('page_size', 25, 'site.page_size', 'site.page_size_help', constraints: [new Range(min: 5, max: 200)]);
        yield SettingField::choice('theme', ['site.theme_light' => 'light', 'site.theme_dark' => 'dark'], 'light', 'site.theme');
        yield SettingField::bool('maintenance_banner', false, 'site.maintenance_banner')->notPortable();
        yield SettingField::secret('api_key', 'site.api_key', 'site.api_key_help');
    }
}
```

### Fields

`SettingField` is an immutable value object. Named constructors:

| Constructor | Type | Value | Admin widget |
| --- | --- | --- | --- |
| `string($name, $default, $label, $help, $required, $constraints)` | `string` | `?string` (trimmed, `''` → null) | `TextType` |
| `text(...)` | `text` | `?string` (whitespace kept) | `TextareaType` |
| `email($name, $default, $label, $help, $required)` | `email` | `?string`, `Email` constraint | `EmailType` |
| `url($name, $default, $label, $help, $required, $constraints)` | `url` | `?string`, absolute `http(s)://` only | `UrlType` |
| `bool($name, $default = false, $label, $help)` | `bool` | `bool` (`"1"`, `"on"`, `"true"` accepted) | `CheckboxType` |
| `int(...)` / `float(...)` | `int` / `float` | `?int` / `?float` (numeric strings accepted) | `IntegerType` / `NumberType` |
| `choice($name, $choices, $default, $label, $help, $required)` | `choice` | one of the values of `$choices` (label key => value) | `ChoiceType` |
| `secret($name, $label, $help, $required, $constraints, $type = String)` | string-like, `secret: true` | encrypted at rest, read with `getSecret()` | DoctrineEncrypt `SecretType` |

Every typed factory accepts `$constraints` (for example an `https://` or host allow-list check on a provider URL). Modifiers: `->notPortable()` (left out of exports, refused on imports — for flags that must never travel between instances) and `->withFormOptions([...])` (extra options merged into the admin form field). The full constructor also takes `$portable`, `$constraints` (Symfony Validator constraints, applied on save and import) and `$choices`.

Definition errors fail fast: a field name must be lower snake case (`^[a-z][a-z0-9_]{0,63}$`), a secret must be string-typed and has no default, a choice needs choices, a default must match the type; section names follow the same pattern and must be unique, field names must be unique in their section.

Field **labels and help** are translation keys in the section domain (`getTranslationDomain()`); the label defaults to the field name.

### Section metadata and order

| Method | Default (`AbstractSettingsSection`) | Used for |
| --- | --- | --- |
| `getName()` | — (abstract) | key prefix, URL `/admin/settings/sections/<name>`, export key, `security.area_roles` key |
| `getLabel()` | `<name>.label` | tab and card title |
| `getTranslationDomain()` | `messages` | label, intro, field labels / help / choice labels |
| `getIntro()` | `null` | text under the card title |
| `getIcon()` | `null` | UiKit icon of the tab (canonical kind such as `config`, or an icon name) |
| `getFields()` | — (abstract) | form order and validation |

Order: tagged-iterator priority. Use `#[AsSettingsSection(priority: 20)]` on the class or set `priority` on the service tag; several tags on one service are collapsed to the highest priority.

### Extra actions next to the submit button

Implement `SettingsSectionActionsInterface::getActions(SectionActionContext $context)` and return UiKit `Action`s; they render on the section page right after **Save**. The context carries the section name, the current `Request` and the authenticated user (or null).

A mutating action (send a test e-mail, send a test SMS) must be a POST `Action` carrying a CSRF `FormView` and be handled by a controller of the contributing bundle that checks the same access and the token:

- authorise with `InstanceSettingsAccessCheckerInterface::canAccess($user, '<section>')` or `AccessGuard::isGranted('<section>')` — both are registered (public) even when `web_ui.enabled` is false, so kits can protect their own actions and commands;
- **gotcha:** build the CSRF form **named** (`$formFactory->createNamed('nowo_mail_kit_test', CsrfOnlyType::class, …)`): `handleRequest()` never submits a form whose name is empty, so an unnamed CSRF-only form silently never validates;
- link back with the public route names of `Routing\InstanceSettingsRoutes` (`SECTION`, `INDEX`, `PORTABILITY`, …), e.g. `redirectToRoute(InstanceSettingsRoutes::SECTION, ['section' => 'mailer'])`.

```php
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;

public function getActions(SectionActionContext $context): iterable
{
    if ($context->user === null) {
        return;
    }
    $form = $this->formFactory->createNamed('nowo_mail_kit_test', CsrfOnlyType::class, null, ['csrf_token_id' => 'nowo_mail_kit_test']);

    yield new Action('custom', new Label('mailer.send_test', 'NowoMailKitBundle'), $this->urls->generate('nowo_mail_kit_test'), method: 'POST', form: $form->createView());
}
```

### Reading the values in the contributing bundle

Inject `Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface` (public alias) and read `<section>.<field>` keys. Keep the read **inside the call that needs it** (not in a constructor): values are memoized per request, so reading on every use is cheap and always current.

```php
final class ConfiguredMailerDsn
{
    public function __construct(private InstanceSettingsInterface $settings, private string $fallbackDsn) {}

    public function dsn(): string
    {
        return $this->settings->getSecret('mailer.dsn') ?? $this->fallbackDsn;   // env fallback when nothing is stored
    }
}
```

### Reacting to changes

`Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent` is dispatched after a save that changed something (admin form, import, or code). It carries `section`, `changedFields`, `secretFields` and `origin` (`form`, `import`, `api`) — never values.

```php
#[AsEventListener]
public function onSettingsChanged(SettingsChangedEvent $event): void
{
    if ($event->section === 'mailer') {
        $this->transportCache->reset();     // e.g. drop a transport built from the old DSN
    }
}
```

Use the event for audit logs too (field names only).

### Rules for bundle authors

1. **Own your section name** and namespace your translation domain (`NowoMailKitBundle`); never write into another bundle's section.
2. **Do not depend on another kit**: a mailer bundle and an SMS bundle each depend on `nowo-tech/instance-settings-bundle` only.
3. **Secrets only through `SettingField::secret()`** and `getSecret()`; never log or render them, never put them in non-secret fields.
4. **Defaults are your public contract**; changing a default changes the value of every instance that never saved one.
5. **Optional dependency**: a bundle that wants to work without this bundle registers its section only when `interface_exists(SettingsSectionInterface::class)` and falls back to its own configuration (env / YAML) otherwise.
6. **Renaming a field or a section** orphans stored rows (they are ignored); ship a migration that renames the rows (`UPDATE instance_setting_value SET name = 'new' WHERE section = 'x' AND name = 'old'`).

### Example: mailer section (MailKit)

```php
final class MailerSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface
{
    public function getName(): string { return 'mailer'; }
    public function getTranslationDomain(): string { return 'NowoMailKitBundle'; }
    public function getIntro(): ?string { return 'settings.mailer.intro'; }

    public function getFields(): iterable
    {
        yield SettingField::secret('dsn', 'settings.mailer.dsn', 'settings.mailer.dsn_help');           // smtp://… / resend+api://…
        yield SettingField::email('from', 'noreply@example.test', 'settings.mailer.from', required: true);
        yield SettingField::string('from_name', null, 'settings.mailer.from_name');
        yield SettingField::email('test_recipient', null, 'settings.mailer.test_recipient');
    }

    public function getActions(SectionActionContext $context): iterable { /* POST "Send a test e-mail" with a named CSRF FormView */ }
}
```

The kit reads `getSecret('mailer.dsn')` (falling back to `MAILER_DSN`) in a `MailerInterface` decorator, validates DSNs with a field constraint, and resets its transport on `SettingsChangedEvent` for `mailer`.

### Example: SMS provider section (SmsKit)

```php
final class SmsSettingsSection extends AbstractSettingsSection
{
    public function getName(): string { return 'sms'; }
    public function getTranslationDomain(): string { return 'NowoSmsKitBundle'; }

    public function getFields(): iterable
    {
        yield SettingField::choice('provider', ['settings.sms.null' => 'null', 'settings.sms.twilio' => 'twilio', 'settings.sms.bridge' => 'sms_bridge'], 'null', 'settings.sms.provider', required: true);
        yield SettingField::string('sender', null, 'settings.sms.sender');
        yield SettingField::string('account_sid', null, 'settings.sms.account_sid');
        yield SettingField::secret('auth_token', 'settings.sms.auth_token');
        yield SettingField::url('bridge_url', null, 'settings.sms.bridge_url');
        yield SettingField::secret('bridge_api_key', 'settings.sms.bridge_api_key');
    }
}
```

The provider registry of the kit picks the provider from `getString('sms.provider')` and the credentials from `getSecret()`. Provider credentials travel between instances only sealed (see [Secrets](#secrets)).

### Example: settings-backed Mercure hub

A Mercure hub configured from the admin UI is one more section; the publisher reads it per request with an env fallback:

```php
yield SettingField::bool('enabled', false, 'settings.mercure.enabled');
yield SettingField::secret('url', 'settings.mercure.url', type: SettingType::Url);          // internal publish URL
yield SettingField::secret('public_url', 'settings.mercure.public_url', type: SettingType::Url);
yield SettingField::secret('jwt_secret', 'settings.mercure.jwt_secret');
```

The hub factory builds a `Hub` from `getSecret('mercure.url') ?? $_ENV['MERCURE_URL']`, guards the URL against SSRF (private ranges refused unless allowed) before publishing, and is reset on `SettingsChangedEvent` for `mercure`. The bundle does not ship this factory: it belongs to a Mercure kit (or the host).

## Reading settings

```php
$settings->get('site.page_size');        // bool|float|int|string|null — stored value, else default
$settings->getString('mailer.from');     // ?string (LogicException for another type)
$settings->getInt('site.page_size');     // ?int
$settings->getFloat('shop.vat_rate');    // ?float (ints widen)
$settings->getBool('site.maintenance');  // bool (null = false)
$settings->getSection('site');           // ['name' => …, 'page_size' => …] — secrets left out
$settings->isOverridden('site.name');    // a value is stored (not the default)
$settings->getSecret('mailer.dsn');      // ?string, decrypted
$settings->hasSecret('mailer.dsn');      // bool
```

Unknown keys throw `UnknownSettingException`; `get()` on a secret field and `getSecret()` on a plain field throw `LogicException`. A stored value whose type no longer matches the field (the field type changed in code) is ignored and the default applies.

Twig: `{{ instance_setting('site.name') }}` (plain fields only; secrets are refused).

**Caching.** The first read of a request loads every row with **one** query; the map is memoized for the current main request (a new main request always reloads, even without `kernel.reset`), cleared by `kernel.reset` (Messenger workers reset between messages) and after every save. There is no cross-request cache: a change made in one worker is seen by every worker on its next request.

## Writing settings from code

```php
$changed = $settings->save('site', ['name' => 'Acme Ops', 'page_size' => '40', 'footer' => null]);
// ['name', 'page_size', 'footer'] — '40' normalized to 40; null removed the stored footer (default applies again)
```

`save()` validates every value first (types, `required`, built-in e-mail / URL / choice checks, field constraints) and throws `InvalidSettingValueException` (`violations`: field => messages) without writing anything when one fails. Then it upserts the changed rows with one flush, clears the memo, logs the section and field names and dispatches `SettingsChangedEvent`. Unchanged values are not written.

For **secret** fields: a non-empty string replaces the secret, `null` or `''` removes it, a missing key keeps it.

## Secrets

- Stored in `instance_setting_value.secret_value`, an `#[Encrypted]` column: DoctrineEncryptBundle encrypts it on flush and decrypts it on load with its **default profile** (Halite by default). The bundle refuses to compile when `NowoDoctrineEncryptBundle` is not registered.
- Key rotation and bulk encryption use the DoctrineEncrypt commands (`doctrine:encrypt:rotate-keys`, `doctrine:encrypt:status`); the settings table is covered like any `#[Encrypted]` entity.
- The admin form never receives the secret: it only knows whether one is stored ("Saved: ••••. Leave blank to keep it."), a blank field keeps it, the "Remove the saved value" checkbox clears it.
- Exports contain secrets only on request and only **sealed** (`enc:v2:<base64>` of the DoctrineEncrypt ciphertext of the value bound to `<schema>|<section>.<field>`): an instance holding the same key imports them into the same field, any other instance refuses the file (`failed_unseal_secret`) and a value moved to another field is refused (`secret_context_mismatch`). Set `portability.sealed_secrets: false` to keep secrets on the instance.

## Export and import (config portability)

### Envelope

```json
{
    "schema": "nowo-instance-settings",
    "version": 1,
    "exported_at": "2026-10-31T10:00:00+00:00",
    "settings": {"site": {"name": "Acme Ops", "page_size": 25}, "mailer": {"from": "ops@example.test"}},
    "secrets": {"sealed": true, "algorithm": "doctrine_encrypt:default", "values": {"mailer": {"dsn": "enc:v2:…"}}},
    "providers": {"appearance": {"accent": "#0d6efd"}}
}
```

- **Export**: the effective value (stored or default) of every portable plain field, the providers' data, and — only when asked — the stored secrets, sealed. Non-portable fields are left out. From the admin page, only the sections the user may open are exported (CLI: everything); a provider whose data contains a credential-shaped key makes the export fail (`forbidden_key`).
- **Import**: the file is read with a size cap (`portability.max_upload_bytes`), then validated **as a whole** — schema (`portability.schema`), version, known top-level keys, known sections and fields, from the admin page only sections the user may open (`forbidden_section`), portable fields only, secrets only under `secrets` and only sealed with this instance key, value types and constraints, forbidden sections / providers refused from the raw keys first (admin page), credential-shaped keys refused in provider data (keys normalised from camelCase / `-` / `.`; words such as password, passphrase, secret, token, bearer, credential, apikey, privatekey, signature anywhere, or parts such as pwd, pass, key, dsn, auth, jwt, mfa, seed, webhook, holding strings or structures — a heuristic safety net, not a guarantee), provider validation. Then everything is applied in **one transaction**. A refused file changes nothing; the error names the key (`invalid_value:site.page_size`), never the value.
- **Dry run** (checkbox / `--dry-run`): validation only, lists the keys that would be applied.

### CLI

```bash
php bin/console nowo:instance-settings:export                                  # stdout
php bin/console nowo:instance-settings:export -s site -s mailer -o var/site.json
php bin/console nowo:instance-settings:export --include-secrets -o var/full.json   # sealed; new file, mode 0600
php bin/console nowo:instance-settings:export -o var/full.json --force           # replace an existing regular file (never a symlink)
php bin/console nowo:instance-settings:import var/full.json --dry-run
php bin/console nowo:instance-settings:import var/full.json
```

Both commands exit non-zero with the reason code on a refusal. The CLI is an operator tool: it is not restricted by `area_roles`.

### Portability providers

Data that lives elsewhere (appearance, domain presets…) joins the envelope through a service implementing `PortabilityProviderInterface` (autoconfigured, tag `nowo_instance_settings.portability_provider`):

```php
final class AppearancePortability implements PortabilityProviderInterface
{
    public function getName(): string { return 'appearance'; }        // unique, not a section name
    public function export(): array { return ['accent' => $this->repo->get()->getAccent()]; }   // no secrets
    public function validate(array $data): void { /* throw new PortabilityException('invalid_value:appearance.accent') */ }
    public function import(array $data): array { /* apply; runs inside the import transaction */ return array_keys($data); }
}
```

## Admin UI

Routes (prefix `web_ui.path_prefix`, default `/admin/settings`):

| Route | Path | Methods | Page |
| --- | --- | --- | --- |
| `nowo_instance_settings_index` | `` | GET | redirects to the first section the user may open (empty state without sections) |
| `nowo_instance_settings_section` | `/sections/{section}` | GET, POST | section form |
| `nowo_instance_settings_portability` | `/portability` | GET | export + import forms |
| `nowo_instance_settings_export` | `/portability/export` | POST | JSON download (`no-store`) |
| `nowo_instance_settings_import` | `/portability/import` | POST | upload |

The routes exist only through the `nowo_instance_settings` route loader import (REQ-SF-004 rule 7): a host `routing.controllers` import never mounts them. Pages extend the UiKit settings scaffold (one host theme for every Nowo bundle — [docs/TEMPLATES.md](TEMPLATES.md)); set `nowo_ui_kit.layouts.admin` (or `web_ui.layout_template`) to your layout.

### Access

Two layers (REQ-UI-002): the host firewall on the prefix, and the bundle checker on every request under the prefix and on every bundle controller wherever it is mounted. The default checker grants a user holding any role of `security.access_roles` (`ROLE_ADMIN`); `security.area_roles.<section>` replaces the list for one section and `security.area_roles._portability` for the import / export page (tabs the user may not open are hidden). An empty list denies. Anonymous users inside a firewall get the firewall entry point (401 / login), others 403. Replace the checker with `security.access_checker: App\Security\SettingsAccessChecker` (implements `InstanceSettingsAccessCheckerInterface::canAccess(?object $user, ?string $area)`).

### Forms (FormKit)

Every form is a FormKit type (`SettingsSectionType`, `PortabilityExportType`, `PortabilityImportType` — `#[FormKitConfig('instance_settings')]`) rendered by the UiKit scaffold with `form_start` / the REQ-TWIG-003 child loop / `form_end`; CSRF on every POST (`nowo_instance_settings_section_<name>`, `nowo_instance_settings_export`, `nowo_instance_settings_import`). Hosts and demos must not reintroduce raw `<form>` / `<input>` markup when overriding templates. The FormKit profile `nowo_form_kit.profiles.instance_settings` is prepended by the bundle; define it yourself to change it ([CONFIGURATION](CONFIGURATION.md#formkit-profile)).

### Overriding templates (REQ-TWIG-001)

Preferred, upgrade-safe customisation: `nowo_ui_kit.layouts.admin` / `web_ui.layout_template` (your layout), the UiKit theme (`nowo_ui_kit.theme` classes / tokens), or `web_ui.templates.<page>` pointing at a thin template that `{% extends '@!NowoInstanceSettingsBundle/settings/section.html.twig' %}` and overrides one block.

Full-file overrides remain possible: create `templates/bundles/NowoInstanceSettingsBundle/<subpath>` — it always wins over the bundle file.

**Freeze rule:** an application file at the override path always wins and will not pick up vendor changes for that path until removed or merged. To recover upstream after a freeze, delete (or rename aside) the app override, clear the cache (`php bin/console cache:clear`), and re-apply only surgical customisations; diff against `vendor/nowo-tech/instance-settings-bundle/src/Resources/views/` first.

Procedure: 1) pick the `<subpath>` below; 2) create `templates/bundles/NowoInstanceSettingsBundle/<subpath>`; 3) clear the cache; 4) treat the file as a fork.

| `<subpath>` | Purpose |
| --- | --- |
| `settings/section.html.twig` | Section page (and the empty state): extends `@NowoUiKit/page/settings.html.twig` |
| `settings/portability.html.twig` | Import / export page: extends `@NowoUiKit/page/settings.html.twig` |

The override directory is registered (when present) before the bundle views by `TwigPathsPass`; the namespace is always `@NowoInstanceSettingsBundle`.

## Translations

UI copy uses the domain **`NowoInstanceSettingsBundle`** (catalogues `en`, `es`, `fr`, `de`, `it`, `pt`, `nl` under `src/Resources/translations/`). Override any key in your app: `translations/NowoInstanceSettingsBundle.<locale>.yaml`

```yaml
settings:
    title: 'Instance configuration'
portability:
    export:
        submit: 'Download'
```

Section and field labels use each section's own domain. The secret widget copy ("New value", "Remove the saved value") comes from `NowoDoctrineEncryptBundle`.

## Doctrine schema and migrations

The bundle maps one entity, `Nowo\InstanceSettingsBundle\Entity\SettingValue` → table `<prefix>instance_setting_value` (`id`, `section`, `name`, `value` JSON, `secret_value` TEXT, `updated_at`, unique `(section, name)`). The mapping is prepended to `doctrine.orm.mappings` automatically.

Create and apply the schema with Doctrine Migrations in the application:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

`doctrine:schema:update --force` is fine for local demos only. After changing `doctrine.table_prefix`, generate a migration that renames the table and its unique index. The bundle never changes the schema at runtime.
