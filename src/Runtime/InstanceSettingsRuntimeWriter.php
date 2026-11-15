<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Runtime;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Repository\SectionLockingRepositoryInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
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
 * Stateless.
 */
final readonly class InstanceSettingsRuntimeWriter implements RuntimeSettingWriterInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private SettingValueRepositoryInterface $repository,
        private InstanceSettingsInterface $settings,
        private SettingsSectionRegistry $registry,
        private RuntimeSettingTables $tables,
        private InstanceSettingsRuntimeSource $source,
        private ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function set(RuntimeSettingDefinition $definition, string $value, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
        return $this->write($definition, RuntimeSettingAction::Set, $context, $value, function () use ($definition, $value): void {
            $this->save($definition, $definition->type->parse($value, $definition->maxLength));
        });
    }

    public function unset(RuntimeSettingDefinition $definition, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
        return $this->write($definition, RuntimeSettingAction::Unset, $context, null, function () use ($definition): void {
            $this->save($definition, null);
        });
    }

    public function rotate(RuntimeSettingDefinition $definition, string $value, DateTimeImmutable $previousExpiresAt, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
        return $this->write($definition, RuntimeSettingAction::Rotate, $context, $value, function () use ($definition, $value, $previousExpiresAt): void {
            $stored = $this->storedForm($definition);
            // The save drops any older previous value of the field (a plain write replaces it) ...
            $this->save($definition, $definition->type->parse($value, $definition->maxLength));
            // ... then the value it replaced becomes the previous one, as stored (a secret stays encrypted).
            if ($stored !== null) {
                $this->tables->connection()->insert($this->tables->previousTable(), [
                    'section'      => $definition->namespace(),
                    'name'         => $definition->key(),
                    'value'        => $stored['value'],
                    'secret_value' => $stored['secret'],
                    'expires_at'   => RuntimeSettingTables::utc($previousExpiresAt),
                ], ['expires_at' => 'datetime_immutable']);
            }
        });
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        $this->assertOwnTransaction();

        /** @var int $purged */
        // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
        $purged = $this->repository->transactional(function () use ($now): int {
            [$purged, $sections] = $this->tables->purge($now);
            $this->tables->bump($sections);

            return $purged;
        });
        if ($purged > 0) {
            $this->logger->info('Instance settings: {count} expired previous value(s) purged.', ['count' => $purged]);
        }

        return $purged;
    }

    /**
     * @param callable(): void $mutation
     */
    private function write(RuntimeSettingDefinition $definition, RuntimeSettingAction $action, RuntimeSettingWriteContext $context, ?string $newValue, callable $mutation): RuntimeSettingChange
    {
        $this->assertWritable($definition);
        $this->assertOwnTransaction();
        $section = $definition->namespace();

        try {
            /** @var array{0: ?string, 1: string} $result */
            // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
            $result = $this->repository->transactional(function () use ($definition, $mutation, $section): array {
                if ($this->repository instanceof SectionLockingRepositoryInterface) {
                    $this->repository->lockSection($section);
                }
                // Only a plain value is read back for its fingerprint; a secret is never decrypted here.
                $old = $definition->secret ? null : $this->source->read($definition->id)?->current;
                // Any write replaces the previous value of a rotation (also one that leaves the value as it is).
                $this->tables->dropPrevious($section, $definition->key());
                $mutation();
                $this->tables->purge($this->now(), $section, $definition->key());

                return [$old, $this->tables->bump([$section])[$section]];
            });
        } catch (InvalidSettingValueException $e) {
            throw InvalidRuntimeSettingValue::because(sprintf('refused by the rules of instance settings field %s', $definition->idString()));
        }
        [$old, $token] = $result;

        $change = new RuntimeSettingChange($definition->id, $action, $context->fingerprint($definition, $old), $context->fingerprint($definition, $newValue), $token, false, $definition->secret);
        $this->logger->info('Instance settings runtime setting {id} {action} by {actor}.', [
            'bundle'          => 'NowoInstanceSettingsBundle',
            'id'              => $definition->idString(),
            'action'          => $action->value,
            'actor'           => $context->actor,
            'secret'          => $definition->secret,
            'old_fingerprint' => $change->oldFingerprint,
            'new_fingerprint' => $change->newFingerprint,
        ]);

        return $change;
    }

    private function save(RuntimeSettingDefinition $definition, string|int|float|bool|null $value): void
    {
        $this->settings->save($definition->namespace(), [$definition->key() => $value], SettingsChangedEvent::ORIGIN_RUNTIME);
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

    private function assertWritable(RuntimeSettingDefinition $definition): void
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
    }

    private function assertOwnTransaction(): void
    {
        if ($this->tables->connection()->isTransactionActive()) {
            throw RuntimeSettingNotWritable::insideTransaction();
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
