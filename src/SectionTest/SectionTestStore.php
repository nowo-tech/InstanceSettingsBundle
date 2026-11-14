<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;

use function hash;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;

use const JSON_THROW_ON_ERROR;

/**
 * The section test state kept in the bundle's own store (1.18): the last report of each tested section / group, the
 * "one test at a time" marker of an actor and a section, and the built-in per-actor rate window.
 *
 * Every row lives in the settings table (`instance_setting_value`) under a reserved section name starting with `_`,
 * which no registered section can use ({@see SettingsSectionRegistry::NAME_PATTERN} starts with a letter): no new
 * table, no migration, no change for a host without a tester. Row names are SHA-256 hashes (64 characters, the column
 * width): of the section and group for a report, of the salted actor key for the marker and the rate window, so no
 * user identifier is a row name. Exports and seeds only read registered sections, so these rows are never exported.
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

    /** Reserved section of the built-in rate windows. */
    public const RATES = '_section_test_rate';

    /**
     * @param SettingValueRepositoryInterface $repository the bundle's store
     */
    public function __construct(
        private SettingValueRepositoryInterface $repository,
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
        foreach ($this->repository->findBySection(self::REPORTS) as $row) {
            $report = StoredTestReport::fromJson($row->getValue());
            if ($report instanceof StoredTestReport && $report->section === $section && $row->getName() === self::reportName($section, $report->group)) {
                $reports[$report->group ?? ''] = $report;
            }
        }

        return $reports;
    }

    /**
     * Writes the last report of its section / group (replacing the previous one).
     *
     * @throws UniqueConstraintViolationException when another request wrote the first report of that group at the same time
     */
    public function saveReport(StoredTestReport $report): void
    {
        $name = self::reportName($report->section, $report->group);
        $row  = $this->row(self::REPORTS, $name) ?? new SettingValue(self::REPORTS, $name, $report->testedAt);
        $this->repository->apply([$row->storeValue($report->toJson(), $report->testedAt)], []);
    }

    /**
     * Marks a test of `$section` by the actor `$actorKey` as running. False when one is already running and was started
     * less than `$staleAfter` seconds ago (an older marker is from a request that died, and is taken over).
     */
    public function acquire(string $actorKey, string $section, DateTimeImmutable $now, int $staleAfter): bool
    {
        $name     = $this->lockName($actorKey, $section);
        $existing = $this->row(self::LOCKS, $name);
        if ($existing instanceof SettingValue) {
            if ($existing->getUpdatedAt()->getTimestamp() > $now->getTimestamp() - $staleAfter) {
                return false;
            }
            $this->repository->apply([$existing->storeValue(1, $now)], []);

            return true;
        }

        try {
            $this->repository->apply([(new SettingValue(self::LOCKS, $name, $now))->storeValue(1, $now)], []);
        } catch (UniqueConstraintViolationException) {
            // Another request of the same actor inserted the marker first.
            return false;
        }

        return true;
    }

    /**
     * Removes the "test running" marker of the actor and the section.
     */
    public function release(string $actorKey, string $section): void
    {
        $existing = $this->row(self::LOCKS, $this->lockName($actorKey, $section));
        if ($existing instanceof SettingValue) {
            $this->repository->apply([], [$existing]);
        }
    }

    /**
     * Consumes one test of the built-in fixed window of `$actorKey` (`$limit` tests per `$interval` seconds). Null when
     * accepted, else the seconds until the window ends.
     */
    public function consume(string $actorKey, int $limit, int $interval, DateTimeImmutable $now): ?int
    {
        $row    = $this->row(self::RATES, $actorKey);
        $window = ['start' => $now->getTimestamp(), 'count' => 0];
        if ($row instanceof SettingValue) {
            $stored = is_string($row->getValue()) ? json_decode($row->getValue(), true) : null;
            if (is_array($stored) && is_int($stored['start'] ?? null) && is_int($stored['count'] ?? null) && $stored['start'] > $now->getTimestamp() - $interval) {
                $window = ['start' => $stored['start'], 'count' => $stored['count']];
            }
        }
        if ($window['count'] >= $limit) {
            return max(1, $window['start'] + $interval - $now->getTimestamp());
        }
        ++$window['count'];
        $row ??= new SettingValue(self::RATES, $actorKey, $now);

        try {
            $this->repository->apply([$row->storeValue(json_encode($window, JSON_THROW_ON_ERROR), $now)], []);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first test of the same actor created the window: this one counts as accepted.
        }

        return null;
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

    private function row(string $section, string $name): ?SettingValue
    {
        foreach ($this->repository->findBySection($section) as $row) {
            if ($row->getName() === $name) {
                return $row;
            }
        }

        return null;
    }
}
