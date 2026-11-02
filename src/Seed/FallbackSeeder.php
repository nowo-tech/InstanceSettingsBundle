<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Seed;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SectionFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

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
 * - Insert-only writes in one transaction, with the same field validation ({@see ValueNormalizer}) as a save; secrets
 *   are stored with {@see SettingValue::storeSecret()} (encrypted at rest). A concurrent admin save always wins.
 * - Audited like a save (`Instance settings saved.` log line, {@see SettingsChangedEvent} with origin
 *   {@see SettingsChangedEvent::ORIGIN_SEED}), but only **after the commit**: a rolled-back seed dispatches nothing.
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
     * @param InstanceSettingsInterface $settings store (its request memo is reset after a seed)
     * @param ValueNormalizer $normalizer per-field validation before the write
     * @param LoggerInterface|null $logger receives one summary line per run (names only)
     * @param TransactionStateGuard|null $transactionGuard refuses to write on a phantom DBAL transaction (MySQL DDL)
     * @param EventDispatcherInterface|null $dispatcher receives one {@see SettingsChangedEvent} per seeded section, after the commit
     * @param ClockInterface|null $clock `updated_at` of the inserted rows
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private SettingValueRepositoryInterface $repository,
        private InstanceSettingsInterface $settings,
        private ValueNormalizer $normalizer = new ValueNormalizer(),
        private ?LoggerInterface $logger = null,
        private ?TransactionStateGuard $transactionGuard = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /** Attempts of a seed whose insert lost a unique-key race (the next attempt sees the concurrent row). */
    private const ATTEMPTS = 2;

    /**
     * Seeds the fallbacks of `$sections` (null = every section implementing the fallback interface).
     *
     * Plans outside the transaction (stored check + fallbacks), then writes **insert-only** inside one transaction: each
     * field is re-checked there and a row that exists by then (an admin save that won the race) is skipped, never
     * updated. A unique-key violation (a row committed after that re-check) rolls the attempt back and the seed runs
     * once more, so the concurrent value is kept. {@see SettingsChangedEvent}s are dispatched only after the commit.
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

        if (!$dryRun) {
            $this->transactionGuard?->check();
        }

        $attempt = 0;
        while (true) {
            try {
                [$report, $events] = $this->attempt($names, $dryRun);
                break;
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }

        if ($events !== [] && $this->settings instanceof ResetInterface) {
            $this->settings->reset();
        }
        foreach ($events as $event) {
            $this->logger?->info('Instance settings saved.', [
                'bundle'  => 'NowoInstanceSettingsBundle',
                'section' => $event->section,
                'fields'  => implode(',', $event->changedFields),
                'origin'  => SettingsChangedEvent::ORIGIN_SEED,
            ]);
            $this->dispatcher?->dispatch($event);
        }

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
     * One seed attempt: the report and the events to dispatch after the commit.
     *
     * @param list<string> $names
     *
     * @return array{0: SeedReport, 1: list<SettingsChangedEvent>}
     */
    private function attempt(array $names, bool $dryRun): array
    {
        $examined = [];
        $skipped  = [];
        $plans    = [];
        foreach ($names as $name) {
            $section = $this->registry->get($name);
            if (!$section instanceof SectionFallbackProviderInterface) {
                continue;
            }
            $examined[]                = $name;
            [$values, $sectionSkipped] = $this->plan($name, $section);
            $skipped                   = array_merge($skipped, $sectionSkipped);
            if ($values !== []) {
                $plans[$name] = $values;
            }
        }

        if ($dryRun) {
            $seeded  = [];
            $secrets = [];
            foreach ($plans as $name => $values) {
                $fields = $this->registry->fields($name);
                foreach (array_keys($values) as $field) {
                    $seeded[] = $name . '.' . $field;
                    if ($fields[$field]->secret) {
                        $secrets[] = $name . '.' . $field;
                    }
                }
            }

            return [new SeedReport($examined, $seeded, $skipped, $secrets, true), []];
        }

        /** @var array{0: list<string>, 1: list<string>, 2: array<string, string>, 3: list<SettingsChangedEvent>} $written */
        $written                            = $plans === [] ? [[], [], [], []] : $this->repository->transactional(fn (): array => $this->insertMissing($plans));
        [$seeded, $secrets, $lost, $events] = $written;

        return [new SeedReport($examined, $seeded, array_merge($skipped, $lost), $secrets, false), $events];
    }

    /**
     * Inserts the planned values whose row is still missing (re-checked inside the transaction); never updates a row.
     *
     * @param array<string, array<string, bool|float|int|string>> $plans section => field => value
     *
     * @return array{0: list<string>, 1: list<string>, 2: array<string, string>, 3: list<SettingsChangedEvent>}
     */
    private function insertMissing(array $plans): array
    {
        $now     = $this->clock?->now() ?? new DateTimeImmutable();
        $seeded  = [];
        $secrets = [];
        $lost    = [];
        $events  = [];
        foreach ($plans as $name => $values) {
            $fields = $this->registry->fields($name);
            $stored = [];
            foreach ($this->repository->findBySection($name) as $row) {
                $stored[$row->getName()] = true;
            }

            $rows          = [];
            $changed       = [];
            $changedSecret = [];
            foreach ($values as $field => $value) {
                $key = $name . '.' . $field;
                if (isset($stored[$field])) {
                    $lost[$key] = SeedReport::SKIP_STORED;

                    continue;
                }
                $row = new SettingValue($name, $field, $now);
                if ($fields[$field]->secret) {
                    $rows[]          = $row->storeSecret((string) $value, $now);
                    $changedSecret[] = $field;
                    $secrets[]       = $key;
                } else {
                    $rows[] = $row->storeValue($value, $now);
                }
                $changed[] = $field;
                $seeded[]  = $key;
            }

            if ($rows !== []) {
                $this->repository->apply($rows, []);
                $events[] = new SettingsChangedEvent($name, $changed, $changedSecret, SettingsChangedEvent::ORIGIN_SEED);
            }
        }

        return [$seeded, $secrets, $lost, $events];
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
                [$clean, $messages] = $this->normalizer->normalize($fields[$fallback->field], $fallback->value());
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
            in_array($fallback->field, $seen, true)                                                           => SeedReport::SKIP_DUPLICATE,
            !isset($fields[$fallback->field])                                                                 => SeedReport::SKIP_UNKNOWN_FIELD,
            $fields[$fallback->field]->secret !== $fallback->secret                                           => SeedReport::SKIP_SECRET_MISMATCH,
            $fallback->value() === null || (is_string($fallback->value()) && trim($fallback->value()) === '') => SeedReport::SKIP_EMPTY,
            isset($stored[$fallback->field])                                                                  => SeedReport::SKIP_STORED,
            default                                                                                           => null,
        };
    }
}
