<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Settings;

use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_values;
use function filter_var;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function trim;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;

/**
 * Normalizes and validates submitted setting values (admin form, {@see InstanceSettingsInterface::save()}, imports).
 *
 * Strings are trimmed ('' becomes null), numeric strings become numbers, `"true"` / `"1"` / `"on"` become booleans.
 * Then the type, `required`, the built-in type constraints (e-mail, http(s) URL, choice) and the field constraints
 * are checked. Messages never contain the value.
 */
final readonly class ValueNormalizer
{
    /**
     * @param ValidatorInterface|null $validator Symfony Validator for the field constraints (null = type checks only)
     */
    public function __construct(
        private ?ValidatorInterface $validator = null,
    ) {
    }

    /**
     * Normalized value, or the list of violation messages.
     *
     * @return array{0: bool|float|int|string|null, 1: list<string>}
     */
    public function normalize(SettingField $field, mixed $value): array
    {
        $value = $this->coerce($field->type, $value);
        if ($value === null) {
            // A required field may drop its stored value only when a default takes over.
            return [null, $field->required && $field->default === null ? ['This value should not be blank.'] : []];
        }

        if (!SettingField::matchesType($field->type, $value, $field->choices)) {
            return [null, [$field->type === SettingType::Choice ? 'The value is not one of the allowed choices.' : 'This value is not a valid ' . $field->type->value . '.']];
        }
        if (is_float($value) && !is_finite($value)) {
            return [null, ['This value is not a finite number.']];
        }
        if ($field->type === SettingType::Url && preg_match('#^https?://#i', (string) $value) !== 1) {
            return [null, ['This value is not a valid http(s) URL.']];
        }

        return [$value, $this->validate($field, $value)];
    }

    /**
     * @return list<string>
     */
    private function validate(SettingField $field, bool|float|int|string $value): array
    {
        if (!$this->validator instanceof ValidatorInterface) {
            return [];
        }

        $constraints = $field->constraints;
        if ($field->type === SettingType::Email) {
            $constraints[] = new Email();
        }
        if ($field->type === SettingType::Url) {
            $constraints[] = new Url(requireTld: false);
        }
        if ($field->type === SettingType::Choice) {
            $constraints[] = new Choice(choices: array_values($field->choices));
        }

        $messages = [];
        foreach ($this->validator->validate($value, $constraints) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    private function coerce(SettingType $type, mixed $value): mixed
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                return null;
            }
            if ($type !== SettingType::Text) {
                $value = trim($value);
            }
        }

        return match ($type) {
            SettingType::Bool  => $this->toBool($value),
            SettingType::Int   => is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
            SettingType::Float => is_int($value) || (is_string($value) && is_numeric($value)) ? (float) $value : $value,
            default            => $value,
        };
    }

    private function toBool(mixed $value): mixed
    {
        if (!is_string($value) && !is_int($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
    }
}
