# Usage

## Table of contents

- [Contributing a settings section](#contributing-a-settings-section)
  - [Fields](#fields)
  - [Section metadata and order](#section-metadata-and-order)
  - [Extra actions next to the submit button](#extra-actions-next-to-the-submit-button)
  - [Section notices (warnings)](#section-notices-warnings)
  - [Provider-dependent fields (visible_when)](#provider-dependent-fields-visible_when)
  - [Reading the values in the contributing bundle](#reading-the-values-in-the-contributing-bundle)
  - [Reacting to changes](#reacting-to-changes)
  - [Rules for bundle authors](#rules-for-bundle-authors)
  - [Example: mailer section (MailKit)](#example-mailer-section-mailkit)
  - [Example: SMS provider section (SmsKit)](#example-sms-provider-section-smskit)
  - [Example: settings-backed Mercure hub](#example-settings-backed-mercure-hub)
- [Reading settings](#reading-settings)
- [Writing settings from code](#writing-settings-from-code)
- [Secrets](#secrets)
- [Image fields](#image-fields)
- [Seeding from fallbacks (DB-only mode)](#seeding-from-fallbacks-db-only-mode)
  - [Calling the seeder from a migration](#calling-the-seeder-from-a-migration)
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
| `getIntro()` | `null` | text under the card title: a key of the section domain, a UiKit `Label`, or a `TranslatableMessage` with parameters (`new TranslatableMessage('mailer.intro', ['%host%' => $host])`, domain defaults to the section's) |
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

### Section notices (warnings)

Since 1.2.0 a section may implement `SettingsSectionNoticesInterface::getNotices(SectionActionContext $context)` and
return translation keys of its domain, UiKit `Label`s or `TranslatableMessage`s. The section page renders each one as a
UiKit `flash_warning` alert (`role="alert"`, `data-testid="instance-settings-notice"`) above the form. Typical use: "the
selected provider is missing its API key". Notices are rendered in the page: name the fields, **never** echo a stored
value.

### Provider-dependent fields (visible_when)

Since 1.3.0 a field can depend on another field of the same section (typically a provider choice):

```php
yield SettingField::choice('provider', ['provider.smtp' => 'smtp', 'provider.api' => 'api']);
yield SettingField::string('smtp_host', null, 'smtp_host', required: true)->withVisibleWhen('provider', ['smtp']);
yield SettingField::secret('api_key', 'api_key')->withVisibleWhen('provider', ['api']);
yield SettingField::bool('tracking');
yield SettingField::string('tracking_domain')->withVisibleWhen('tracking', [true]);
// same option as an array: new SettingField('smtp_host', visibleWhen: ['field' => 'provider', 'in' => ['smtp']])
```

- Shape: `{field: '<controlling field name>', in: [values]}`. Values compare as strings (`true` → `'1'`, `false` →
  `''`). The controlling field must exist in the section, not be secret and not be provider-dependent itself; with a
  choice (bool) controller every value must be one of its values. Violations throw a `LogicException` when the
  sections load (first use of the registry).
- Rendering: the row gets the UiKit 2.23 `visible-when` attributes through `row_attr` (merged with a `row_attr` from
  `withFormOptions()`), the page root gets `data-controller="visible-when"`. Register the UiKit `visible-when`
  Stimulus peer in the host app; the peer hides rows (`hidden`, `inert`, `aria-hidden`) and never disables or clears
  inputs. Without JavaScript every row stays visible.
- Saving (admin form): only the fields visible for the **submitted** controlling value are validated and written. A
  hidden field keeps its stored row (also when submitted empty); its submitted value is replaced by its current value
  before validation, so a hidden row never shows an error. "Empty = delete" applies to visible fields only; secrets
  keep "blank = keep". One exception: checking "clear" on a hidden secret deletes its stored row (a value typed in it
  is never written), to remove an inactive provider's credential without activating it. The controlling value is
  normalized like the form type does (trim, then its transformers: checkbox → bool, integer → int) on both sides.
  Without JavaScript, edit a provider's fields after selecting it.
- Notices: compute required-field notices for the active provider only (`SettingField::isVisibleFor()` with the
  stored values).
- `InstanceSettings::save()` (API), imports, exports and the seed store every given field; `required` is not enforced
  on a field hidden for the normalized saved values merged over the stored ones.

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

## Image fields

Since 1.4 a field can hold a raster image (logo, icon, mascot): `SettingField::image()`.

```php
yield SettingField::image('logo', 'brand.logo', maxWidth: 2400, maxHeight: 800, public: true);
yield SettingField::image('app_icon', 'brand.app_icon', minWidth: 512, minHeight: 512, square: true, variants: [512, 192, 180], public: true);
yield SettingField::image('id_scan', 'kyc.scan', types: ['image/jpeg'], maxBytes: 500_000); // private: section area only
```

| Argument | Default | Meaning |
| --- | --- | --- |
| `types` | PNG, WebP, JPEG | Accepted types, a subset of those three. SVG, GIF, ICO, BMP, TIFF, AVIF / HEIC and PDF are never accepted. |
| `maxBytes` | 1 MiB | Largest upload (ceiling 5 MiB). |
| `maxWidth` / `maxHeight` | 4096 | Largest dimensions (after the JPEG EXIF orientation). Every image is also capped at 16 MP. |
| `minWidth` / `minHeight` | 1 | Smallest dimensions. |
| `square` | `false` | Width and height within 2 % (nothing is cropped or padded). |
| `variants` | `[]` | Longest side of each PNG variant generated on upload (icons), never upscaled; at most 8. |
| `public` | `false` | Served to anonymous visitors (brand assets). Otherwise only users of the section's area may load it. |
| `required` | `false` | A stored image is required. |

Image fields cannot be secret, cannot declare a default (config default assets come with the `brand` section) and
cannot use or control `visible_when`. They need `ext-gd` with PNG, JPEG and WebP support.

**What happens to an upload.** The type is decided only from the content (magic bytes and `getimagesizefromstring()`
must agree; the client name and type are ignored), the dimensions are read from the header before anything is decoded
(pixel cap, field limits, memory budget), then the image is decoded and **re-encoded** with GD: PNG → PNG (alpha
kept), JPEG → JPEG q90 turned upright from its EXIF orientation, still WebP → WebP q90. EXIF / GPS / XMP / ICC and
anything appended to the file are dropped. Animated WebP is refused; an APNG keeps one frame.

**Storage.** The re-encoded file and its variants go to the table `<prefix>instance_setting_file`, bound to their
`(section, field)` and content-addressed by the SHA-256 of the re-encoded file; the setting stores the reference
`isf:<sha256>`. The same image uploaded twice is one row. `SettingFileStoreInterface` is the seam for another store.

**Reading.**

```php
$file = $settings->getFile('brand.logo');   // ?StoredSettingFile; get() / getString() return null for image fields
$file?->url();                               // /_settings-file/brand/logo/3f1c9a0b7e21.png
$file?->url(192);                            // PNG variant, or null when the field does not declare it
$file?->mimeType; $file?->width; $file?->height; $file?->byteSize; $file?->sha256;
```

```twig
{% set logo = instance_setting_file('brand.logo') %}
{% if logo %}<img src="{{ logo.url }}" width="{{ logo.width }}" height="{{ logo.height }}" alt="">{% endif %}
```

URLs are immutable (they carry the hash), so a new upload gets a new URL. Never inline the bytes of an upload into
HTML, SVG or PDF markup; use the URL, a `data:` URI of the raster or a CID attachment.

**Writing.**

- Admin form: a file input per image field (and "Remove the stored image" when one is stored), inside the section's
  CSRF form; the section's area role is required. An empty file input keeps the stored image.
- Code: `$settings->save('brand', ['logo' => ImageUpload::fromBytes($bytes)])` (or `ImageUpload::fromPath()`, or an
  `UploadedFile`); `null` removes the image; the current `isf:` reference keeps it. Any other value is refused: a field
  can only point to an image uploaded or imported into it.

**Serving.** Route `nowo_instance_settings_file`, `{files.path_prefix}/{section}/{field}/{hash12}[-{variant}].{ext}`,
`GET` / `HEAD`, outside the admin prefix, added only when a section declares an image field. Headers: stored type,
`X-Content-Type-Options: nosniff`, `Content-Disposition: inline`, `Content-Security-Policy: default-src 'none';
sandbox`, `ETag` (`If-None-Match` → 304 without reading the bytes), `Referrer-Policy: no-referrer`,
`X-Robots-Tag: noindex`; public fields `public, max-age=31536000, immutable` + `Cross-Origin-Resource-Policy:
cross-origin`, private fields `private, max-age=3600` + `same-origin`. An unknown section, field, hash, variant or
extension, and a private image the viewer may not open, all answer the same empty 404. A replaced image keeps
answering until it is purged.

**Replacing, removing, purging.** A replaced or removed image is marked orphaned (not deleted) in the same
transaction. Schedule the purge (daily is plenty):

```bash
php bin/console nowo:instance-settings:purge-files --dry-run
php bin/console nowo:instance-settings:purge-files
```

It first marks images that no live setting references (a field removed from code, a row deleted by hand), then
deletes those orphaned for `files.orphan_retention` days or more.

**Audit.** The "Instance settings saved." log line gets `files`: `logo=3f1c9a0b7e21 image/png 512x512 20480B` (or
`logo=removed`); `SettingsChangedEvent::$fileChanges` gives `field => {old, new}` full hashes. Never content.

## Seeding from fallbacks (DB-only mode)

Kits used to read bundle configuration (`%env(...)%` / YAML) while a field had nothing stored. Since 1.2.0 a section can
expose those values with `SectionFallbackProviderInterface::getFallbacks()` (`SettingFallback::plain('sender', $x)`,
`SettingFallback::secret('api_key', $y)`), and the host copies them **once** into the store:

```bash
php bin/console nowo:instance-settings:seed-from-fallbacks --dry-run   # what would be seeded (names only)
php bin/console nowo:instance-settings:seed-from-fallbacks             # seed every section exposing fallbacks
php bin/console nowo:instance-settings:seed-from-fallbacks --section=sms --strict
```

- Only fields **without a stored value** are written, insert-only: a stored value (set in the admin UI or by an
  earlier seed) is never overwritten, even when an admin saves the field while the seed runs (the seed re-checks
  inside its transaction and retries once on a unique-key race). A second run is a no-op.
- **Seed once, then remove the fallbacks.** Clearing a field in the admin UI deletes its row, so running the seed
  again while the fallbacks are still wired would write the fallback back (e.g. a key an admin blanked on purpose).
  Do not run it on every deploy; run it for the migration and drop the `%env()%` / YAML fallbacks right after.
- Empty fallbacks (null, blank) are skipped. A fallback for an unknown field, a fallback whose secret flag differs from
  the field, an invalid value or a second fallback for the same field is skipped with a reason code; `--strict` exits 1
  when that happens.
- Same field validation as a save, secrets encrypted at rest, the `Instance settings saved.` log line and a
  `SettingsChangedEvent` with origin `seed` (`SettingsChangedEvent::ORIGIN_SEED`) per seeded section. Everything is
  written in one transaction and the events are dispatched **after the commit** (a rolled-back seed dispatches none).
- The output, the `SeedReport` and the log carry `<section>.<field>` names and reason codes only, never a value.
- From PHP (host upgrade step): `$container->get(FallbackSeeder::class)->seed(['sms'], dryRun: false)` (public service)
  returns a `SeedReport`.

**Order:** seed while the `%env()%` fallbacks are still wired, check the report, and drop the env vars / YAML values
only afterwards. Once dropped, the instance runs in **DB-only mode**: the admin UI is the only source of those settings.

### Calling the seeder from a migration

On MySQL / MariaDB every DDL statement commits implicitly. In a **transactional** Doctrine migration that already ran DDL
(or after earlier DDL migrations in the same `migrate` run), Doctrine Migrations skips its own commit but DBAL keeps a
transaction nesting level although the server has none. The seeder's transaction then fails with
`SAVEPOINT DOCTRINE_n does not exist`, or worse writes outside any transaction.

Call the seeder only from a **non-transactional** migration:

```php
final class Version20261102000000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SELECT 1'); // data-only migration
    }

    public function postUp(Schema $schema): void
    {
        $this->container->get(FallbackSeeder::class)->seed(); // or inject it through a migration factory
    }
}
```

or set `doctrine_migrations.transactional: false` globally. As a safety net the seeder checks the connection before
writing (`TransactionStateGuard`): when DBAL reports an open transaction but the PDO connection is not in one, it
throws a `LogicException` explaining the above instead of corrupting the state. The check is best effort (PDO drivers
only); a dry run never writes and is not checked.

## Export and import (config portability)

### Envelope

```json
{
    "schema": "nowo-instance-settings",
    "version": 2,
    "exported_at": "2026-10-31T10:00:00+00:00",
    "settings": {"site": {"name": "Acme Ops", "page_size": 25}, "mailer": {"from": "ops@example.test"}, "brand": {"logo": "isf:3f1c…"}},
    "secrets": {"sealed": true, "algorithm": "doctrine_encrypt:default", "values": {"mailer": {"dsn": "enc:v2:…"}}},
    "providers": {"appearance": {"accent": "#0d6efd"}},
    "files": {"brand.logo": {"type": "image/png", "sha256": "3f1c…", "data": "<base64>"}}
}
```

- **Version.** An export that contains no image field is still version 1 (any 1.x imports it). With image fields it is
  version 2: `settings` carries each image field's `isf:<sha256>` reference (or `null`) and, when asked (UI checkbox,
  checked by default; CLI `--include-files`), `files` carries the stored bytes in base64. 1.4 imports both versions; a
  1.3 instance refuses version 2 (`unsupported_version`).
- **Images on import.** A reference with its `files` entry is decoded strictly (base64), size-capped (the field's
  `maxBytes`, and `portability.max_file_bytes` for all files together), checked against its declared hash (the bytes
  as transferred) and type, then run through the whole upload pipeline (sniffing, limits, re-encode); the stored hash
  is the one of the re-encoded bytes (a JPEG gets a new one). A reference equal to the current one needs no file;
  `null` removes the image. Refusals: `invalid_files`, `invalid_file:files.<section>.<field>`, `file_too_large:…`,
  `files_too_large`, `file_hash_mismatch:…`, `missing_image_file:…` (reference without its file),
  `unexpected_file:…` (file without its reference). All-or-nothing, as for every other key.

- **Export**: the effective value (stored or default) of every portable plain field, the providers' data, and — only when asked — the stored secrets, sealed. Non-portable fields are left out. From the admin page, only the sections the user may open are exported (CLI: everything); a provider whose data contains a credential-shaped key makes the export fail (`forbidden_key`).
- **Import**: the file is read with a size cap (`portability.max_upload_bytes`), then validated **as a whole** — schema (`portability.schema`), version, known top-level keys, known sections and fields, from the admin page only sections the user may open (`forbidden_section`), portable fields only, secrets only under `secrets` and only sealed with this instance key, value types and constraints, forbidden sections / providers refused from the raw keys first (admin page), credential-shaped keys refused in provider data (keys normalised from camelCase / `-` / `.`; words such as password, passphrase, secret, token, bearer, credential, apikey, privatekey, signature anywhere, or parts such as pwd, pass, key, dsn, auth, jwt, mfa, seed, webhook, holding strings or structures — a heuristic safety net, not a guarantee), provider validation. Then everything is applied in **one transaction**. A refused file changes nothing; the error names the key (`invalid_value:site.page_size`), never the value.
- **Dry run** (checkbox / `--dry-run`): validation only, lists the keys that would be applied.

### CLI

```bash
php bin/console nowo:instance-settings:export                                  # stdout
php bin/console nowo:instance-settings:export -s site -s mailer -o var/site.json
php bin/console nowo:instance-settings:export --include-secrets -o var/full.json   # sealed; new file, mode 0600
php bin/console nowo:instance-settings:export --include-files -o var/brand.json   # with the images of image fields (base64)
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

The bundle maps two entities (the mapping is prepended to `doctrine.orm.mappings` automatically):

- `Nowo\InstanceSettingsBundle\Entity\SettingValue` → table `<prefix>instance_setting_value` (`id`, `section`, `name`, `value` JSON, `secret_value` TEXT, `updated_at`, unique `(section, name)`).
- `Nowo\InstanceSettingsBundle\Entity\SettingFile` (1.4, image fields) → table `<prefix>instance_setting_file` (`id`, `section`, `name`, `sha256` CHAR(64), `variant`, `mime_type`, `width`, `height`, `byte_size`, `content` BLOB / BYTEA, `created_at`, `orphaned_at`, unique `(section, name, sha256, variant)`, index `orphaned_at`). Created even without image fields (it stays empty); see [UPGRADING](UPGRADING.md#unreleased) for the DDL.

Create and apply the schema with Doctrine Migrations in the application:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

`doctrine:schema:update --force` is fine for local demos only. After changing `doctrine.table_prefix`, generate a migration that renames the table and its unique index. The bundle never changes the schema at runtime.
