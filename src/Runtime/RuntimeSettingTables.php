<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\RuntimeEntity\RuntimeSettingPrevious;
use Nowo\InstanceSettingsBundle\RuntimeEntity\RuntimeSettingVersion;

use function array_unique;
use function array_values;
use function bin2hex;
use function is_array;
use function is_string;
use function random_bytes;
use function sort;
use function sprintf;
use function str_starts_with;

use const SORT_STRING;

/**
 * 1.20 (`runtime_setting.enabled`): the DBAL side of the runtime-setting tables, on the connection of the bundle's private
 * entity manager (table and column names from the mapping, so `doctrine.table_prefix` applies).
 *
 * - {@see self::bump()} writes a new, never reused token for each section, inside the caller's unit of work: the row is
 *   ensured with a statement that cannot conflict (`INSERT … ON DUPLICATE KEY UPDATE` on MySQL / MariaDB, `INSERT … ON
 *   CONFLICT DO NOTHING` on PostgreSQL / SQLite; audit QT-L-3), then updated, in ascending section order.
 * - {@see self::rowsWritten()} is called by the settings repository after every flush of setting rows (admin form, API,
 *   import, seed): the previous value of a rotation of those fields is dropped (a plain write replaces it) and the sections
 *   are bumped, in the same transaction.
 * - Rotation expiries are UTC wall-clock time (audit QT-L-1).
 *
 * Stateless: the private manager is resolved per call.
 */
final readonly class RuntimeSettingTables
{
    /** Token of a section that was never written. */
    public const INITIAL_VERSION = '0';

    private const ENSURE_MYSQL = 'INSERT INTO %s (section, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE section = section';

    private const ENSURE_STANDARD = 'INSERT INTO %s (section, token) VALUES (?, ?) ON CONFLICT (section) DO NOTHING';

    public function __construct(
        private SettingsEntityManager $entityManager,
    ) {
    }

    public function connection(): Connection
    {
        return $this->entityManager->get()->getConnection();
    }

    /**
     * @return array{table: string, section: string, name: string, value: string, secret: string}
     */
    public function settingsColumns(): array
    {
        $metadata = $this->entityManager->get()->getClassMetadata(SettingValue::class);

        return [
            'table'   => $metadata->getTableName(),
            'section' => $metadata->getColumnName('section'),
            'name'    => $metadata->getColumnName('name'),
            'value'   => $metadata->getColumnName('value'),
            'secret'  => $metadata->getColumnName('secretValue'),
        ];
    }

    public function previousTable(): string
    {
        return $this->entityManager->get()->getClassMetadata(RuntimeSettingPrevious::class)->getTableName();
    }

    public function versionsTable(): string
    {
        return $this->entityManager->get()->getClassMetadata(RuntimeSettingVersion::class)->getTableName();
    }

    /**
     * The committed token of `$section` ({@see self::INITIAL_VERSION} when never written), compared byte for byte.
     */
    public function token(string $section): string
    {
        $row = $this->connection()->fetchAssociative(sprintf('SELECT section, token FROM %s WHERE section = ?', $this->versionsTable()), [$section]);

        return is_array($row) && $row['section'] === $section && is_string($row['token']) ? $row['token'] : self::INITIAL_VERSION;
    }

    /**
     * New tokens for `$sections` inside the open unit of work (callers pass registered sections only).
     *
     * @param list<string> $sections
     *
     * @return array<string, string> section => new token
     */
    public function bump(array $sections): array
    {
        $sections = array_values(array_unique($sections));
        sort($sections, SORT_STRING);
        $connection = $this->connection();
        $table      = $this->versionsTable();
        $ensure     = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? self::ENSURE_MYSQL : self::ENSURE_STANDARD;

        $tokens = [];
        foreach ($sections as $section) {
            $connection->executeStatement(sprintf($ensure, $table), [$section, self::INITIAL_VERSION]);
            $token = bin2hex(random_bytes(16));
            $connection->update($table, ['token' => $token], ['section' => $section]);
            $tokens[$section] = $token;
        }

        return $tokens;
    }

    /**
     * After a flush of setting rows: drops the previous values of those fields and bumps their sections.
     *
     * @param list<array{0: string, 1: string}> $rows section, name
     */
    public function rowsWritten(array $rows): void
    {
        $sections = [];
        foreach ($rows as [$section, $name]) {
            if (str_starts_with($section, '_')) {
                continue;
            }
            $this->dropPrevious($section, $name);
            $sections[] = $section;
        }
        if ($sections !== []) {
            $this->bump($sections);
        }
    }

    public function dropPrevious(string $section, string $name): void
    {
        $this->connection()->delete($this->previousTable(), ['section' => $section, 'name' => $name]);
    }

    /**
     * Deletes the previous values that expired at `$now` (UTC), of one field or of the whole table (a conditional
     * `DELETE`); returns how many rows went and the sections touched.
     *
     * @return array{0: int, 1: list<string>}
     */
    public function purge(DateTimeImmutable $now, ?string $section = null, ?string $name = null): array
    {
        $connection = $this->connection();
        $table      = $this->previousTable();
        $where      = 'expires_at <= ?';
        $params     = [self::utc($now)];
        $types      = [Types::DATETIME_IMMUTABLE];
        if ($section !== null) {
            $where .= ' AND section = ? AND name = ?';
            $params[] = $section;
            $params[] = (string) $name;
            $types[]  = Types::STRING;
            $types[]  = Types::STRING;
        }

        /** @var list<string> $sections */
        $sections = $connection->fetchFirstColumn(sprintf('SELECT DISTINCT section FROM %s WHERE %s', $table, $where), $params, $types);
        $deleted  = $sections === [] ? 0 : (int) $connection->executeStatement(sprintf('DELETE FROM %s WHERE %s', $table, $where), $params, $types);

        return [$deleted, $sections];
    }

    /**
     * UTC wall-clock time for the zone-less expiry column (audit QT-L-1).
     */
    public static function utc(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
