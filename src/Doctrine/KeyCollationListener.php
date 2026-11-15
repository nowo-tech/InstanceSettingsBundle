<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\ExpiryEntity\SecretExpiry;
use Nowo\InstanceSettingsBundle\FileEntity\SettingFile;
use Psr\Log\LoggerInterface;

use function method_exists;

/**
 * Exact-match collation of the key columns (`section`, `name`) of the bundle tables on MySQL / MariaDB (1.19, Beacon
 * spec 239, REQ-SEC-006 rule 3).
 *
 * The default MySQL / MariaDB collations fold case and accents, and the PAD SPACE ones ignore trailing spaces, so a
 * section `General` or `general ` equals `general`, also in the `(section, name)` unique keys. The bundle re-checks every
 * row it reads with `===` either way; the binary NO PAD collation keeps the database itself exact: MariaDB
 * `utf8mb4_nopad_bin`, MySQL 8.0+ `utf8mb4_0900_bin`, MySQL 5.7 `utf8mb4_bin` (PAD SPACE, the only binary one it has; the
 * re-check covers it, and a warning is logged). PostgreSQL and SQLite compare exactly and are untouched (they reject MySQL
 * collation names, so it cannot live in the mapping). Applied to the generated schema (schema tool,
 * `doctrine:migrations:diff`); existing tables keep theirs until the optional `ALTER TABLE` of UPGRADING.
 */
final readonly class KeyCollationListener
{
    /** Mapped key fields per bundle entity. */
    public const FIELDS = [
        SettingValue::class => ['section', 'name'],
        SecretExpiry::class => ['section', 'name'],
        SettingFile::class  => ['section', 'name'],
    ];

    /**
     * @param ManagerRegistry $registry Doctrine registry (the manager that maps the bundle entity decides the collation)
     * @param LoggerInterface|null $logger warned when only the PAD SPACE `utf8mb4_bin` is available
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The exact-match collation on that platform, null when its default is already exact.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform       => 'utf8mb4_0900_bin',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_bin',
            default                                    => null,
        };
    }

    /**
     * Gives the key columns of a bundle table their binary collation.
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $fields   = self::FIELDS[$metadata->getName()] ?? [];
        if ($fields === []) {
            return;
        }
        $manager   = $this->registry->getManagerForClass($metadata->getName());
        $collation = $manager instanceof EntityManagerInterface ? self::collationFor($manager->getConnection()->getDatabasePlatform()) : null;
        if ($collation === null) {
            return;
        }
        if ($collation === 'utf8mb4_bin') {
            $this->logger?->warning('Instance settings: the key columns of {table} get the PAD SPACE collation utf8mb4_bin (no binary NO PAD collation detected: MySQL before 8.0, or a doctrine server_version without the full version); every match is re-checked in PHP.', [
                'bundle' => 'NowoInstanceSettingsBundle',
                'table'  => $metadata->getTableName(),
            ]);
        }

        $table = $args->getClassTable();
        foreach ($fields as $field) {
            /** @var non-empty-string $column mapped column names are never empty */
            $column = $metadata->getColumnName($field);
            // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
            $edited = self::collate($table, $column, $collation, method_exists(Table::class, 'edit'));
            if ($edited instanceof Table) {
                $table = $edited;
            }
        }
        if ($table !== $args->getClassTable()) {
            $args->setClassTable($table);
        }
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
     * in place (older DBAL; returns null).
     *
     * @param non-empty-string $column
     * @param non-empty-string $collation
     */
    public static function collate(Table $table, string $column, string $collation, bool $editor): ?Table
    {
        if ($editor) {
            return $table->edit()->modifyColumnByUnquotedName($column, static function (ColumnEditor $columnEditor) use ($collation): void {
                $columnEditor->setCharset('utf8mb4')->setCollation($collation);
            })->create();
        }

        $table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }
}
