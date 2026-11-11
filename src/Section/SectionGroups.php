<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use LogicException;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;

use function array_key_exists;
use function count;
use function in_array;
use function sprintf;

/**
 * Resolves the field groups of a section page (1.14): which fields each subtab holds.
 *
 * Declared groups ({@see SettingsSectionGroupsInterface}) or, without them, one group per distinct 1.5 form group of
 * the fields ({@see SettingField::$group}), in the order of their first field. Every field lands in exactly one group;
 * fields keep the section's field order inside their group. Empty groups are dropped; fewer than two groups resolve to
 * none (the page stays stacked).
 *
 * Pure and stateless (worker-safe).
 */
final class SectionGroups
{
    /** Code of the automatic group of the fields no group claims. */
    public const OTHER = 'other';

    /** Translation key (bundle domain) of the automatic "Other" group. */
    public const OTHER_LABEL = 'group.other';

    /**
     * Checks the declared groups of a section (MP-I2: called when the registry loads the section definitions, whatever
     * the layout): unique codes, known field names, at most one `rest` group. No-op without
     * {@see SettingsSectionGroupsInterface}.
     *
     * @param array<string, SettingField> $fields the section's fields
     *
     * @throws LogicException on an invalid declaration
     */
    public static function validate(SettingsSectionInterface $section, array $fields): void
    {
        if ($section instanceof SettingsSectionGroupsInterface) {
            self::declared($section, $fields);
        }
    }

    /**
     * The non-empty groups of a section, each holding its field names in field order; [] for fewer than two.
     *
     * @param array<string, SettingField> $fields the section's fields (registry order)
     *
     * @throws LogicException for a duplicate group code, a group listing a field the section does not have or more
     *                        than one `rest` group
     *
     * @return list<SettingsGroup>
     */
    public static function resolve(SettingsSectionInterface $section, array $fields): array
    {
        $declared = $section instanceof SettingsSectionGroupsInterface ? self::declared($section, $fields) : self::fromFieldGroups($fields);
        if ($declared === []) {
            return [];
        }

        $rest = null;
        foreach ($declared as $code => $group) {
            $rest = $group->rest ? $code : $rest;
        }
        $members = [];
        foreach ($fields as $name => $field) {
            $code             = self::owner($declared, $name, $field) ?? $rest ?? self::OTHER;
            $members[$code][] = $name;
        }
        if (!array_key_exists(self::OTHER, $declared) && isset($members[self::OTHER])) {
            $declared[self::OTHER] = new SettingsGroup(self::OTHER, self::OTHER_LABEL, translationDomain: NowoInstanceSettingsBundle::TRANSLATION_DOMAIN);
        }

        $groups = [];
        foreach ($declared as $code => $group) {
            if (isset($members[$code])) {
                $groups[] = $group->withResolvedFields($members[$code]);
            }
        }

        return count($groups) < 2 ? [] : $groups;
    }

    /**
     * @param array<string, SettingField> $fields
     *
     * @return array<string, SettingsGroup>
     */
    private static function declared(SettingsSectionGroupsInterface&SettingsSectionInterface $section, array $fields): array
    {
        $groups = [];
        $rest   = null;
        foreach ($section->getGroups() as $group) {
            if (array_key_exists($group->code, $groups)) {
                throw new LogicException(sprintf('Settings section "%s": duplicate group code "%s".', $section->getName(), $group->code));
            }
            foreach ($group->fields as $name) {
                if (!array_key_exists($name, $fields)) {
                    throw new LogicException(sprintf('Settings section "%s": group "%s" lists the unknown field "%s".', $section->getName(), $group->code, $name));
                }
            }
            if ($group->rest && $rest !== null) {
                throw new LogicException(sprintf('Settings section "%s": groups "%s" and "%s" are both the rest group (at most one).', $section->getName(), $rest, $group->code));
            }
            $rest                 = $group->rest ? $group->code : $rest;
            $groups[$group->code] = $group;
        }

        return $groups;
    }

    /**
     * One group per distinct 1.5 form group, in the order of its first field ([] when no field has a group).
     *
     * @param array<string, SettingField> $fields
     *
     * @return array<string, SettingsGroup>
     */
    private static function fromFieldGroups(array $fields): array
    {
        $groups = [];
        foreach ($fields as $field) {
            if ($field->group !== null && !array_key_exists($field->group, $groups)) {
                $groups[$field->group] = new SettingsGroup($field->group, fieldGroups: [$field->group]);
            }
        }

        return $groups;
    }

    /**
     * Code of the group that claims a field: by name first (any group), then by form group (first match).
     *
     * @param array<string, SettingsGroup> $groups
     */
    private static function owner(array $groups, string $name, SettingField $field): ?string
    {
        foreach ($groups as $code => $group) {
            if (in_array($name, $group->fields, true)) {
                return $code;
            }
        }
        foreach ($groups as $code => $group) {
            if ($group->claimsFieldGroup($field->group)) {
                return $code;
            }
        }

        return null;
    }
}
