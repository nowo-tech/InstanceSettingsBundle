<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Runtime;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Doctrine\SequenceId;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Runtime\InstanceSettingsRuntimeSource;
use Nowo\InstanceSettingsBundle\Runtime\InstanceSettingsRuntimeWriter;
use Nowo\InstanceSettingsBundle\Runtime\RuntimeSettingTables;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Database\RuntimeSettingDatabaseTest;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ChatFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\RuntimeSettingProbeController;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Bridge\Symfony\NowoRuntimeSettingBundle;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\Validator\IntRange;
use Nowo\RuntimeSettingContract\Contract\Validator\SingleLineString;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Throwable;

use function bin2hex;
use function count;
use function hash;
use function json_encode;
use function random_bytes;
use function spl_object_id;
use function str_contains;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use const DATE_ATOM;

/**
 * 1.20, the adopter tests of nowo-tech/runtime-setting-contract on the instance settings: run on SQLite
 * ({@see RuntimeSettingSqliteTest}) and on MySQL / MariaDB / PostgreSQL ({@see RuntimeSettingDatabaseTest}).
 */
abstract class RuntimeSettingScenarios extends TestCase
{
    /** @var list<InstanceSettingsTestKernel> */
    private array $kernels = [];

    private string $run;

    abstract protected function databaseUrl(string $run): string;

    protected function setUp(): void
    {
        $this->run = 'rs_' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir(InstanceSettingsTestKernel::baseDir());
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(InstanceSettingsTestKernel::baseDir());
    }

    /**
     * The mandatory test: a kept-alive worker (no services resetter) with a SHARED cache pool serves the old value while
     * its token is fresh, the new one after version_ttl, for writes of another kernel through the updater, the admin /
     * API path (InstanceSettings::save) and a rotation; same container, no restart.
     */
    public function testAKeptAliveWorkerSeesChangesWithinTheVersionTtl(): void
    {
        $worker = $this->kernel(true);
        $admin  = $this->kernel(false);
        $id     = spl_object_id($worker->getContainer());
        $clock  = $this->service($worker, 'nowo_runtime_setting.clock');
        self::assertInstanceOf(MockClock::class, $clock);
        self::assertFalse($worker->getContainer()->has('services_resetter'));
        self::assertInstanceOf(InstanceSettingsRuntimeSource::class, $this->service($admin, RuntimeValueSourceInterface::class));
        $updater = $this->updater($admin);

        self::assertSame('30', $this->probe($worker, 'chat.retention_days'), 'nothing stored: the configured value');

        $updater->set('chat.retention_days', '45', 'cli:test');
        self::assertSame('30', $this->probe($worker, 'chat.retention_days'), 'the token read by the first request is still fresh');
        $clock->modify('+1001 milliseconds');
        self::assertSame('45', $this->probe($worker, 'chat.retention_days'));
        $item = (new FilesystemAdapter('runtime_setting', 0, $this->poolDirectory()))->getItem('nowo_runtime_setting.' . hash('sha256', 'chat.retention_days'));
        self::assertTrue($item->isHit(), 'the worker filled the shared pool');

        $updater->set('chat.retention_days', '60', 'cli:test');
        self::assertSame('45', $this->probe($worker, 'chat.retention_days'), 'token still fresh: the shared pool serves it');
        $clock->modify('+1001 milliseconds');
        self::assertSame('60', $this->probe($worker, 'chat.retention_days'));

        // The admin / API path bumps the section token in its own unit of work.
        $this->settings($admin)->save('chat', ['retention_days' => 70]);
        $clock->modify('+1001 milliseconds');
        self::assertSame('70', $this->probe($worker, 'chat.retention_days'));

        // A rotation: both secrets accepted inside the window, only the new one after it.
        $updater->set('chat.webhook_secret', 'REDACTED-TEST-OLD', 'cli:test');
        $updater->rotate('chat.webhook_secret', 'REDACTED-TEST-NEW', new DateInterval('PT1H'), 'cli:test');
        self::assertSame('REDACTED-TEST-NEW|REDACTED-TEST-OLD', $this->probe($worker, 'chat.webhook_secret'), 'secrets skip the pool');
        $clock->modify('+1 hour');
        self::assertSame('REDACTED-TEST-NEW', $this->probe($worker, 'chat.webhook_secret'));

        self::assertSame($id, spl_object_id($worker->getContainer()), 'same container: no restart');
    }

