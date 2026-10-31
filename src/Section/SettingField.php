<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use InvalidArgumentException;
use Symfony\Component\Validator\Constraint;

use function in_array;
use function is_bool;
use function is_float;
use function is_int;
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
     */
    public static function email(string $name, ?string $default = null, ?string $label = null, ?string $help = null, bool $required = false): self
    {
        return new self($name, SettingType::Email, $default, $label, $help, $required);
    }

    /**
     * Absolute http(s) URL field.
     */
    public static function url(string $name, ?string $default = null, ?string $label = null, ?string $help = null, bool $required = false): self
    {
        return new self($name, SettingType::Url, $default, $label, $help, $required);
    }

    /**
     * Boolean switch (defaults to false).
     */
    public static function bool(string $name, bool $default = false, ?string $label = null, ?string $help = null): self
    {
        return new self($name, SettingType::Bool, $default, $label, $help);
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
     */
    public static function choice(string $name, array $choices, int|string|null $default = null, ?string $label = null, ?string $help = null, bool $required = false): self
    {
        return new self($name, SettingType::Choice, $default, $label, $help, $required, choices: $choices);
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
        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, false, $this->constraints, $this->choices, $this->formOptions);
    }

    /**
     * Copy with extra admin form options (merged over the bundle defaults).
     *
     * @param array<string, mixed> $formOptions
     */
    public function withFormOptions(array $formOptions): self
    {
        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, $this->portable, $this->constraints, $this->choices, $formOptions + $this->formOptions);
    }

    /**
     * Translation key of the field label (the field name when none was given).
     */
    public function labelKey(): string
    {
        return $this->label ?? $this->name;
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
