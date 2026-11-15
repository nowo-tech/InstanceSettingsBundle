<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Settings;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Exception\SettingsConflictException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;

/**
 * Saves a section form without losing what another editor saved meanwhile (1.19, Beacon spec 239). Implemented by
 * {@see InstanceSettings}; the section page uses it when the aliased {@see InstanceSettingsInterface} implements it.
 *
 * Under the section's write lock, each submitted field is compared with the section as the editor opened it
 * ({@see SectionSnapshot}) and as it is stored now:
 *
 * - nobody changed the field meanwhile: the submitted value is saved (as {@see InstanceSettingsInterface::save()} does);
 * - another editor changed it, and this editor left it as shown: the other editor's value is kept (no lost update);
 * - another editor changed it, and this editor changed it too (to another value), or acted on a secret, an image or an
 *   expiry date that was replaced meanwhile: nothing is saved and {@see SettingsConflictException} names the fields (409).
 */
interface SnapshotSettingsWriterInterface
{
    /**
     * The section as stored now (the token of its form); null when no fingerprint key is configured (no token is
     * rendered then, and the form saves like {@see InstanceSettingsInterface::save()}).
     *
     * @throws UnknownSettingException
     */
    public function snapshot(string $section): ?SectionSnapshot;

    /**
     * Saves like {@see InstanceSettingsInterface::save()}, merged with the changes saved since `$base` (see above).
     *
     * @param array<string, mixed> $values field => submitted value
     *
     * @throws UnknownSettingException
     * @throws InvalidSettingValueException
     * @throws SettingsConflictException when a field the editor changed was changed by another editor meanwhile
     *
     * @return list<string> the saved field names
     */
    public function saveSince(string $section, array $values, SectionSnapshot $base, string $origin = SettingsChangedEvent::ORIGIN_FORM): array;
}
