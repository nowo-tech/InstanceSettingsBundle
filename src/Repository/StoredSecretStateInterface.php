<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use Nowo\InstanceSettingsBundle\Settings\SectionSnapshot;

/**
 * Optional capability of a {@see SettingValueRepositoryInterface} (1.19, audit QQ-L-1): the stored form of the secret rows
 * of a section, as the storage holds them (the DoctrineEncrypt ciphertext), never the plaintext. A {@see SectionSnapshot}
 * fingerprints a secret from it, so the form token of a section changes whenever its secret is written again and cannot
 * confirm a guessed secret, even to someone who knows `kernel.secret`. A repository without it is fingerprinted by row
 * id and `updated_at`.
 */
interface StoredSecretStateInterface
{
    /**
     * The stored (encrypted) form of each secret row of one section, keyed by field name; the section name is re-checked
     * byte for byte. With `$forUpdate` (inside a unit of work, under the section lock) it is a locking read: the latest
     * committed rows, never an older snapshot of the transaction (audit QQ-D-M-1).
     *
     * @return array<string, string>
     */
    public function storedSecretStates(string $section, bool $forUpdate = false): array;
}
