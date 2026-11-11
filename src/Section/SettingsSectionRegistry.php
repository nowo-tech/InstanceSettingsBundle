<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use LogicException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\File\GdSupport;

use function array_key_exists;
use function array_keys;
use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;

/**
 * Registered settings sections, in tag priority order, with their fields.
 *
 * Definitions are validated on first use (unique section names matching {@see self::NAME_PATTERN}, unique field
 * names, `visible_when` targets: {@see self::assertVisibleWhen()}) and kept for the lifetime of the worker: sections are stateless services and fields are immutable value
 * objects, so the map never holds request data.
 */
final class SettingsSectionRegistry
{
    /** Allowed section names: lower snake case, 1–64 characters (also the URL segment). */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** @var array<string, array{section: SettingsSectionInterface, fields: array<string, SettingField>}>|null */
    private ?array $map = null;

    /**
     * @param iterable<SettingsSectionInterface> $sections tagged `nowo_instance_settings.section`
     * @param array<string, mixed>|null $gdInfo gd_info() to check image fields against (null = the running PHP; tests)
     * @param bool $filesEnabled `files.enabled`: without it the file table is not mapped and an image field fails closed
     *                           (true when the registry is built by hand, outside the container)
     */
    public function __construct(
        private readonly iterable $sections = [],
        private readonly ?array $gdInfo = null,
        private readonly bool $filesEnabled = true,
    ) {
    }

    /**
     * Whether any section declares an image field (1.4).
     */
    public function hasImageFields(): bool
    {
        foreach ($this->map() as $entry) {
            foreach ($entry['fields'] as $field) {
                if ($field->type === SettingType::Image) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every section, in order.
     *
     * @return array<string, SettingsSectionInterface> name => section
     */
    public function all(): array
    {
        $sections = [];
        foreach ($this->map() as $name => $entry) {
            $sections[$name] = $entry['section'];
        }

        return $sections;
    }

    /**
     * Section names, in order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->map());
    }

    /**
     * Whether a section is registered.
     */
    public function has(string $section): bool
    {
        return array_key_exists($section, $this->map());
    }

    /**
     * A registered section.
     *
     * @throws UnknownSettingException
     */
    public function get(string $section): SettingsSectionInterface
    {
        return $this->entry($section)['section'];
    }

    /**
     * Fields of a section, keyed by name, in order.
     *
     * @throws UnknownSettingException
     *
     * @return array<string, SettingField>
     */
    public function fields(string $section): array
    {
        return $this->entry($section)['fields'];
    }

    /**
     * One field of a section.
     *
     * @throws UnknownSettingException
     */
    public function field(string $section, string $field): SettingField
    {
        $fields = $this->fields($section);
        if (!array_key_exists($field, $fields)) {
            throw UnknownSettingException::field($section, $field);
        }

        return $fields[$field];
    }

    /**
     * Splits a `<section>.<field>` key and returns the section name, the field name and the field.
     *
     * @throws UnknownSettingException
     *
     * @return array{0: string, 1: string, 2: SettingField}
     */
    public function resolve(string $key): array
    {
        $parts = explode('.', $key);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw UnknownSettingException::malformedKey($key);
        }

        return [$parts[0], $parts[1], $this->field($parts[0], $parts[1])];
    }

    /**
     * @return array{section: SettingsSectionInterface, fields: array<string, SettingField>}
     */
    private function entry(string $section): array
    {
        $map = $this->map();
        if (!array_key_exists($section, $map)) {
            throw UnknownSettingException::section($section);
        }

        return $map[$section];
    }

    /**
     * A `visible_when` field depends on another field of the same section that is not secret and not itself
     * provider-dependent; with a choice (bool) controller every value must be one of its choices ('1' / '').
     *
     * @param array<string, SettingField> $fields
     */
    private function assertVisibleWhen(string $section, SettingField $field, array $fields): void
    {
        if ($field->visibleWhen === null) {
            return;
        }
        $target = $fields[$field->visibleWhen['field']] ?? null;
        $where  = sprintf('Field "%s" of instance settings section "%s"', $field->name, $section);
        if ($target === null) {
            throw new LogicException(sprintf('%s: visible_when.field "%s" is not a field of the section.', $where, $field->visibleWhen['field']));
        }
        if ($target->secret) {
            throw new LogicException(sprintf('%s: visible_when.field "%s" is a secret (its value is never rendered).', $where, $target->name));
        }
        if ($target->visibleWhen !== null) {
            throw new LogicException(sprintf('%s: visible_when.field "%s" is provider-dependent itself (no chains).', $where, $target->name));
        }
        if ($target->type === SettingType::Image) {
            throw new LogicException(sprintf('%s: visible_when.field "%s" is an image field.', $where, $target->name));
        }
        $allowed = match ($target->type) {
            SettingType::Choice => array_map(SettingField::visibilityValue(...), $target->choices),
            SettingType::Bool   => ['1', ''],
            default             => null,
        };
        if ($allowed === null) {
            return;
        }
        foreach ($field->visibleWhen['in'] as $value) {
            if (!in_array($value, $allowed, true)) {
                throw new LogicException(sprintf('%s: visible_when.in value "%s" is not a value of "%s" (%s).', $where, $value, $target->name, implode(', ', $allowed)));
            }
        }
    }

    /**
     * A colour field's preset source (1.12, {@see SettingField::withPresetFrom()}) is another field of the same section
     * whose value is rendered: not a secret, not an image.
     *
     * @param array<string, SettingField> $fields
     */
    private function assertPresetFrom(string $section, SettingField $field, array $fields): void
    {
        $from = $field->presetFrom();
        if ($from === null) {
            return;
        }
        $target = $fields[$from['field']] ?? null;
        if ($target === null || $target->secret || $target->type === SettingType::Image) {
            throw new LogicException(sprintf('Field "%s" of instance settings section "%s": the preset colour source "%s" must be a plain (not secret, not image) field of the section.', $field->name, $section, $from['field']));
        }
    }

    /**
     * @return array<string, array{section: SettingsSectionInterface, fields: array<string, SettingField>}>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = [];
        foreach ($this->sections as $section) {
            $name = $section->getName();
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new LogicException(sprintf('Invalid instance settings section name "%s" (%s): expected lower snake case matching %s.', $name, $section::class, self::NAME_PATTERN));
            }
            if (array_key_exists($name, $map)) {
                throw new LogicException(sprintf('Duplicate instance settings section "%s" (%s and %s).', $name, $map[$name]['section']::class, $section::class));
            }

            $fields = [];
            foreach ($section->getFields() as $field) {
                if (array_key_exists($field->name, $fields)) {
                    throw new LogicException(sprintf('Duplicate field "%s" in instance settings section "%s".', $field->name, $name));
                }
                if ($field->type === SettingType::Image) {
                    if (!$this->filesEnabled) {
                        throw new LogicException(sprintf('Instance settings section "%s" declares the image field "%s": set nowo_instance_settings.files.enabled: true (it maps the instance_setting_file table; add the migration).', $name, $field->name));
                    }
                    // Fail closed: an image is never stored without GD's sniffing and re-encoding.
                    GdSupport::assertAvailable($this->gdInfo);
                }
                $fields[$field->name] = $field;
            }
            foreach ($fields as $field) {
                $this->assertVisibleWhen($name, $field, $fields);
                $this->assertPresetFrom($name, $field, $fields);
            }

            $map[$name] = ['section' => $section, 'fields' => $fields];
        }

        // @igor-ignore - Immutable section definitions resolved once per worker from stateless tagged services; no request data.
        return $this->map = $map;
    }
}