    public function testEveryWriteChangesTheSectionTokenAndTheTokenIsNeverReused(): void
    {
        $admin  = $this->kernel(true);
        $tables = $this->service($admin, RuntimeSettingTables::class);
        self::assertInstanceOf(RuntimeSettingTables::class, $tables);
        $updater = $this->updater($admin);
        $seen    = [$tables->token('chat')];
        self::assertSame(RuntimeSettingTables::INITIAL_VERSION, $seen[0]);

        foreach ([
            static fn (): RuntimeSettingChange => $updater->set('chat.retention_days', '45', 'cli:test'),
            static fn (): RuntimeSettingChange => $updater->set('chat.retention_days', '45', 'cli:test'),
            fn (): array => $this->settings($admin)->save('chat', ['welcome' => 'Hi']),
            static fn (): RuntimeSettingChange => $updater->unset('chat.retention_days', 'cli:test'),
            static fn (): RuntimeSettingChange => $updater->unset('chat.retention_days', 'cli:test'),
        ] as $write) {
            $write();
            $token = $tables->token('chat');
            self::assertNotContains($token, $seen);
            $seen[] = $token;
        }
        self::assertSame(RuntimeSettingTables::INITIAL_VERSION, $tables->token('general'), 'another section keeps its token');
        self::assertFalse($this->settings($admin)->isOverridden('chat.retention_days'));
    }

    public function testTheBundleValidatorAlsoGuardsTheAdminPath(): void
    {
        $admin = $this->kernel(true);

        try {
            // 5000 passes the field's own Range (1..36500) but not the registered setting's IntRange (1..1000).
            $this->settings($admin)->save('chat', ['retention_days' => 5000]);
            self::fail('Expected InvalidSettingValueException.');
        } catch (InvalidSettingValueException $e) {
            self::assertStringContainsString('must be between 1 and 1000', (string) json_encode($e->violations));
        }

        $this->expectException(InvalidRuntimeSettingValue::class);
        $this->updater($admin)->set('chat.retention_days', '5000', 'cli:test');
    }

    public function testSecretsStayEncryptedAndThePurgeIsConditional(): void
    {
        $admin   = $this->kernel(true);
        $updater = $this->updater($admin);
        $clock   = $this->service($admin, 'nowo_runtime_setting.clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $updater->set('chat.webhook_secret', 'REDACTED-TEST-OLD', 'cli:test');
        $change = $updater->rotate('chat.webhook_secret', 'REDACTED-TEST-NEW', new DateInterval('PT1H'), 'cli:test');
        self::assertNull($change->newFingerprint, 'no fingerprint of a secret');

        $raw = $this->connection($admin)->fetchOne('SELECT secret_value FROM instance_setting_runtime_previous');
        self::assertIsString($raw);
        self::assertFalse(str_contains($raw, 'REDACTED-TEST-OLD'), 'the previous secret is encrypted at rest');

        self::assertSame(0, $updater->purgeExpired());
        $clock->modify('+1 hour');
        self::assertSame(1, $updater->purgeExpired());
        self::assertSame(0, (int) $this->connection($admin)->fetchOne('SELECT COUNT(*) FROM instance_setting_runtime_previous'));
    }

    public function testRotationExpiryIsStoredAndReadAsUtc(): void
    {
        $admin  = $this->kernel(true);
        $writer = $this->service($admin, InstanceSettingsRuntimeWriter::class);
        $source = $this->service($admin, InstanceSettingsRuntimeSource::class);
        self::assertInstanceOf(InstanceSettingsRuntimeWriter::class, $writer);
        self::assertInstanceOf(InstanceSettingsRuntimeSource::class, $source);
        $definition = $this->registryDefinition($admin, 'chat.webhook_secret');
        $context    = new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter('test-fingerprint-key-0123456789'));
        $writer->rotate($definition, 'REDACTED-TEST-FIRST', new DateTimeImmutable('2030-01-01 00:00:00', new DateTimeZone('UTC')), $context);
        self::assertSame(0, (int) $this->connection($admin)->fetchOne('SELECT COUNT(*) FROM instance_setting_runtime_previous'), 'nothing to keep on the first rotation');
        $writer->set($definition, 'REDACTED-TEST-OLD', $context);

        // An expiry built in New York (a CLI with another date.timezone) is stored and read back as UTC (audit QT-L-1).
        $writer->rotate($definition, 'REDACTED-TEST-NEW', new DateTimeImmutable('2026-11-16 08:00:00', new DateTimeZone('America/New_York')), $context);

        self::assertStringStartsWith('2026-11-16 13:00:00', (string) $this->connection($admin)->fetchOne('SELECT expires_at FROM instance_setting_runtime_previous'));
        $stored = $source->read($definition->id);
        self::assertNotNull($stored);
        self::assertSame('2026-11-16T13:00:00+00:00', $stored->previousExpiresAt?->format(DATE_ATOM));
    }

