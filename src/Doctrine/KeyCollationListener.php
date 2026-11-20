<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\ExpiryEntity\SecretExpiry;
use Nowo\InstanceSettingsBundle\FileEntity\SettingFile;
use Nowo\InstanceSettingsBundle\RuntimeEntity\RuntimeSettingPrevious;
use Nowo\InstanceSettingsBundle\RuntimeEntity\RuntimeSettingVersion;
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
 *
 * 1.23.1 (audit XI): applied on `postGenerateSchema`, once the schema tool has added every foreign key, by table name.
 * Earlier versions replaced each table on `postGenerateSchemaTable` (`setClassTable()`, DBAL 4.5+), and ORM 3.7–3.8's
 * schema tool then added the table's foreign keys to the table it had replaced, losing them on MySQL / MariaDB (latent:
 * the bundle tables have no foreign key today); with ORM 3.6 and DBAL 4.5+ `setClassTable()` does not exist at all, so
 * the listener was fatal there. Now:
 *
 * - DBAL 4.5+ with an ORM that has `GenerateSchemaEventArgs::setSchema()`: each table is rebuilt with its table editor
 *   (foreign keys and indexes included) and swapped into a new schema given back with `setSchema()`;
 * - otherwise (older DBAL, ORM 2 / 3.6): the column's platform options are set in place.
 */
final readonly class KeyCollationListener
{
    /** Mapped key fields per bundle entity. */
    public const FIELDS = [
        SettingValue::class => ['section', 'name'],
        SecretExpiry::class => ['section', 'name'],
        SettingFile::class  => ['section', 'name'],
        // 1.20 (runtime_setting.enabled; mapped only then, so nothing changes without it).
        RuntimeSettingVersion::class  => ['section'],
        RuntimeSettingPrevious::class => ['section', 'name'],
    ];

    /**
     * @param ManagerRegistry|null $registry unused since 1.23.1 (the platform comes from the schema tool's entity manager);
     *                                       kept for BC
     * @param LoggerInterface|null $logger warned when only the PAD SPACE `utf8mb4_bin` is available
     */
    public function __construct(
        ?ManagerRegistry $registry = null,
        private ?LoggerInterface $logger = null,
    ) {
        unset($registry);
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
     * Gives the key columns of the bundle tables their binary collation in the generated schema, keeping every foreign key
     * and index of the tables.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $entityManager = $args->getEntityManager();
        $collation     = null;
        $schema        = $args->getSchema();
        // the editor path replaces the tables in a new schema: it needs Table::edit() and Schema::edit() (DBAL 4.5+) and an
        // ORM that accepts the new schema (3.7+)
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType, function.alreadyNarrowedType, booleanAnd.alwaysTrue (older doctrine/dbal / doctrine/orm)
        $editor       = method_exists(Table::class, 'edit') && method_exists(Schema::class, 'edit') && method_exists($args, 'setSchema');
        $replacements = [];
        foreach (self::FIELDS as $class => $fields) {
            if ($entityManager->getMetadataFactory()->isTransient($class)) {
                continue;
            }
            $collation ??= self::collationFor($entityManager->getConnection()->getDatabasePlatform()) ?? false;
            if ($collation === false) {
                return;
            }
            $metadata  = $entityManager->getClassMetadata($class);
            $tableName = $metadata->getTableName();
            if ($tableName === '' || !$schema->hasTable($tableName)) {
                continue;
            }
            if ($collation === 'utf8mb4_bin') {
                $this->logger?->warning('Instance settings: the key columns of {table} get the PAD SPACE collation utf8mb4_bin (no binary NO PAD collation detected: MySQL before 8.0, or a doctrine server_version without the full version); every match is re-checked in PHP.', [
                    'bundle' => 'NowoInstanceSettingsBundle',
                    'table'  => $tableName,
                ]);
            }

            $original = $table = $schema->getTable($tableName);
            foreach ($fields as $field) {
                /** @var non-empty-string $column mapped column names are never empty */
                $column = $metadata->getColumnName($field);
                $edited = self::collate($table, $column, $collation, $editor);
                if ($edited instanceof Table) {
                    $table = $edited;
                }
            }
            if ($table !== $original) {
                $replacements[$tableName] = $table;
            }
        }

        if ($replacements !== []) {
            $editing = $schema->edit();
            foreach ($replacements as $tableName => $table) {
                $editing->dropTableByUnquotedName($tableName)->addTable($table);
            }
            $args->setSchema($editing->create());
        }
    }

    /**
     * No longer subscribed (1.23.1, audit XI): replacing the tables there lost their foreign keys. Kept for BC; does nothing.
     *
     * @deprecated since 1.23.1, the collation is applied on postGenerateSchema ({@see self::postGenerateSchema()})
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        unset($args);
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

        // DBAL 4.5 deprecates the setters for the editors, and no editor changes a table in place (ORM 3.6 cannot swap it)
        @$table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }
}
