<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\InstanceSettingsBundle\Doctrine\KeyCollationListener;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_key_first;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function dirname;
use function implode;
use function ksort;
use function sort;
use function str_replace;

use const PHP_VERSION_ID;

/**
 * 1.19 (Beacon spec 239, REQ-SEC-006 rule 3): the key columns of the bundle tables get a binary NO PAD collation on MySQL / MariaDB.
 *
 * Audit XI (1.23.1): the collation is applied on `postGenerateSchema`, once the schema tool has added every foreign key,
 * so the generated schema keeps every foreign key and index of every table.
 */
final class KeyCollationListenerTest extends TestCase
{
    /** @var array<class-string, string> */
    private array $tables = [];

    /**
     * @return iterable<string, array{0: AbstractPlatform, 1: string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'MariaDB' => [new MariaDBPlatform(), 'utf8mb4_nopad_bin'];
        yield 'MySQL 8' => [new MySQL80Platform(), 'utf8mb4_0900_bin'];
        yield 'MySQL 5.7' => [new MySQLPlatform(), 'utf8mb4_bin'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), null];
    }

    /**
     * Lazy connections (nothing connects): the platform comes from `serverVersion`.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function connections(): iterable
    {
        yield 'MariaDB' => [['driver' => 'pdo_mysql', 'serverVersion' => '11.4.3-MariaDB'], 'utf8mb4_nopad_bin'];
        yield 'MySQL 8.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.3'], 'utf8mb4_0900_bin'];
        yield 'MySQL 5.7' => [['driver' => 'pdo_mysql', 'serverVersion' => '5.7.44'], 'utf8mb4_bin'];
        yield 'SQLite' => [['driver' => 'pdo_sqlite', 'memory' => true], null];
    }

    #[DataProvider('platforms')]
    public function testTheCollationOfEachPlatform(AbstractPlatform $platform, ?string $collation): void
    {
        self::assertSame($collation, KeyCollationListener::collationFor($platform));
    }

    #[DataProvider('platforms')]
    public function testTheKeyColumnsOfEveryBundleTable(AbstractPlatform $platform, ?string $collation): void
    {
        $logger = new RecordingLogger();
        $args   = new GenerateSchemaEventArgs($this->manager($platform), $this->schema());
        (new KeyCollationListener(null, $logger))->postGenerateSchema($args);

        foreach (KeyCollationListener::FIELDS as $class => $fields) {
            $table = $args->getSchema()->getTable($this->table($class));
            foreach ($fields as $field) {
                self::assertSame($collation, $this->option($table->getColumn($field), 'collation'), $class . '::' . $field);
            }
            self::assertNull($this->option($table->getColumn('value'), 'collation'), 'only the key columns');
        }
        self::assertCount($collation === 'utf8mb4_bin' ? count(KeyCollationListener::FIELDS) : 0, $logger->records, 'a warning for the PAD SPACE fallback only');
    }

    public function testAManagerThatDoesNotMapTheBundleEntitiesOrLacksTheTablesIsLeftAlone(): void
    {
        $schema = $this->schema();
        $args   = new GenerateSchemaEventArgs($this->manager(new MySQL80Platform(), transient: true), $schema);
        (new KeyCollationListener())->postGenerateSchema($args);
        self::assertSame($schema, $args->getSchema());
        self::assertNull($this->option($schema->getTable($this->table(array_key_first(KeyCollationListener::FIELDS)))->getColumn('section'), 'collation'));

        $empty = new Schema();
        $args  = new GenerateSchemaEventArgs($this->manager(new MySQL80Platform()), $empty);
        (new KeyCollationListener())->postGenerateSchema($args);
        self::assertSame([], $args->getSchema()->getTables());
    }

    /**
     * A table that has foreign keys (the bundle tables have none today) keeps them, and the tables that point to it too.
     */
    public function testForeignKeysOfTheEditedTablesSurviveTheSwap(): void
    {
        $schema = $this->schema();
        $first  = $this->table(array_key_first(KeyCollationListener::FIELDS));
        $owner  = $schema->createTable('host_owner');
        $owner->addColumn('id', Types::INTEGER);
        $owner->setPrimaryKey(['id']);
        $table = $schema->getTable($first);
        $table->addColumn('owner_id', Types::INTEGER, ['notnull' => false]);
        $table->addIndex(['owner_id'], 'idx_host_owner');
        $table->addForeignKeyConstraint('host_owner', ['owner_id'], ['id'], ['onDelete' => 'SET NULL']);
        $pointer = $schema->createTable('host_pointer');
        $pointer->addColumn('key_column', Types::STRING, ['length' => 64]);
        $pointer->addForeignKeyConstraint($first, ['key_column'], ['section']);
        $before = $this->shape($schema);

        $args = new GenerateSchemaEventArgs($this->manager(new MariaDBPlatform()), $schema);
        (new KeyCollationListener())->postGenerateSchema($args);

        self::assertSame($before, $this->shape($args->getSchema()));
        self::assertSame('utf8mb4_nopad_bin', $this->option($args->getSchema()->getTable($first)->getColumn('section'), 'collation'));
    }