    public function testRowsOfReservedSectionsChangeNoToken(): void
    {
        $admin  = $this->kernel(true);
        $tables = $this->service($admin, RuntimeSettingTables::class);
        self::assertInstanceOf(RuntimeSettingTables::class, $tables);
        $doctrine = $this->service($admin, 'doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $manager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = $this->connection($admin);
        $connection->insert('instance_setting_value', SequenceId::into(['section' => 'chat', 'name' => 'welcome', 'value' => null, 'updated_at' => '2026-11-16 12:00:00'], $connection, $manager->getClassMetadata(SettingValue::class)));

        $tables->rowsWritten([['_section_lock', 'chat'], ['chat', 'welcome']]);

        self::assertNotSame(RuntimeSettingTables::INITIAL_VERSION, $tables->token('chat'));
        self::assertSame(RuntimeSettingTables::INITIAL_VERSION, $tables->token('_section_lock'));
        $source = $this->service($admin, InstanceSettingsRuntimeSource::class);
        self::assertInstanceOf(InstanceSettingsRuntimeSource::class, $source);
        self::assertNull($source->read($this->registryDefinition($admin, 'chat.welcome')->id), 'a row without a value is unset');
    }

    public function testWritesInsideTheCallersTransactionAreRefused(): void
    {
        $admin      = $this->kernel(true);
        $connection = $this->connection($admin);
        $connection->beginTransaction();

        try {
            $this->updater($admin)->set('chat.retention_days', '45', 'cli:test');
            self::fail('Expected RuntimeSettingNotWritable.');
        } catch (RuntimeSettingNotWritable $e) {
            self::assertStringContainsString('own transaction', $e->getMessage());
        }
        // The connection is the test's own and was never nested: a plain rollback of the level opened above.
        try {
            $connection->rollBack();
        } catch (Throwable) {
            $connection->close();
        }

        $writer = $this->service($admin, InstanceSettingsRuntimeWriter::class);
        self::assertInstanceOf(InstanceSettingsRuntimeWriter::class, $writer);
    }

    public function testValuesOfEveryTypeAndTheFieldRulesStillApply(): void
    {
        $admin   = $this->kernel(true);
        $updater = $this->updater($admin);
        $updater->set('general.ratio', '2.5', 'cli:test');
        $updater->set('general.maintenance_lock', 'true', 'cli:test');
        $source = $this->service($admin, InstanceSettingsRuntimeSource::class);
        self::assertInstanceOf(InstanceSettingsRuntimeSource::class, $source);
        self::assertSame('2.5', $source->read($this->registryDefinition($admin, 'general.ratio')->id)?->current);
        self::assertSame('true', $source->read($this->registryDefinition($admin, 'general.maintenance_lock')->id)?->current);
        self::assertSame(2.5, $this->settings($admin)->getFloat('general.ratio'), 'the instance settings read the same row');

        try {
            // The contract allows any int (Unbounded); the field's own Range (1..100) refuses 500.
            $updater->set('general.page_size', '500', 'cli:test');
            self::fail('Expected InvalidRuntimeSettingValue.');
        } catch (InvalidRuntimeSettingValue $e) {
            self::assertStringContainsString('refused by the rules of instance settings field general.page_size', $e->getMessage());
        }
    }

    public function testWhatTheSourceAndTheWriterDoNotHandle(): void
    {
        $admin  = $this->kernel(true);
        $source = $this->service($admin, InstanceSettingsRuntimeSource::class);
        $writer = $this->service($admin, InstanceSettingsRuntimeWriter::class);
        self::assertInstanceOf(InstanceSettingsRuntimeSource::class, $source);
        self::assertInstanceOf(InstanceSettingsRuntimeWriter::class, $writer);
        $context = new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter('test-fingerprint-key-0123456789'));

        self::assertNull($source->read(RuntimeSettingId::fromString('API_TOKEN')), 'environment-variable ids live in RuntimeEnvBundle');
        self::assertNull($source->read(RuntimeSettingId::fromString('chat.welcome')), 'no row: unset');

        foreach ([
            new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded()),
            new RuntimeSettingDefinition('chat.unknown_field', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded()),
            new RuntimeSettingDefinition('chat.welcome', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new Unbounded()),
        ] as $definition) {
            try {
                $writer->set($definition, 'x', $context);
                self::fail('Expected RuntimeSettingNotWritable for ' . $definition->idString());
            } catch (RuntimeSettingNotWritable) {
            }
        }

