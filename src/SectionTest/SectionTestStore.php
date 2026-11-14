<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;

use function bin2hex;
use function hash;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * The section test state kept in the bundle's own store (1.18): the last report of each tested section / group, the
 * "one test at a time" marker of an actor and a section, and the built-in per-actor rate window.
 *
 * Every row lives in the settings table (`instance_setting_value`) under a reserved section name starting with `_`,
 * which no registered section can use ({@see SettingsSectionRegistry::NAME_PATTERN} starts with a letter): no new
 * table, no migration, no change for a host without a tester. Row names are SHA-256 hashes (64 characters, the column
 * width), so no user identifier is a row name. The settings reads, the raw row reader, exports and seeds never load
 * these rows (audit PU-L6).
 *
 * Concurrency (audit PU-L2 / L3) rests on the storage's atomic primitives ({@see SectionTestStateStorageInterface}):
 * - the rate window is one row per accepted test (slot `0 … limit-1` of the actor's current fixed window): a test is
 *   accepted only when its own insert of a free slot succeeded, so concurrent requests can never exceed the limit and
 *   a lost insert is never counted as accepted; rows of ended windows are removed on the next call;
 * - the running marker holds a random per-request token; a stale marker is taken over with a conditional delete
 *   followed by an insert (one winner), and {@see self::release()} deletes only the marker the caller holds.
 *
 * Stateless (worker-safe): every call reads the store; nothing is memoised.
 *
 * @internal not covered by the backward compatibility promise
 */
final readonly class SectionTestStore
{
    /** Reserved section of the last reports. */
    public const REPORTS = '_section_test_report';

    /** Reserved section of the "test running" markers. */
    public const LOCKS = '_section_test_lock';

    /** Reserved section of the built-in rate window slots. */
    public const RATES = '_section_test_rate';

    /**
     * @param SectionTestStateStorageInterface $storage atomic rows of the reserved sections
     */
    public function __construct(
        private SectionTestStateStorageInterface $storage,
    ) {
    }

    /**
     * The last report of every tested group of `$section`, keyed by group code ('' = the whole section).
     *
     * @return array<string, StoredTestReport>
     */
    public function lastReports(string $section): array
    {
        $reports = [];
        foreach ($this->storage->rows(self::REPORTS) as $row) {
            $report = StoredTestReport::fromJson($row->value);
            if ($report instanceof StoredTestReport && $report->section === $section && $row->name === self::reportName($section, $report->group)) {
                $reports[$report->group ?? ''] = $report;
            }
        }

        return $reports;
    }

    /**
     * Writes the last report of its section / group (replacing the previous one). When another request wrote that
     * report at the same moment, its report stays.
     */
    public function saveReport(StoredTestReport $report): void
    {
        $name     = self::reportName($report->section, $report->group);
        $existing = $this->row(self::REPORTS, $name);
        if ($existing instanceof SectionTestStateRow) {
            $this->storage->update($existing, $report->toJson(), $report->testedAt);

            return;
        }
        $this->storage->insert(self::REPORTS, $name, $report->toJson(), $report->testedAt);
    }

    /**
     * Marks a test of `$section` by the actor `$actorKey` as running, with a new random token. Null when one is already
     * running and was started less than `$staleAfter` seconds ago, or when a concurrent request took over the stale one.
     */
    public function acquire(string $actorKey, string $section, DateTimeImmutable $now, int $staleAfter): ?SectionTestStateRow
    {
        $name   = $this->lockName($actorKey, $section);
        $token  = bin2hex(random_bytes(16));
        $marker = $this->storage->insert(self::LOCKS, $name, $token, $now);
        if ($marker instanceof SectionTestStateRow) {
            return $marker;
        }
        $existing = $this->row(self::LOCKS, $name);
        if (!$existing instanceof SectionTestStateRow || $existing->updatedAt->getTimestamp() > $now->getTimestamp() - $staleAfter) {
            return null;
        }
        // Stale: only the request whose conditional delete removed exactly that marker may insert its own.
        if (!$this->storage->delete($existing)) {
            return null;
        }

        return $this->storage->insert(self::LOCKS, $name, $token, $now);
    }

    /**
     * Removes the caller's own marker; a marker another request took over is left alone.
     */
    public function release(SectionTestStateRow $marker): void
    {
        $this->storage->delete($marker);
    }

    /**
     * Takes one slot of the built-in fixed window of `$actorKey` (`$limit` tests per `$interval` seconds, windows
     * aligned on the epoch). Null when accepted, else the seconds until the window ends.
     */
    public function consume(string $actorKey, int $limit, int $interval, DateTimeImmutable $now): ?int
    {
        $time   = $now->getTimestamp();
        $window = intdiv($time, $interval);
        $ends   = ($window + 1) * $interval;
        $taken  = [];
        foreach ($this->storage->rows(self::RATES) as $row) {
            $stored = is_string($row->value) ? json_decode($row->value, true) : null;
            $until  = is_array($stored) ? ($stored['until'] ?? null) : null;
            if (!is_int($until) || $until <= $time) {
                // An ended window (or a damaged row): pruned, whoever's it is.
                $this->storage->delete($row);

                continue;
            }
            $taken[$row->name] = true;
        }
        for ($slot = 0; $slot < $limit; ++$slot) {
            $name = hash('sha256', $actorKey . "\0" . $window . "\0" . $slot);
            if (!isset($taken[$name]) && $this->storage->insert(self::RATES, $name, json_encode(['until' => $ends], JSON_THROW_ON_ERROR), $now) instanceof SectionTestStateRow) {
                return null;
            }
        }

        return max(1, $ends - $time);
    }

    /**
     * Row name of the report of a section / group.
     */
    public static function reportName(string $section, ?string $group): string
    {
        return hash('sha256', $section . "\0" . ($group ?? ''));
    }

    private function lockName(string $actorKey, string $section): string
    {
        return hash('sha256', $actorKey . "\0" . $section);
    }

    private function row(string $section, string $name): ?SectionTestStateRow
    {
        foreach ($this->storage->rows($section) as $row) {
            if ($row->name === $name) {
                return $row;
            }
        }

        return null;
    }
}
