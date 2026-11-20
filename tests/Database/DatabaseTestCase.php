<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Database;

use Closure;
use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Database\Support\HookState;
use Nowo\InstanceSettingsBundle\Tests\Database\Support\StatementHook;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\AppearancePortabilityProvider;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function count;
use function getenv;
use function is_string;
use function json_decode;
use function preg_replace;
use function str_starts_with;

/**
 * Kernels on one real database (1.19, Beacon spec 239): `IS_TEST_DATABASE_URL` (MySQL / MariaDB / PostgreSQL), skipped
 * without it (the CI job runs with `--fail-on-skipped`). Every kernel of a test shares the database and the encryption
 * key, so one kernel is "this request" and another the concurrent one. The schema is created again for every test.
 */
abstract class DatabaseTestCase extends TestCase
{
    /** @var list<InstanceSettingsTestKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        $url = getenv('IS_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('IS_TEST_DATABASE_URL is not set (a MySQL / MariaDB / PostgreSQL database for the race tests).');
        }
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
     * A kernel on the test database. The first one of a test creates the schema again.
     *
     * @param array<string, mixed> $config nowo_instance_settings configuration
     * @param array<string, string> $services extra service id => class (autoconfigured, public)
     * @param bool $hook this kernel's connection runs {@see StatementHook}
     * @param (Closure(RoutingConfigurator): void)|null $routes extra routes (the bundle's routes are always imported)
     * @param list<BundleInterface> $extraBundles more bundles (e.g. NowoTransactionDoctrineBundle for request-level commit detection)
     */
    protected function boot(array $config = [], array $services = [], bool $hook = false, ?Closure $routes = null, array $extraBundles = []): InstanceSettingsTestKernel
    {
        $id     = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel = new InstanceSettingsTestKernel(
            $id,
            static function (ContainerConfigurator $container) use ($config, $services, $hook, $id): void {
                $container->extension('nowo_instance_settings', $config);
                // One container class per kernel: kernels with the same configuration never share dumped classes.
                $container->parameters()->set('nowo_test.kernel_id', $id);
                $defaults = $container->services()->defaults()->autowire()->autoconfigure();
                $defaults->set(MailerFixtureSection::class);
                $defaults->set(GeneralFixtureSection::class);
                $defaults->set(AppearancePortabilityProvider::class)->public();
                foreach ($services as $serviceId => $class) {
                    $defaults->set($serviceId, $class)->public();
                }
                if ($hook) {
                    $defaults->set(HookState::class)->public();
                    $defaults->set(StatementHook::class)->tag('doctrine.middleware');
                }
            },
            static function (RoutingConfigurator $configurator) use ($routes): void {
                $configurator->import('.', 'nowo_instance_settings');
                if ($routes instanceof Closure) {
                    $routes($configurator);
                }
            },
            databaseUrl: (string) getenv('IS_TEST_DATABASE_URL'),
            keyId: 'database-shared',
            extraBundles: $extraBundles,
        );
        $first           = $this->kernels === [];
        $this->kernels[] = $kernel;
        $kernel->boot();
        if (str_starts_with((string) InstalledVersions::getVersion('doctrine/dbal'), '3.')) {
            // DBAL 3: nested units on savepoints, as HIGH-LOAD.md asks of a host that calls the store inside its own transactions.
            $this->connection($kernel)->setNestTransactionsWithSavepoints(true);
        }
        if ($first) {
            $doctrine = $kernel->getContainer()->get('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $doctrine);
            $manager = $doctrine->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $metadata = $manager->getMetadataFactory()->getAllMetadata();
            $tool     = new SchemaTool($manager);
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }

        return $kernel;
    }

    protected function container(InstanceSettingsTestKernel $kernel): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = $kernel->getContainer()->get('test.service_container');

        return $container;
    }

    /**
     * The statement hook of a kernel booted with `hook: true`.
     */
    protected function hook(InstanceSettingsTestKernel $kernel): HookState
    {
        $hook = $this->container($kernel)->get(HookState::class);
        self::assertInstanceOf(HookState::class, $hook);

        return $hook;
    }

    protected function settings(InstanceSettingsTestKernel $kernel): InstanceSettings
    {
        $settings = $this->container($kernel)->get(InstanceSettings::class);
        self::assertInstanceOf(InstanceSettings::class, $settings);

        return $settings;
    }

    protected function connection(InstanceSettingsTestKernel $kernel): Connection
    {
        $connection = $this->container($kernel)->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * Browser that keeps the session (CSRF tokens) between requests.
     */
    protected function client(InstanceSettingsTestKernel $kernel): KernelBrowser
    {
        /** @var KernelBrowser $client */
        $client = $kernel->getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(true);
        $client->setServerParameters(['REMOTE_ADDR' => '203.0.113.9', 'PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'admin']);

        return $client;
    }

    /**
     * Makes a lock wait of the kernel's connection give up after `$seconds` (so one process can play both requests).
     */
    protected function lockTimeout(InstanceSettingsTestKernel $kernel, int $seconds): void
    {
        $connection = $this->connection($kernel);
        $platform   = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = ' . $seconds);
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement("SET lock_timeout = '" . ($seconds * 1000) . "ms'");
        }
    }

    /**
     * The stored JSON value of a setting row, straight from the database (null when there is no row).
     */
    protected function stored(InstanceSettingsTestKernel $kernel, string $section, string $name): mixed
    {
        $value = $this->connection($kernel)->fetchOne('SELECT value FROM instance_setting_value WHERE section = ? AND name = ?', [$section, $name]);

        return is_string($value) ? json_decode($value, true) : null;
    }
}
