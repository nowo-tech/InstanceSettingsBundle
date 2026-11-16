<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Runtime;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use Nowo\InstanceSettingsBundle\Expiry\LockingSecretExpiryStoreInterface;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryStoreInterface;
use Nowo\InstanceSettingsBundle\Repository\SectionLock;
use Nowo\InstanceSettingsBundle\Repository\SectionLockingRepositoryInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_keys;
use function array_sum;
use function is_array;
use function is_string;
use function sprintf;

/**
 * 1.20: nowo-tech/runtime-setting-contract writer over the instance settings (`runtime_setting.enabled`).
 *
 * Every call is one unit of work of the settings repository (the bundle's runner and private manager; refused inside a
 * transaction the caller opened, audit lessons QL-M1 / QL-M2): the section write lock, the value written through
 * {@see InstanceSettingsInterface::save()} (the field's own type and constraints still apply, the secret is encrypted as
 * any other, the after-commit `SettingsChangedEvent` and audit line follow; origin {@see SettingsChangedEvent::ORIGIN_RUNTIME}),
 * the previous value of a rotation (the stored form copied as is, expiry in UTC), the purge of this field's expired
 * previous value, and a new section version token, also when the value did not change (Delta QS-D-I). After the commit an
 * `info` line with the actor, the id and the fingerprints (none for secrets).
 *
 * - **Expiring secrets** (1.17 `expires: true`): a `set` or `rotate` keeps the stored expiry date of the field, so the
 *   expiry warnings go on (audit QZ-L-3); the date is named in the audit line. Change the date with the admin form or
 *   {@see InstanceSettingsInterface::save()} (`<field>:expires_on`). An `unset` removes the secret and its date.
 * - **One connection** (audit QZ-L-5): the settings repository must run its unit of work on the connection of the
 *   runtime-setting tables (the bundle's repository does); a repository on another connection is refused, because the
 *   token would commit apart from the value.
 * - **Purge** (audit QZ-L-1): `purgeExpired()` takes the section locks of the sections it purges, in the lock order,
 *   before it deletes anything, like every writer of a section.
 *
 * Stateless.
 */
