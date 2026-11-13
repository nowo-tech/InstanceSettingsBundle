<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Form\SettingColorType;
use Nowo\InstanceSettingsBundle\Form\SettingImageCrop;
use Nowo\InstanceSettingsBundle\Form\ThemeCardsType;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\Validator\Constraint;

use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;

/**
 * One field of a settings section: name, type, default, labels, validation and the secret / portable flags.
 *
 * Immutable and free of services: a section builds its fields on every {@see SettingsSectionInterface::getFields()} call
 * and the registry caches them per worker. Use the named constructors ({@see self::string()}, {@see self::secret()}, …).
 */
final readonly class SettingField
{
    /** Allowed field names: lower snake case, 1–64 characters. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Prefix of a stored image reference (`isf:<sha256>`, 1.4). */
    public const IMAGE_REFERENCE_PREFIX = 'isf:';

    /** A stored image reference: `isf:` + the lower-case hex SHA-256 of the re-encoded file. */
    public const IMAGE_REFERENCE_PATTERN = '/^isf:[0-9a-f]{64}$/';

    /** A colour value of {@see SettingType::Color} (1.12): `#rrggbb`, any case (stored lower-cased). */
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/D';

    /** Allowed form groups (1.5): dot-separated lower snake case segments, e.g. `colors.light`. */
    public const GROUP_PATTERN = '/^[a-z][a-z0-9_]{0,63}(?:\.[a-z][a-z0-9_]{0,63}){0,3}$/';

    /** @var list<Constraint> */
    public array $constraints;

    /**
     * Provider-dependent visibility (1.3): `{field: <controlling field of the same section>, in: <values as strings>}`,
     * or null when the field is always shown. See {@see self::withVisibleWhen()}.
     *
     * @var array{field: string, in: list<string>}|null
     */
    public ?array $visibleWhen;

    /** Upload rules of an {@see SettingType::Image} field (1.4); null for every other type. */
    public ?ImageSpec $image;

    /** Form group of the field (1.5, {@see self::withGroup()}); null = no group. */
    public ?string $group;

    /**
     * @param string $name field name, unique inside its section ({@see self::NAME_PATTERN})
     * @param SettingType $type value type
     * @param bool|float|int|string|null $default effective value while nothing is stored (secrets: always null)
     * @param string|null $label translation key in the section domain (null = `<name>`)
     * @param string|null $help translation key of the help text in the section domain
     * @param bool $required a value (stored or default) is required; null / '' is refused
     * @param bool $secret encrypted at rest, never rendered back, never exported in clear
     * @param bool $portable included in exports and accepted on imports (secrets only sealed)
     * @param iterable<Constraint> $constraints extra Symfony Validator constraints applied on save and import
     * @param array<string, int|string> $choices label key => value, for {@see SettingType::Choice}
     * @param array<string, mixed> $formOptions extra options merged into the admin form field
     * @param array{field?: mixed, in?: mixed}|null $visibleWhen `{field: '<name>', in: [...]}`: show the field only while
     *                                                           that field of the section holds one of the values (1.3)
     * @param ImageSpec|null $image upload rules of an image field (1.4; null = the defaults); only for {@see SettingType::Image}
     * @param string|null $group form group (1.5, {@see self::GROUP_PATTERN}); null = no group
     * @param bool $revealable 1.15: a stored secret may be read back through the reveal endpoint by a user holding the
     *                         reveal roles ({@see InstanceSettingsAccessCheckerInterface::AREA_REVEAL}). Only for secret
     *                         fields, default false: a secret is never revealable unless the host says so
     */
    public function __construct(
        public string $name,
        public SettingType $type = SettingType::String,
        public bool|float|int|string|null $default = null,
        public ?string $label = null,
        public ?string $help = null,
        public bool $required = false,
        public bool $secret = false,
        public bool $portable = true,
        iterable $constraints = [],
        public array $choices = [],
        public array $formOptions = [],
        ?array $visibleWhen = null,
        ?ImageSpec $image = null,
        ?string $group = null,
        public bool $revealable = false,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid setting field name "%s": expected lower snake case matching %s.', $name, self::NAME_PATTERN));
        }
        if ($secret && !$type->isString()) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": only string, text, email and url fields can be secret.', $name));
        }
        if ($secret && $default !== null) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": a secret field cannot declare a default value.', $name));
        }
        if ($revealable && !$secret) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": only a secret field can be revealable.', $name));
        }
        if ($type === SettingType::Choice && $choices === []) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": a choice field needs at least one choice.', $name));
        }
        if ($type === SettingType::Image && ($default !== null || $visibleWhen !== null)) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": an image field cannot declare a default value nor visible_when.', $name));
        }
        if ($type !== SettingType::Image && $image instanceof ImageSpec) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": image rules only apply to image fields.', $name));
        }
        if ($group !== null && preg_match(self::GROUP_PATTERN, $group) !== 1) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": invalid group "%s" (expected dot-separated lower snake case segments matching %s).', $name, $group, self::GROUP_PATTERN));
        }
        if ($default !== null && !self::matchesType($type, $default, $choices)) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": the default value does not match the %s type.', $name, $type->value));
        }

        $list = [];
        foreach ($constraints as $constraint) {
            $list[] = $constraint;
        }
        $this->constraints = $list;
        $this->visibleWhen = $visibleWhen === null ? null : $this->normalizeVisibleWhen($name, $visibleWhen);
        $this->image       = $type === SettingType::Image ? $image ?? new ImageSpec() : null;
        $this->group       = $group;
    }

    /**
     * Single-line string field.
     *
     * @param iterable<Constraint> $constraints
     */
    public static function string(string $name, ?string $default = null, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = []): self
    {
        return new self($name, SettingType::String, $default, $label, $help, $required, constraints: $constraints);
    }

    /**
     * Multi-line string field.
     *
     * @param iterable<Constraint> $constraints
     */
    public static function text(string $name, ?string $default = null, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = []): self
    {
        return new self($name, SettingType::Text, $default, $label, $help, $required, constraints: $constraints);
    }

    /**
     * E-mail address field.
     *
     * @param iterable<Constraint> $constraints
     */
    public static function email(string $name, ?string $default = null, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = []): self
    {
        return new self($name, SettingType::Email, $default, $label, $help, $required, constraints: $constraints);
    }

    /**
     * Absolute http(s) URL field.
     *
     * @param iterable<Constraint> $constraints e.g. a host allow-list constraint
     */
    public static function url(string $name, ?string $default = null, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = []): self
    {
        return new self($name, SettingType::Url, $default, $label, $help, $required, constraints: $constraints);
    }

    /**
     * Boolean switch (defaults to false).
     *
     * @param iterable<Constraint> $constraints
     */
    public static function bool(string $name, bool $default = false, ?string $label = null, ?string $help = null, iterable $constraints = []): self
    {
        return new self($name, SettingType::Bool, $default, $label, $help, constraints: $constraints);
    }

    /**
     * Integer field.
     *
     * @param iterable<Constraint> $constraints e.g. new Range(min: 1, max: 100)
     */
    public static function int(string $name, ?int $default = null, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = []): self
    {
        return new self($name, SettingType::Int, $default, $label, $help, $required, constraints: $constraints);
    }

    /**
     * Float field.
     *
     * @param iterable<Constraint> $constraints
     */
    public static function float(string $name, ?float $default = null, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = []): self
    {
        return new self($name, SettingType::Float, $default, $label, $help, $required, constraints: $constraints);
    }

    /**
     * One-of field.
     *
     * @param array<string, int|string> $choices label translation key => value
     * @param iterable<Constraint> $constraints
     */
    public static function choice(string $name, array $choices, int|string|null $default = null, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = []): self
    {
        return new self($name, SettingType::Choice, $default, $label, $help, $required, constraints: $constraints, choices: $choices);
    }

    /**
     * Colour field (1.12): `#rrggbb`, stored lower-cased; the admin form shows a native swatch paired with the hex text
     * input and a "Use the preset colour" reset ({@see SettingColorType}). Null (nothing stored) follows the preset
     * colour: `$default` here, or another field's value with {@see self::withPresetFrom()}. A colour equal to the
     * preset is saved as null, so a later change of the preset still reaches untouched instances.
     *
     * @param string|null $default preset colour `#rrggbb` (null = none: the swatch starts black while empty)
     * @param iterable<Constraint> $constraints extra constraints (the `#rrggbb` check is built in)
     */
    public static function color(string $name, ?string $default = null, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = []): self
    {
        return new self($name, SettingType::Color, $default === null ? null : strtolower($default), $label, $help, $required, constraints: $constraints);
    }

    /**
     * Encrypted secret (API key, password, DSN, token): never rendered back and exported only sealed.
     *
     * With `$revealable` (1.15) a user holding the reveal roles can read the stored secret back, one field per call,
     * through the reveal endpoint ({@see InstanceSettingsRoutes::REVEAL}); every reveal is audited. Default false: turn
     * it on for a secret the admin has to read somewhere else (an OAuth client secret), never for a key whose plaintext
     * nobody needs again (an encryption key, a webhook signing key). See `docs/SECURITY.md`.
     *
     * @param iterable<Constraint> $constraints
     * @param bool $revealable the stored secret may be read back through the reveal endpoint (1.15)
     */
    public static function secret(string $name, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = [], SettingType $type = SettingType::String, bool $revealable = false): self
    {
        return new self($name, $type, null, $label, $help, $required, secret: true, constraints: $constraints, revealable: $revealable);
    }

    /**
     * Raster image field (1.4): PNG, JPEG or still WebP, detected from the content, checked against the limits and
     * re-encoded with GD on every upload. The stored value is a reference `isf:<sha256>`; read the file with
     * {@see InstanceSettingsInterface::getFile()}. Requires `ext-gd` with PNG,
     * JPEG and WebP support (checked when the section definitions load).
     *
     * @param list<string> $types accepted types (subset of {@see ImageSpec::TYPES})
     * @param int $maxBytes largest accepted upload (ceiling {@see ImageSpec::MAX_BYTES_CEILING})
     * @param bool $square width and height within 2 %
     * @param list<int> $variants longest side of each PNG variant generated from the upload (icons)
     * @param bool $public served to anonymous visitors by the file route (brand assets)
     */
    public static function image(
        string $name,
        ?string $label = null,
        ?string $help = null,
        array $types = ImageSpec::TYPES,
        int $maxBytes = ImageSpec::DEFAULT_MAX_BYTES,
        int $maxWidth = ImageSpec::DEFAULT_MAX_DIMENSION,
        int $maxHeight = ImageSpec::DEFAULT_MAX_DIMENSION,
        int $minWidth = 1,
        int $minHeight = 1,
        bool $square = false,
        array $variants = [],
        bool $public = false,
        bool $required = false,
    ): self {
        return new self($name, SettingType::Image, null, $label, $help, $required, image: new ImageSpec($types, $maxBytes, $maxWidth, $maxHeight, $minWidth, $minHeight, $square, $variants, $public));
    }

    /**
     * Copy that is left out of exports and refused on imports (for flags that must never travel between instances).
     */
    public function notPortable(): self
    {
        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, false, $this->constraints, $this->choices, $this->formOptions, $this->visibleWhen, $this->image, $this->group, $this->revealable);
    }

    /**
     * Copy with extra admin form options (merged over the bundle defaults).
     *
     * @param array<string, mixed> $formOptions
     */
    public function withFormOptions(array $formOptions): self
    {
        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, $this->portable, $this->constraints, $this->choices, $formOptions + $this->formOptions, $this->visibleWhen, $this->image, $this->group, $this->revealable);
    }

    /**
     * Copy rendered as a theme-card picker (1.6, IS-2): a radio group of cards, each with a colour swatch, instead of a
     * select ({@see ThemeCardsType}). Only for {@see SettingType::Choice}; values, validation and storage do not change.
     *
     * @param array<int|string, list<string>> $swatches choice value => colours (`#rrggbb`; other values are dropped,
     *                                                  at most 8 per option); an option without colours shows its label only
     */
    public function withThemeCards(array $swatches = []): self
    {
        if ($this->type !== SettingType::Choice) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": theme cards are only for choice fields.', $this->name));
        }

        return $this->withFormOptions([ThemeCardsType::SWATCHES => ThemeCardsType::normalizeSwatches($swatches)]);
    }

    /**
     * Whether the admin form renders the field as a theme-card picker ({@see self::withThemeCards()}).
     */
    public function hasThemeCards(): bool
    {
        return $this->type === SettingType::Choice && array_key_exists(ThemeCardsType::SWATCHES, $this->formOptions);
    }

    /**
     * Copy whose preset colour follows another field of the same section (1.12): while nothing is stored, the colour is
     * the one `$colors` maps to the current value of `$field` (e.g. a theme preset choice), else the field default.
     * Only for {@see SettingType::Color}. The admin swatch follows a change of `$field` live (JavaScript), and a save
     * of the colour equal to the preset for the submitted (else the stored) value of `$field` stores null.
     *
     * @param string $field controlling field name (same section, not secret, not an image)
     * @param array<int|string, string> $colors value of `$field` (booleans as `'1'` / `''`) => preset colour `#rrggbb`
     */
    public function withPresetFrom(string $field, array $colors): self
    {
        if ($this->type !== SettingType::Color) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": a preset colour source is only for colour fields.', $this->name));
        }
        if (preg_match(self::NAME_PATTERN, $field) !== 1 || $field === $this->name) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": the preset colour source must be another field name of the same section.', $this->name));
        }
        if ($colors === []) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": the preset colour source needs at least one colour.', $this->name));
        }
        $map = [];
        foreach ($colors as $value => $color) {
            if (!is_string($color) || preg_match(self::COLOR_PATTERN, $color) !== 1) {
                throw new InvalidArgumentException(sprintf('Setting field "%s": preset colours are #rrggbb.', $this->name));
            }
            $map[(string) $value] = strtolower($color);
        }

        return $this->withFormOptions([SettingColorType::PRESET_FROM => ['field' => $field, 'colors' => $map]]);
    }

    /**
     * The preset colour source of a colour field ({@see self::withPresetFrom()}), or null.
     *
     * @return array{field: string, colors: array<string, string>}|null
     */
    public function presetFrom(): ?array
    {
        if ($this->type !== SettingType::Color) {
            return null;
        }
        $from = $this->formOptions[SettingColorType::PRESET_FROM] ?? null;
        if (!is_array($from) || !is_string($from['field'] ?? null) || !is_array($from['colors'] ?? null)) {
            return null;
        }
        $colors = [];
        foreach ($from['colors'] as $value => $color) {
            if (is_string($color) && preg_match(self::COLOR_PATTERN, $color) === 1) {
                $colors[(string) $value] = strtolower($color);
            }
        }

        return ['field' => $from['field'], 'colors' => $colors];
    }

    /**
     * Preset colour of a colour field for these section values (1.12): the {@see self::withPresetFrom()} colour of the
     * controlling value, else the default (lower-cased); null for other types or without a preset.
     *
     * @param array<string, mixed> $values section values (name => value)
     */
    public function presetColor(array $values): ?string
    {
        if ($this->type !== SettingType::Color) {
            return null;
        }
        $from = $this->presetFrom();
        if ($from !== null) {
            $color = $from['colors'][self::visibilityValue($values[$from['field']] ?? null)] ?? null;
            if ($color !== null) {
                return $color;
            }
        }

        return is_string($this->default) ? strtolower($this->default) : null;
    }

    /**
     * Copy with the crop step on its file input (1.8, owner decision D-20261109-09): a picked image opens UiKit's crop
     * dialog (2.43) and the crop is uploaded in its place. Only for {@see SettingType::Image}. The server is unchanged:
     * the upload is still checked against the field's rules and re-encoded.
     *
     * Options are UiKit's image crop options (`shape`, `aspect`, `max`, `min`, `format`, `max_bytes`, `quality`,
     * `bake_circle`, `preview`, `labels`) over the defaults derived from the field ({@see SettingImageCrop::derive()}:
     * a square field crops square at least its minimum size, `max_bytes` 95 % of its `maxBytes`, `format` `auto`).
     * They are checked now, against UiKit and the field: an option the field would refuse (a format it does not take,
     * `max_bytes` over its `maxBytes`, a non-square aspect on a square field, `max` / `min` outside its dimensions)
     * throws when the section definitions load. Wins over the bundle default `files.crop`.
     *
     * @param array<string, mixed> $options
     */
    public function withCrop(array $options = []): self
    {
        $spec = $this->imageSpec('crop');
        try {
            SettingImageCrop::resolve($spec, $options);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": %s', $this->name, $e->getMessage()), 0, $e);
        }

        return $this->withFormOptions([SettingImageCrop::OPTION => $options]);
    }

    /**
     * Copy without the crop step even when the bundle default `files.crop` turns it on (1.8). Only for image fields.
     */
    public function withoutCrop(): self
    {
        $this->imageSpec('crop');

        return $this->withFormOptions([SettingImageCrop::OPTION => false]);
    }

    /**
     * The field's own crop setting (1.8): null (the bundle default applies), false (off) or its options.
     *
     * @return array<string, mixed>|false|null
     */
    public function crop(): array|false|null
    {
        $crop = $this->formOptions[SettingImageCrop::OPTION] ?? null;

        return match (true) {
            is_array($crop), $crop === false => $crop,
            $crop === true                   => [],
            default                          => null,
        };
    }

    /**
     * Copy shown only while another field of the same section (the "provider") holds one of `$in` (1.3).
     *
     * The admin form hides the row (UiKit `visible-when` peer: hidden + inert, never disabled nor cleared) and the
     * save only validates and writes the field when it is visible for the submitted value of `$field`; a hidden
     * field keeps its stored row. Booleans compare as `'1'` (true) and `''` (false).
     *
     * @param string $field controlling field name (same section, not secret, not itself provider-dependent)
     * @param iterable<bool|int|string> $in values of the controlling field the field is shown for (at least one)
     */
    public function withVisibleWhen(string $field, iterable $in): self
    {
        $values = [];
        foreach ($in as $value) {
            $values[] = $value;
        }

        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, $this->portable, $this->constraints, $this->choices, $this->formOptions, ['field' => $field, 'in' => $values], $this->image, $this->group, $this->revealable);
    }

    /**
     * Copy placed in a form group (1.5): the admin form marks the row with `data-nowo-instance-settings-group="<group>"`
     * (rows keep the field order; keep the fields of a group together), so a host form theme or stylesheet can lay the
     * groups out as fieldsets or tabs. Groups never change storage, export or validation.
     *
     * @param string $group dot-separated lower snake case segments ({@see self::GROUP_PATTERN}), e.g. `colors.light`
     */
    public function withGroup(string $group): self
    {
        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, $this->portable, $this->constraints, $this->choices, $this->formOptions, $this->visibleWhen, $this->image, $group, $this->revealable);
    }

    /**
     * Upload rules of an image field, or a refusal naming `$feature` for any other type.
     */
    private function imageSpec(string $feature): ImageSpec
    {
        if (!$this->image instanceof ImageSpec) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": %s is only for image fields.', $this->name, $feature));
        }

        return $this->image;
    }

    /**
     * Whether the field is shown for these section values (name => value; the controlling field's value decides).
     * Always true without {@see self::$visibleWhen}.
     *
     * @param array<string, mixed> $values
     */
    public function isVisibleFor(array $values): bool
    {
        if ($this->visibleWhen === null) {
            return true;
        }

        return in_array(self::visibilityValue($values[$this->visibleWhen['field']] ?? null), $this->visibleWhen['in'], true);
    }

    /**
     * String form of a controlling value, as the browser sees it: true → '1', false / null → '', numbers as strings.
     */
    public static function visibilityValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Translation key of the field label (the field name when none was given).
     */
    public function labelKey(): string
    {
        return $this->label ?? $this->name;
    }

    /**
     * @param array<mixed> $option
     *
     * @return array{field: string, in: list<string>}
     */
    private function normalizeVisibleWhen(string $name, array $option): array
    {
        $keys = array_keys($option);
        if (count($keys) !== 2 || !array_key_exists('field', $option) || !array_key_exists('in', $option)) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": visible_when is {field: string, in: list<string|int|bool>}.', $name));
        }
        $field = $option['field'];
        if (!is_string($field) || preg_match(self::NAME_PATTERN, $field) !== 1) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": visible_when.field must be a field name of the same section.', $name));
        }
        if ($field === $name) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": visible_when.field cannot be the field itself.', $name));
        }
        if (!is_array($option['in']) || $option['in'] === []) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": visible_when.in needs at least one value.', $name));
        }
        $in = [];
        foreach ($option['in'] as $value) {
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new InvalidArgumentException(sprintf('Setting field "%s": visible_when.in values must be strings, integers or booleans.', $name));
            }
            $in[] = self::visibilityValue($value);
        }

        return ['field' => $field, 'in' => array_values(array_unique($in))];
    }

    /**
     * Whether a (non-null) value matches the PHP type of a setting type.
     *
     * @param array<string, int|string> $choices
     */
    public static function matchesType(SettingType $type, mixed $value, array $choices = []): bool
    {
        return match ($type) {
            SettingType::String, SettingType::Text, SettingType::Email, SettingType::Url => is_string($value),
            SettingType::Bool                                                            => is_bool($value),
            SettingType::Int                                                             => is_int($value),
            SettingType::Float                                                           => is_float($value) || is_int($value),
            SettingType::Choice                                                          => (is_string($value) || is_int($value)) && in_array($value, $choices, true),
            SettingType::Image                                                           => is_string($value) && preg_match(self::IMAGE_REFERENCE_PATTERN, $value) === 1,
            SettingType::Color                                                           => is_string($value) && preg_match(self::COLOR_PATTERN, $value) === 1,
        };
    }
}