        // A secret whose ciphertext does not decrypt to a terminated value (lost key) is unset, like getSecret().
        $updater = $this->updater($admin);
        $updater->set('chat.webhook_secret', 'REDACTED-TEST', 'cli:test');
        $this->connection($admin)->executeStatement("UPDATE instance_setting_value SET secret_value = 'not-a-terminated-value' WHERE name = 'webhook_secret'");
        self::assertNull($source->read($this->registryDefinition($admin, 'chat.webhook_secret')->id));
    }

    public function testAPreviousValueThatDoesNotDecryptIsDropped(): void
    {
        $admin   = $this->kernel(true);
        $updater = $this->updater($admin);
        $updater->set('chat.webhook_secret', 'REDACTED-TEST-OLD', 'cli:test');
        $updater->rotate('chat.webhook_secret', 'REDACTED-TEST-NEW', new DateInterval('PT1H'), 'cli:test');
        $this->connection($admin)->executeStatement("UPDATE instance_setting_runtime_previous SET secret_value = 'garbage<ENC>'");
        $source = $this->service($admin, InstanceSettingsRuntimeSource::class);
        self::assertInstanceOf(InstanceSettingsRuntimeSource::class, $source);

        $stored = $source->read($this->registryDefinition($admin, 'chat.webhook_secret')->id);

        self::assertNotNull($stored);
        self::assertSame('REDACTED-TEST-NEW', $stored->current, 'the current value is still served');
        self::assertNull($stored->previous);
    }

    protected function connection(InstanceSettingsTestKernel $kernel): Connection
    {
        $doctrine = $this->service($kernel, 'doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $manager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager->getConnection();
    }

    private function kernel(bool $createSchema): InstanceSettingsTestKernel
    {
        $pool   = $this->poolDirectory();
        $kernel = new InstanceSettingsTestKernel(
            $this->run . '_' . count($this->kernels),
            static function (ContainerConfigurator $container) use ($pool): void {
                $container->extension('nowo_instance_settings', ['runtime_setting' => ['enabled' => true]]);
                $container->extension('nowo_runtime_setting', ['cache_pool' => 'test.shared_pool', 'version_ttl' => 1, 'fingerprint_key' => 'test-fingerprint-key-0123456789', 'command' => ['enabled' => true]]);
                $services = $container->services();
                $services->defaults()->autowire()->autoconfigure();
                $services->set(MailerFixtureSection::class);
                $services->set(GeneralFixtureSection::class);
                $services->set(ChatFixtureSection::class);
                $services->set(RuntimeSettingProbeController::class)->public();
                $services->set('test.shared_pool', FilesystemAdapter::class)->args(['runtime_setting', 0, $pool]);
                $services->set('nowo_runtime_setting.clock', MockClock::class)->args(['2026-11-16 12:00:00', 'UTC'])->public();
                $services->alias('clock', 'nowo_runtime_setting.clock')->public();
                $services->set('test.retention_range', IntRange::class)->args([1, 1000]);
                $services->set('test.single_line', SingleLineString::class)->args([128]);
                $services->set('test.unbounded', Unbounded::class);
                $services->set('test.definition.retention', RuntimeSettingDefinition::class)
                    ->args(['chat.retention_days', RuntimeSettingType::Int, RuntimeSettingSource::DbThenEnv, RuntimeSettingErrorPolicy::FailClosed, false, service('test.retention_range'), 30])
                    ->tag(RuntimeSettingsConfiguration::DEFINITION_TAG);
                $services->set('test.definition.webhook', RuntimeSettingDefinition::class)
                    ->args(['chat.webhook_secret', RuntimeSettingType::String, RuntimeSettingSource::Db, RuntimeSettingErrorPolicy::FailClosed, true, service('test.single_line')])
                    ->tag(RuntimeSettingsConfiguration::DEFINITION_TAG);
                $services->set('test.definition.ratio', RuntimeSettingDefinition::class)
                    ->args(['general.ratio', RuntimeSettingType::Float, RuntimeSettingSource::Db, RuntimeSettingErrorPolicy::FailClosed, false, service('test.unbounded')])
                    ->tag(RuntimeSettingsConfiguration::DEFINITION_TAG);
                $services->set('test.definition.lock', RuntimeSettingDefinition::class)
                    ->args(['general.maintenance_lock', RuntimeSettingType::Bool, RuntimeSettingSource::Db, RuntimeSettingErrorPolicy::FailClosed, false, service('test.unbounded')])
                    ->tag(RuntimeSettingsConfiguration::DEFINITION_TAG);
                $services->set('test.definition.page_size', RuntimeSettingDefinition::class)
                    ->args(['general.page_size', RuntimeSettingType::Int, RuntimeSettingSource::Db, RuntimeSettingErrorPolicy::FailClosed, false, service('test.unbounded')])
                    ->tag(RuntimeSettingsConfiguration::DEFINITION_TAG);
                $services->set('test.definition.welcome', RuntimeSettingDefinition::class)
                    ->args(['chat.welcome', RuntimeSettingType::String, RuntimeSettingSource::DbThenEnv, RuntimeSettingErrorPolicy::FailClosed, false, service('test.unbounded'), 'Hello'])
                    ->tag(RuntimeSettingsConfiguration::DEFINITION_TAG);
            },
            static function (RoutingConfigurator $routes): void {
                $routes->add('t_runtime', '/t/runtime/{id}')->controller(RuntimeSettingProbeController::class);
            },
            [],
            false,
            false,
            $this->databaseUrl($this->run),
            $this->run,
            [new NowoRuntimeSettingBundle()],
            true,
        );
        $this->kernels[] = $kernel;
        $kernel->boot();

        if ($createSchema) {
            $doctrine = $this->service($kernel, 'doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $doctrine);
            $manager = $doctrine->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $tool = new SchemaTool($manager);
            $tool->dropDatabase();
            $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }

        return $kernel;
    }

    private function poolDirectory(): string
    {
        return InstanceSettingsTestKernel::baseDir() . '/' . $this->run . '/pool';
    }

    private function updater(InstanceSettingsTestKernel $kernel): RuntimeSettingUpdater
    {
        $updater = $this->service($kernel, RuntimeSettingUpdater::class);
        self::assertInstanceOf(RuntimeSettingUpdater::class, $updater);

        return $updater;
    }

    private function settings(InstanceSettingsTestKernel $kernel): InstanceSettings
    {
        $settings = $this->service($kernel, InstanceSettings::class);
        self::assertInstanceOf(InstanceSettings::class, $settings);

        return $settings;
    }

    private function registryDefinition(InstanceSettingsTestKernel $kernel, string $id): RuntimeSettingDefinition
    {
        $registry = $this->service($kernel, RuntimeSettingRegistry::class);
        self::assertInstanceOf(RuntimeSettingRegistry::class, $registry);

        return $registry->get($id);
    }

    private function service(InstanceSettingsTestKernel $kernel, string $id): mixed
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container->get($id);
    }

    private function probe(InstanceSettingsTestKernel $kernel, string $id): string
    {
        $request  = Request::create('/t/runtime/' . $id);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return (string) $response->getContent();
    }
}
