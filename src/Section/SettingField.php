<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use InvalidArgumentException;
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

    /** @var list<Constraint> */
    public array $constraints;

    /**
     * Provider-dependent visibility (1.3): `{field: <controlling field of the same section>, in: <values as strings>}`,
     * or null when the field is always shown. See {@see self::withVisibleWhen()}.
     *
     * @var array{field: string, in: list<string>}|null
     */
    public ?array $visibleWhen;

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
        if ($type === SettingType::Choice && $choices === []) {
            throw new InvalidArgumentException(sprintf('Setting field "%s": a choice field needs at least one choice.', $name));
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
     * Encrypted secret (API key, password, DSN, token): never rendered back and exported only sealed.
     *
     * @param iterable<Constraint> $constraints
     */
    public static function secret(string $name, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = [], SettingType $type = SettingType::String): self
    {
        return new self($name, $type, null, $label, $help, $required, secret: true, constraints: $constraints);
    }

    /**
     * Copy that is left out of exports and refused on imports (for flags that must never travel between instances).
     */
    public function notPortable(): self
    {
        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, false, $this->constraints, $this->choices, $this->formOptions, $this->visibleWhen);
    }

    /**
     * Copy with extra admin form options (merged over the bundle defaults).
     *
     * @param array<string, mixed> $formOptions
     */
    public function withFormOptions(array $formOptions): self
    {
        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, $this->portable, $this->constraints, $this->choices, $formOptions + $this->formOptions, $this->visibleWhen);
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

        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, $this->portable, $this->constraints, $this->choices, $this->formOptions, ['field' => $field, 'in' => $values]);
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
        };
    }
}
