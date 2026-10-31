<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Settings;

use LogicException;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;

/**
 * Typed read / write access to the instance settings. Keys are `<section>.<field>`.
 *
 * Reads return the stored value, else the field default. Every row is loaded with one query on the first read of a
 * request and memoized for that request only (worker-safe); saving a section clears the memo.
 */
interface InstanceSettingsInterface
{
    /**
     * Effective value of a plain (non-secret) setting.
     *
     * @throws UnknownSettingException
     * @throws LogicException when the field is a secret (use {@see self::getSecret()})
     */
    public function get(string $key): bool|float|int|string|null;

    /**
     * Effective value of a string-typed setting (string, text, email, url or string choice).
     */
    public function getString(string $key): ?string;

    /**
     * Effective value of an int setting.
     */
    public function getInt(string $key): ?int;

    /**
     * Effective value of a float (or int) setting.
     */
    public function getFloat(string $key): ?float;

    /**
     * Effective value of a bool setting (null counts as false).
     */
    public function getBool(string $key): bool;

    /**
     * Decrypted secret, or null when none is stored.
     *
     * @throws LogicException when the field is not a secret
     */
    public function getSecret(string $key): ?string;

    /**
     * Whether a secret is stored for that field.
     */
    public function hasSecret(string $key): bool;

    /**
     * Whether a value is stored (not the default) for that field.
     */
    public function isOverridden(string $key): bool;

    /**
     * Effective plain values of a section (secrets left out), keyed by field name.
     *
     * @return array<string, bool|float|int|string|null>
     */
    public function getSection(string $section): array;

    /**
     * Validates and stores values of one section, all-or-nothing, then dispatches {@see SettingsChangedEvent}.
     *
     * Plain fields: `null` (or '') removes the stored value (the default applies again). Secret fields: a non-empty
     * string replaces the secret, `null` (or '') removes it, an absent key keeps it.
     *
     * @param array<string, mixed> $values field name => value
     *
     * @throws UnknownSettingException
     * @throws InvalidSettingValueException
     *
     * @return list<string> changed field names
     */
    public function save(string $section, array $values, string $origin = SettingsChangedEvent::ORIGIN_API): array;
}