    /**
     * The real schema tool flow on every bundle table, with an association on the first table: the generated schema
     * keeps every foreign key and index, as without the listener.
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('connections')]
    public function testEveryForeignKeyAndIndexIsKept(array $params, ?string $collation): void
    {
        $with    = $this->generated($params, true);
        $without = $this->generated($params, false);

        self::assertSame($this->shape($without), $this->shape($with));
        self::assertContains('parent_id->instance_setting_value(id) CASCADE', $this->shape($with)['instance_setting_value']['fks']);
        foreach (KeyCollationListener::FIELDS as $class => $fields) {
            foreach ($fields as $field) {
                self::assertSame($collation, $this->option($with->getTable($this->tables[$class])->getColumn($field), 'collation'), $class . '::' . $field);
            }
        }
    }

    public function testTheFormerTableEventDoesNothing(): void
    {
        $table = $this->schema()->getTable($this->table(array_key_first(KeyCollationListener::FIELDS)));
        $args  = new GenerateSchemaTableEventArgs(new ClassMetadata(array_key_first(KeyCollationListener::FIELDS)), new Schema(), $table);

        (new KeyCollationListener())->postGenerateSchemaTable($args);

        self::assertSame($table, $args->getClassTable());
        self::assertNull($this->option($table->getColumn('section'), 'collation'));
    }

    public function testInPlaceOnAnOlderDbal(): void
    {
        $table = $this->schema()->getTable($this->table(array_key_first(KeyCollationListener::FIELDS)));

        self::assertNull(KeyCollationListener::collate($table, 'section', 'utf8mb4_0900_bin', false));
        self::assertSame('utf8mb4_0900_bin', $this->option($table->getColumn('section'), 'collation'));
        self::assertSame('utf8mb4', $this->option($table->getColumn('section'), 'charset'));
    }

    private function option(Column $column, string $name): mixed
    {
        return $column->getPlatformOptions()[$name] ?? null;
    }

    /**
     * @param class-string $class
     */
    private function table(string $class): string
    {
        return 'tbl_' . str_replace('\\', '_', $class);
    }

    /**
     * One table per mapped entity: its key columns and a `value` column.
     */
    private function schema(): Schema
    {
        $schema = new Schema();
        foreach (KeyCollationListener::FIELDS as $class => $fields) {
            $table = $schema->createTable($this->table($class));
            foreach ($fields as $field) {
                $table->addColumn($field, Types::STRING, ['length' => 64]);
            }
            $table->addColumn('value', Types::TEXT, ['notnull' => false]);
        }

        return $schema;
    }

    /**
     * A manager on a connection of that platform (a stub: nothing connects), mapping every bundle entity to its table.
     */
    private function manager(AbstractPlatform $platform, bool $transient = false): EntityManagerInterface
    {
        $metadata = [];
        foreach (KeyCollationListener::FIELDS as $class => $fields) {
            $metadata[$class] = new ClassMetadata($class);
            $metadata[$class]->setPrimaryTable(['name' => $this->table($class)]);
            foreach ($fields as $field) {
                $metadata[$class]->mapField(['fieldName' => $field, 'columnName' => $field, 'type' => Types::STRING]);
            }
        }
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn($transient);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getMetadataFactory')->willReturn($factory);
        $manager->method('getClassMetadata')->willReturnCallback(static fn (string $class): ClassMetadata => $metadata[$class]);

        return $manager;
    }

    /**
     * The bundle schema from the real mapping of a lazy connection, the first table pointing to itself (metadata built by hand:
     * the entity has no such property).
     *
     * @param array<string, mixed> $params
     */
    private function generated(array $params, bool $listener): Schema
    {
        $this->tables = [];
        $config       = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity', dirname(__DIR__, 3) . '/src/FileEntity', dirname(__DIR__, 3) . '/src/ExpiryEntity', dirname(__DIR__, 3) . '/src/RuntimeEntity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $eventManager = new EventManager();
        if ($listener) {
            $eventManager->addEventListener(ToolEvents::postGenerateSchema, new KeyCollationListener());
        }
        $em      = new EntityManager(DriverManager::getConnection($params, $config), $config, $eventManager);
        $classes = $em->getMetadataFactory()->getAllMetadata();
        foreach (array_keys(KeyCollationListener::FIELDS) as $class) {
            $this->tables[$class] = $em->getClassMetadata($class)->getTableName();
        }
        $first   = array_key_first(KeyCollationListener::FIELDS);
        $classes = array_map(static function (ClassMetadata $metadata) use ($first): ClassMetadata {
            if ($metadata->getName() !== $first) {
                return $metadata;
            }
            $metadata = clone $metadata;
            $metadata->mapManyToOne(['fieldName' => 'parent', 'targetEntity' => $first, 'joinColumns' => [['name' => 'parent_id', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']]]);

            return $metadata;
        }, $classes);

        return (new SchemaTool($em))->getSchemaFromMetadata($classes);
    }

    /**
     * Foreign keys (local columns, foreign table and columns, ON DELETE) and index names per table.
     *
     * @return array<string, array{fks: list<string>, indexes: list<string>}>
     */
    private function shape(Schema $schema): array
    {
        $shape = [];
        foreach ($schema->getTables() as $table) {
            $fks = [];
            foreach ($table->getForeignKeys() as $fk) {
                $fks[] = implode(',', $fk->getLocalColumns()) . '->' . $fk->getForeignTableName() . '(' . implode(',', $fk->getForeignColumns()) . ') ' . ($fk->getOption('onDelete') ?? '-');
            }
            $indexes = array_map(static fn (Index $index): string => $index->getName(), array_values($table->getIndexes()));
            sort($fks);
            sort($indexes);
            $shape[$table->getName()] = ['fks' => $fks, 'indexes' => $indexes];
        }
        ksort($shape);

        return $shape;
    }
}
