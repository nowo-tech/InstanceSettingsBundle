<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\AppearancePortabilityProvider;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function count;

/**
 * Boots a fresh {@see InstanceSettingsTestKernel} per call (own cache dir, schema created on the in-memory database).
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<InstanceSettingsTestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(InstanceSettingsTestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $config nowo_instance_settings configuration
     * @param array<string, string> $services extra service id => class (autoconfigured)
     * @param list<string> $without bundles to leave out
     * @param Closure(RoutingConfigurator): void|null $routes routes (default: the bundle loader)
     */
    protected function bootKernel(array $config = [], array $services = [], array $without = [], ?Closure $routes = null, bool $debug = false, bool $fixtures = true): InstanceSettingsTestKernel
    {
        $id     = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel = new InstanceSettingsTestKernel(
            $id,
            static function (ContainerConfigurator $container) use ($config, $services, $fixtures): void {
                $container->extension('nowo_instance_settings', $config);
                $defaults = $container->services()->defaults()->autowire()->autoconfigure();
                if ($fixtures) {
                    $defaults->set(MailerFixtureSection::class);
                    $defaults->set(GeneralFixtureSection::class);
                    $defaults->set(AppearancePortabilityProvider::class)->public();
                }
                foreach ($services as $serviceId => $class) {
                    $defaults->set($serviceId, $class)->public();
                }
            },
            $routes ?? static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
            },
            $without,
            $debug,
        );
        $this->kernels[] = $kernel;
        $kernel->boot();

        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        if ($entityManager instanceof EntityManagerInterface) {
            (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
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
     * Browser that keeps the session (CSRF tokens) and the in-memory database (no kernel reboot) between requests.
     */
    protected function client(InstanceSettingsTestKernel $kernel, ?string $user = 'admin'): KernelBrowser
    {
        /** @var KernelBrowser $client */
        $client = $kernel->getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(true);
        $client->setServerParameters($user === null ? ['REMOTE_ADDR' => '203.0.113.9'] : ['REMOTE_ADDR' => '203.0.113.9', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user]);

        return $client;
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

    protected static function tick(Form $form, string $name): void
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->tick();
    }

    protected static function upload(Form $form, string $name, string $path): void
    {
        $field = $form[$name];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
    }
}