final readonly class InstanceSettingsRuntimeWriter implements RuntimeSettingWriterInterface
{
    private LoggerInterface $logger;

    /**
     * @param SettingValueRepositoryInterface $repository the settings repository (its unit of work and section lock)
     * @param InstanceSettingsInterface $settings writes the value (field rules, encryption, after-commit event and audit)
     * @param SecretExpiryStoreInterface|null $expiryStore the 1.17 expiry dates (kept on a write of an expiring secret)
     */
    public function __construct(
        private SettingValueRepositoryInterface $repository,
        private InstanceSettingsInterface $settings,
        private SettingsSectionRegistry $registry,
        private RuntimeSettingTables $tables,
        private InstanceSettingsRuntimeSource $source,
        private ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        private ?SecretExpiryStoreInterface $expiryStore = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Stores a value of the field (validated by the updater; the field rules apply too).
     */
    public function set(RuntimeSettingDefinition $definition, string $value, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
        return $this->write($definition, RuntimeSettingAction::Set, $context, $value, function (array $expiry) use ($definition, $value): void {
            $this->save($definition, $definition->type->parse($value, $definition->maxLength), $expiry);
        });
    }

    /**
     * Removes the stored value of the field (its configured value or default applies again).
     */
    public function unset(RuntimeSettingDefinition $definition, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
        return $this->write($definition, RuntimeSettingAction::Unset, $context, null, function () use ($definition): void {
            $this->save($definition, null, []);
        });
    }

    /**
     * Stores a new value and keeps the replaced one, as stored, until `$previousExpiresAt` (UTC).
     */
    public function rotate(RuntimeSettingDefinition $definition, string $value, DateTimeImmutable $previousExpiresAt, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
        return $this->write($definition, RuntimeSettingAction::Rotate, $context, $value, function (array $expiry) use ($definition, $value, $previousExpiresAt): void {
            $stored = $this->storedForm($definition);
            // The save drops any older previous value of the field (a plain write replaces it) ...
            $this->save($definition, $definition->type->parse($value, $definition->maxLength), $expiry);
            // ... then the value it replaced becomes the previous one, as stored (a secret stays encrypted).
            if ($stored !== null) {
                $this->tables->insertPrevious($definition->namespace(), $definition->key(), $stored['value'], $stored['secret'], $previousExpiresAt);
            }
        });
    }

    /**
     * Deletes every previous value expired at `$now` and bumps the sections it touched: the sections are read first (no
     * lock), their section locks taken in the lock order, then one conditional DELETE per section (audit QZ-L-1: never a
     * range lock on the previous values before the section lock, which deadlocked with a rotation on MySQL / MariaDB). A
     * value that expires after the read is left for the next run.
     */
    public function purgeExpired(DateTimeImmutable $now): int
    {
        $this->assertOwnTransaction();

        /** @var int $purged */
        // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
        $purged = $this->repository->transactional(function () use ($now): int {
            $this->assertSameConnection();
            $sections = $this->tables->expiredSections($now);
            SectionLock::lockAll($this->repository, $sections);
            $deleted = [];
            foreach ($sections as $section) {
                $count = $this->tables->purge($now, $section);
                if ($count > 0) {
                    $deleted[$section] = $count;
                }
            }
            $this->tables->bump(array_keys($deleted));

            return array_sum($deleted);
        });
        if ($purged > 0) {
            $this->logger->info('Instance settings: {count} expired previous value(s) purged.', ['count' => $purged]);
        }

        return $purged;
    }

    /**
     * @param callable(array<string, string>): void $mutation receives the expiry date to keep (`<field>:expires_on`)
     */
    private function write(RuntimeSettingDefinition $definition, RuntimeSettingAction $action, RuntimeSettingWriteContext $context, ?string $newValue, callable $mutation): RuntimeSettingChange
    {
        $field = $this->assertWritable($definition);
        $this->assertOwnTransaction();
        $section    = $definition->namespace();
        $keepExpiry = $field->expires && $action !== RuntimeSettingAction::Unset;

        try {
            /** @var array{0: ?string, 1: string, 2: ?string} $result */
            // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
            $result = $this->repository->transactional(function () use ($definition, $mutation, $section, $keepExpiry): array {
                $this->assertSameConnection();
                if ($this->repository instanceof SectionLockingRepositoryInterface) {
                    $this->repository->lockSection($section);
                }
                // Only a plain value is read back for its fingerprint; a secret is never decrypted here.
                $old = $definition->secret ? null : $this->source->read($definition->id)?->current;
                // 1.17 expiring secret: its stored date is given again with the new value (audit QZ-L-3).
                $expiresOn = $keepExpiry ? $this->storedExpiry($section, $definition->key()) : null;
                // Any write replaces the previous value of a rotation (also one that leaves the value as it is).
                $this->tables->dropPrevious($section, $definition->key());
                $mutation($expiresOn === null ? [] : [SettingField::expiryKey($definition->key()) => $expiresOn]);
                $this->tables->purge($this->now(), $section, $definition->key());

                return [$old, $this->tables->bump([$section])[$section], $expiresOn];
            });
        } catch (InvalidSettingValueException $e) {
            throw InvalidRuntimeSettingValue::because(sprintf('refused by the rules of instance settings field %s', $definition->idString()));
        }
        [$old, $token, $expiresOn] = $result;

        $change = new RuntimeSettingChange($definition->id, $action, $context->fingerprint($definition, $old), $context->fingerprint($definition, $newValue), $token, false, $definition->secret);
        $this->logger->info('Instance settings runtime setting {id} {action} by {actor}.', [
            'bundle'          => 'NowoInstanceSettingsBundle',
            'id'              => $definition->idString(),
            'action'          => $action->value,
            'actor'           => $context->actor,
            'secret'          => $definition->secret,
            'old_fingerprint' => $change->oldFingerprint,
            'new_fingerprint' => $change->newFingerprint,
            'expires_on'      => $expiresOn,
        ]);

        return $change;
    }

    /**
     * @param array<string, string> $expiry the expiry date kept with the value (`<field>:expires_on` => `YYYY-MM-DD`)
     */
    private function save(RuntimeSettingDefinition $definition, string|int|float|bool|null $value, array $expiry): void
    {
        $this->settings->save($definition->namespace(), [$definition->key() => $value] + $expiry, SettingsChangedEvent::ORIGIN_RUNTIME);
    }

    /**
     * The stored expiry date of an expiring secret (`YYYY-MM-DD`), read under the section lock; null without one.
     */
    private function storedExpiry(string $section, string $name): ?string
    {
        $dates  = $this->expiryStore instanceof LockingSecretExpiryStoreInterface ? $this->expiryStore->findBySectionForUpdate($section) : ($this->expiryStore?->findBySection($section) ?? []);
        $record = $dates[$name] ?? null;

        return $record === null ? null : ExpiryDate::format($record->expiresOn);
    }

    /**
     * The stored form of the field (JSON value, or encrypted secret column), read with DBAL, never decrypted.
     *
     * @return array{value: string|null, secret: string|null}|null
     */
    private function storedForm(RuntimeSettingDefinition $definition): ?array
    {
        $columns = $this->tables->settingsColumns();
        $row     = $this->tables->connection()->fetchAssociative(sprintf(
            'SELECT %2$s AS section, %3$s AS name, %4$s AS value, %5$s AS secret FROM %1$s WHERE %2$s = ? AND %3$s = ?',
            $columns['table'],
            $columns['section'],
            $columns['name'],
            $columns['value'],
            $columns['secret'],
        ), [$definition->namespace(), $definition->key()]);
        if (!is_array($row) || $row['section'] !== $definition->namespace() || $row['name'] !== $definition->key()) {
            return null;
        }

        return [
            'value'  => is_string($row['value']) ? $row['value'] : null,
            'secret' => is_string($row['secret']) ? $row['secret'] : null,
        ];
    }

    private function assertWritable(RuntimeSettingDefinition $definition): SettingField
    {
        if ($definition->id->isEnvName()) {
            throw new RuntimeSettingNotWritable(sprintf('Runtime setting "%s" is an environment variable name: instance settings store "section.field" ids only.', $definition->idString()));
        }

        try {
            $field = $this->registry->field($definition->namespace(), $definition->key());
        } catch (UnknownSettingException) {
            throw new RuntimeSettingNotWritable(sprintf('Runtime setting "%s" is not a field of a registered instance settings section.', $definition->idString()));
        }
        if ($field->type === SettingType::Image || $field->secret !== $definition->secret) {
            throw new RuntimeSettingNotWritable(sprintf('Runtime setting "%s" does not match its instance settings field (image fields cannot be runtime settings; the secret flags must agree).', $definition->idString()));
        }

        return $field;
    }

    private function assertOwnTransaction(): void
    {
        if ($this->tables->connection()->isTransactionActive()) {
            throw RuntimeSettingNotWritable::insideTransaction();
        }
    }

    /**
     * Inside the repository's unit of work: its transaction must be open on the connection of the runtime-setting tables
     * (audit QZ-L-5), or the token would commit apart from the value.
     */
    private function assertSameConnection(): void
    {
        if (!$this->tables->connection()->isTransactionActive()) {
            throw new RuntimeSettingNotWritable('Instance settings runtime settings: the settings repository did not open its unit of work on the connection of the runtime-setting tables (a custom SettingValueRepositoryInterface on another connection?). Runtime settings need the bundle repository, or one on the same connection as the bundle\'s entity manager.');
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
