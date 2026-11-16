<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryStoreInterface;
use Nowo\InstanceSettingsBundle\Runtime\RuntimeSettingAdminGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_keys;
use function array_map;
use function count;
use function explode;
use function hash_equals;
use function implode;
use function sprintf;
use function strval;

/**
 * Default {@see SettingRowWriterInterface}: maps the rows onto the entities of the aliased
 * {@see SettingValueRepositoryInterface} and applies them in one of its transactions (1.11). Stateless (worker-safe).
 */
final readonly class RepositorySettingRowWriter implements SettingRowWriterInterface
{
    /**
     * @param SettingValueRepositoryInterface $repository storage of the rows
     * @param SecretExpiryStoreInterface|null $expiryStore 1.17 (audit L3): the expiry date of a secret row written or
     *                                                     deleted here is removed in the same transaction (its date
     *                                                     belonged to the previous secret)
     * @param RuntimeSettingAdminGuard|null $runtimeSettings 1.20 (audit QZ-L-2): a row of a field registered as a runtime
     *                                                       setting also passes that setting's validator
     */
    public function __construct(
        private SettingValueRepositoryInterface $repository,
        private ?SecretExpiryStoreInterface $expiryStore = null,
        private ?ClockInterface $clock = null,
        private ?RuntimeSettingAdminGuard $runtimeSettings = null,
    ) {
    }

    /**
     * Removes the expiry dates of the rows written and of the rows deleted (1.17, audit L3): a raw write cannot
     * know the date of the new secret. Set it again with {@see SecretExpiryStoreInterface::write()} when it is known.
     * 1.18.1 (audit D1-I1): a secret rewritten byte for byte (a restore of identical rows) keeps its date.
     *
     * @param array<string, array{save?: array<string, StoredSettingRow>, remove?: array<string, true>}> $plans
     * @param array<string, array<string, true>> $unchanged secret rows rewritten with the stored value (section => names)
     */
    private function forgetDates(array $plans, array $unchanged): void
    {
        if (!$this->expiryStore instanceof SecretExpiryStoreInterface) {
            return;
        }
        foreach ($plans as $section => $plan) {
            $dates = [];
            foreach ($plan['save'] ?? [] as $name => $unused) {
                // Every saved row except an unchanged secret (audit QC-I2: a secret rewritten as a plain row too); a
                // null for a row without a date is a no-op.
                if (!isset($unchanged[$section][$name])) {
                    $dates[$name] = null;
                }
            }
            foreach ($plan['remove'] ?? [] as $name => $unused) {
                // A row both saved and removed is saved (see writeRows()): its own rule above applies.
                if (!isset($plan['save'][$name])) {
                    $dates[$name] = null;
                }
            }
            $this->expiryStore->write($section, $dates, ($this->clock ?? new NativeClock())->now());
        }
    }

    /**
     * 1.20 (audit QZ-L-2): refuses the whole write when a row of a registered runtime setting fails that setting's
     * validator (the reason only, never the value).
     *
     * @param array<string, array{save?: array<string, StoredSettingRow>, remove?: array<string, true>}> $plans
     *
     * @throws InvalidArgumentException
     */
    private function assertRuntimeSettings(array $plans): void
    {
        if (!$this->runtimeSettings instanceof RuntimeSettingAdminGuard) {
            return;
        }
        $problems = [];
        foreach ($plans as $section => $plan) {
            $values = [];
            foreach ($plan['save'] ?? [] as $name => $row) {
                $values[$name] = $row->isSecret ? $row->secret() : $row->value;
            }
            foreach ($this->runtimeSettings->violations((string) $section, $values) as $name => $messages) {
                $problems[] = sprintf('%s.%s: %s', $section, $name, implode('; ', $messages));
            }
        }
        if ($problems !== []) {
            throw new InvalidArgumentException('Instance setting rows refused by their runtime setting validators: ' . implode(' | ', $problems) . '.');
        }
    }

    /**
     * Inserts / replaces and deletes the rows in one transaction of the repository.
     */
    public function writeRows(array $save, array $remove = []): void
    {
        $plans = [];
        foreach ($save as $row) {
            if ($row->isSecret && $row->secret() === null) {
                throw new InvalidArgumentException(sprintf('Instance setting row "%s" is a secret without its value (unserialized or not decrypted): it cannot be written.', $row->key()));
            }
            $plans[$row->section]['save'][$row->name] = $row;
        }
        foreach ($remove as $key) {
            $parts = explode('.', $key);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new InvalidArgumentException(sprintf('Invalid instance setting key "%s": expected "section.field".', $key));
            }
            $plans[$parts[0]]['remove'][$parts[1]] = true;
        }
        if ($plans === []) {
            return;
        }
        $this->assertRuntimeSettings($plans);

        // @igor-ignore - The closure runs synchronously inside the transaction and is not stored.
        $this->repository->transactional(function () use ($plans): void {
            // 1.19: the write locks of every section first, in the lock order; each section is then re-read under its lock.
            SectionLock::lockAll($this->repository, array_map(strval(...), array_keys($plans)));
            $entities  = [];
            $deleted   = [];
            $unchanged = [];
            foreach ($plans as $section => $plan) {
                $existing = [];
                foreach (SectionLock::rowsOf($this->repository, (string) $section) as $entity) {
                    $existing[$entity->getName()] = $entity;
                }
                foreach ($plan['save'] ?? [] as $name => $row) {
                    $entity = $existing[$name] ?? new SettingValue($section, $name, $row->updatedAt);
                    $stored = $entity->getSecret();
                    if ($row->isSecret && $stored !== null && hash_equals($stored, (string) $row->secret())) {
                        $unchanged[$section][$name] = true;
                    }
                    $entities[] = $row->isSecret ? $entity->storeSecret((string) $row->secret(), $row->updatedAt) : $entity->storeValue($row->value, $row->updatedAt);
                }
                foreach ($plan['remove'] ?? [] as $name => $unused) {
                    if (isset($existing[$name]) && !isset($plan['save'][$name])) {
                        $deleted[] = $existing[$name];
                    }
                }
            }
            $this->repository->apply($entities, $deleted);
            $this->forgetDates($plans, $unchanged);
        });
    }
}
