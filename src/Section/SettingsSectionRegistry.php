<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use LogicException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;

use function array_key_exists;
use function array_keys;
use function count;
use function explode;
use function preg_match;
use function sprintf;

/**
 * Registered settings sections, in tag priority order, with their fields.
 *
 * Definitions are validated on first use (unique section names matching {@see self::NAME_PATTERN}, unique field
 * names) and kept for the lifetime of the worker: sections are stateless services and fields are immutable value
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
     */
    public function __construct(
        private readonly iterable $sections = [],
    ) {
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
                $fields[$field->name] = $field;
            }

            $map[$name] = ['section' => $section, 'fields' => $fields];
        }

        // @igor-ignore - Immutable section definitions resolved once per worker from stateless tagged services; no request data.
        return $this->map = $map;
    }
}
