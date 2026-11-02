<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Seed;

use LogicException;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SectionFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Psr\Log\LoggerInterface;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function implode;
use function in_array;
use function is_string;
use function trim;

/**
 * One-time bootstrap of the store from the section fallbacks ({@see SectionFallbackProviderInterface}): copies each
 * non-empty fallback into the store **only when the field has no stored value**, so an instance can drop its
 * `%env(...)%` / YAML fallbacks afterwards and keep its settings in the database only.
 *
 * - Idempotent: a second run seeds nothing (every field is stored by then); stored values are never overwritten.
 * - Writes go through {@see InstanceSettingsInterface::save()} with origin {@see SettingsChangedEvent::ORIGIN_SEED}:
 *   same validation, secrets encrypted at rest, the `Instance settings saved.` log line and the
 *   {@see SettingsChangedEvent} audit hook as any other save; all sections are written in one transaction.
 * - Never exposes a value: the {@see SeedReport} and the log carry section / field names and reason codes only, and
 *   the validation messages of a refused fallback are dropped (they may echo the value).
 *
 * Stateless (worker-safe); usable from a host upgrade step, a console command or a **non-transactional** Doctrine
 * migration (`isTransactional(): false`): see {@see TransactionStateGuard}.
 */
final readonly class FallbackSeeder
{
    /**
     * @param SettingsSectionRegistry $registry registered sections
     * @param SettingValueRepositoryInterface $repository storage (stored-value check, transaction)
     * @param InstanceSettingsInterface $settings store (validated, audited writes)
     * @param ValueNormalizer $normalizer per-field validation before the write
     * @param LoggerInterface|null $logger receives one summary line per run (names only)
     * @param TransactionStateGuard|null $transactionGuard refuses to write on a phantom DBAL transaction (MySQL DDL)
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private SettingValueRepositoryInterface $repository,
        private InstanceSettingsInterface $settings,
        private ValueNormalizer $normalizer = new ValueNormalizer(),
        private ?LoggerInterface $logger = null,
        private ?TransactionStateGuard $transactionGuard = null,
    ) {
    }

    /**
     * Seeds the fallbacks of `$sections` (null = every section implementing the fallback interface).
     *
     * @param list<string>|null $sections section names; an unknown name throws
     * @param bool $dryRun compute the report only, write nothing
     *
     * @throws UnknownSettingException when a requested section is not registered
     * @throws LogicException when called inside a phantom transaction ({@see TransactionStateGuard})
     */
    public function seed(?array $sections = null, bool $dryRun = false): SeedReport
    {
        $names = $sections ?? $this->registry->names();
        foreach ($names as $name) {
            $this->registry->get($name);
        }

        $run = function () use ($names, $dryRun): SeedReport {
            $examined = [];
            $seeded   = [];
            $skipped  = [];
            $secrets  = [];
            foreach ($names as $name) {
                $section = $this->registry->get($name);
                if (!$section instanceof SectionFallbackProviderInterface) {
                    continue;
                }
                $examined[]                = $name;
                [$values, $sectionSkipped] = $this->plan($name, $section);
                $skipped                   = array_merge($skipped, $sectionSkipped);
                if ($values === []) {
                    continue;
                }

                $written = $dryRun ? array_keys($values) : $this->settings->save($name, $values, SettingsChangedEvent::ORIGIN_SEED);
                $fields  = $this->registry->fields($name);
                foreach ($written as $field) {
                    $seeded[] = $name . '.' . $field;
                    if ($fields[$field]->secret) {
                        $secrets[] = $name . '.' . $field;
                    }
                }
            }

            return new SeedReport($examined, $seeded, $skipped, $secrets, $dryRun);
        };

        if (!$dryRun) {
            $this->transactionGuard?->check();
        }
        $report = $dryRun ? $run() : $this->repository->transactional($run);

        $this->logger?->info($dryRun ? 'Instance settings fallbacks checked (dry run).' : 'Instance settings seeded from fallbacks.', [
            'bundle'   => 'NowoInstanceSettingsBundle',
            'sections' => implode(',', $report->sections),
            'seeded'   => implode(',', $report->seeded),
            'secrets'  => implode(',', $report->secrets),
            'skipped'  => implode(',', array_keys($report->skipped)),
            'origin'   => SettingsChangedEvent::ORIGIN_SEED,
        ]);

        return $report;
    }

    /**
     * Values to write for one section (field => normalized value) and the skipped keys with their reason.
     *
     * @return array{0: array<string, bool|float|int|string>, 1: array<string, string>}
     */
    private function plan(string $name, SectionFallbackProviderInterface $section): array
    {
        $fields = $this->registry->fields($name);
        $stored = [];
        foreach ($this->repository->findBySection($name) as $row) {
            $stored[$row->getName()] = true;
        }

        $values  = [];
        $skipped = [];
        $seen    = [];
        foreach ($section->getFallbacks() as $fallback) {
            $key    = $name . '.' . $fallback->field;
            $reason = $this->skipReason($fallback, $fields, $stored, $seen);
            if ($reason === null) {
                [$clean, $messages] = $this->normalizer->normalize($fields[$fallback->field], $fallback->value);
                if ($messages !== [] || $clean === null) {
                    $reason = SeedReport::SKIP_INVALID;
                } else {
                    $values[$fallback->field] = $clean;
                }
            }
            $seen[] = $fallback->field;
            if ($reason !== null && !array_key_exists($key, $skipped)) {
                $skipped[$key] = $reason;
            }
        }

        return [$values, $skipped];
    }

    /**
     * @param array<string, SettingField> $fields
     * @param array<string, true> $stored
     * @param list<string> $seen
     */
    private function skipReason(SettingFallback $fallback, array $fields, array $stored, array $seen): ?string
    {
        return match (true) {
            in_array($fallback->field, $seen, true)                                                     => SeedReport::SKIP_DUPLICATE,
            !isset($fields[$fallback->field])                                                           => SeedReport::SKIP_UNKNOWN_FIELD,
            $fields[$fallback->field]->secret !== $fallback->secret                                     => SeedReport::SKIP_SECRET_MISMATCH,
            $fallback->value === null || (is_string($fallback->value) && trim($fallback->value) === '') => SeedReport::SKIP_EMPTY,
            isset($stored[$fallback->field])                                                            => SeedReport::SKIP_STORED,
            default                                                                                     => null,
        };
    }
}
