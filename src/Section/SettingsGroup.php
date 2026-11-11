<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use InvalidArgumentException;

use function in_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * One field group of a settings section (1.14): a subtab of the section page when `web_ui.section_layout` is `tabs`.
 *
 * A group claims fields by name ({@see self::$fields}) and / or by their 1.5 form group ({@see SettingField::$group},
 * {@see self::$fieldGroups}: the exact group or any group below it, `colors` matches `colors.light`). A field claimed by
 * no group goes to the group marked {@see self::$rest}, else to an automatic "Other" group. Groups only change the
 * layout of the admin form: storage, export, validation and the save are the same in both layouts.
 *
 * Immutable and free of services (worker-safe); plain strings only (REQ-ARCH-001).
 */
final readonly class SettingsGroup
{
    /** Group codes: the `?settings_group=` value and part of the DOM ids (the 1.5 field group pattern, e.g. `colors.light`). */
    public const CODE_PATTERN = SettingField::GROUP_PATTERN;

    /** @var list<string> */
    public array $fields;

    /** @var list<string> */
    public array $fieldGroups;

    /**
     * @param string $code stable code ({@see self::CODE_PATTERN}), unique inside its section; never rename it once
     *                     published: hosts link to `?settings_group=<code>`
     * @param string|null $label translation key of the tab label in the section domain (null = `<section>.group.<code>`)
     * @param iterable<string> $fields field names the group claims, in any order (the form keeps the field order)
     * @param iterable<string> $fieldGroups 1.5 form groups whose fields the group claims (exact, or a parent group)
     * @param bool $rest the group also takes every field no other group claims
     * @param string|null $translationDomain domain of the label (null = the section domain)
     */
    public function __construct(
        public string $code,
        public ?string $label = null,
        iterable $fields = [],
        iterable $fieldGroups = [],
        public bool $rest = false,
        public ?string $translationDomain = null,
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid settings group code "%s": expected dot-separated lower snake case segments matching %s.', $code, self::CODE_PATTERN));
        }
        if ($label === '' || $translationDomain === '') {
            throw new InvalidArgumentException(sprintf('Settings group "%s": the label and the translation domain must not be empty strings (use null for the default).', $code));
        }
        $this->fields      = $this->names($code, $fields, SettingField::NAME_PATTERN, 'field name');
        $this->fieldGroups = $this->names($code, $fieldGroups, SettingField::GROUP_PATTERN, 'field group');
    }

    /**
     * Translation key of the label: the given one, else `<section>.group.<code>`.
     */
    public function labelKey(string $section): string
    {
        return $this->label ?? $section . '.group.' . $this->code;
    }

    /**
     * Whether the group claims a field by its 1.5 form group (the exact group, or a group below one of its groups).
     */
    public function claimsFieldGroup(?string $group): bool
    {
        if ($group === null) {
            return false;
        }
        foreach ($this->fieldGroups as $claimed) {
            if ($group === $claimed || str_starts_with($group, $claimed . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy holding exactly these field names (the resolved group of a section page), never a rest group.
     *
     * @param list<string> $fields
     */
    public function withResolvedFields(array $fields): self
    {
        return new self($this->code, $this->label, $fields, [], false, $this->translationDomain);
    }

    /**
     * @param iterable<mixed> $values
     *
     * @return list<string>
     */
    private function names(string $code, iterable $values, string $pattern, string $what): array
    {
        $list = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new InvalidArgumentException(sprintf('Settings group "%s": invalid %s (expected a string matching %s).', $code, $what, $pattern));
            }
            if (!in_array($value, $list, true)) {
                $list[] = $value;
            }
        }

        return $list;
    }
}
