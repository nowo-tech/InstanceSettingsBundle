<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\FileEntity\SettingFile;
use Nowo\InstanceSettingsBundle\Repository\SettingRowReaderInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingRowWriterInterface;
use Nowo\InstanceSettingsBundle\Repository\StoredSettingRow;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\HostEntity\HostNote;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\HostXml\HostXmlNote;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\WorkerProbeController;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function json_decode;
use function memory_get_usage;

use const JSON_THROW_ON_ERROR;

/**
 * 1.11 (architecture bar rule 6): the bundle never flushes, clears or resets an entity manager of the application.
 *
 * Standard host (attribute entity, default manager, no option) and DDD host (XML-mapped entity, dedicated
 * `doctrine.entity_manager`, `auto_mapping` on the default manager); a kept-alive worker kernel driven through
 * `http_kernel` without the services resetter.
 */
final class HostEntityManagerIsolationTest extends KernelTestCase
{
    private const HOST_MAPPING = [
        'type'      => 'attribute',
        'dir'       => '%kernel.project_dir%/tests/Fixtures/HostEntity',
        'prefix'    => 'Nowo\InstanceSettingsBundle\Tests\Fixtures\HostEntity',
        'is_bundle' => false,
    ];

    private const HOST_XML_MAPPING = [
        'type'      => 'xml',
        'dir'       => '%kernel.project_dir%/tests/Fixtures/HostXml/mapping',
        'prefix'    => 'Nowo\InstanceSettingsBundle\Tests\Fixtures\HostXml',
        'is_bundle' => false,
    ];

    public function testStandardHostPendingChangesAreNotFlushedBySave(): void
    {
        $kernel = $this->bootKernel(extensions: ['doctrine' => ['orm' => ['mappings' => ['Host' => self::HOST_MAPPING]]]]);
        $host   = $this->hostManager($kernel, 'default');

        $note = new HostNote('pending');
        $host->persist($note);
        $events = [];
        $this->dispatcher($kernel)->addListener(SettingsChangedEvent::class, static function (SettingsChangedEvent $event) use (&$events, $host): void {
            $events[] = $host->getConnection()->fetchOne('SELECT value FROM instance_setting_value WHERE name = ?', ['site_name']);
        });

        $this->settings($kernel)->save('general', ['site_name' => 'Beacon']);

        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'), 'the pending host entity is not flushed');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note), 'still pending in the host unit of work');
        self::assertSame(['"Beacon"'], $events, 'the event fires once the row is committed');
        self::assertSame([], $host->getUnitOfWork()->getIdentityMap()[SettingValue::class] ?? [], 'no bundle row in the host identity map');

        // The host flushes its own work when it decides to.
        $host->flush();
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
        self::assertSame('Beacon', $this->settings($kernel)->getString('general.site_name'));
    }

    public function testAClosedHostManagerIsLeftClosedAndTheStoreKeepsWorking(): void
    {
        $kernel = $this->bootKernel();
        $host   = $this->hostManager($kernel, 'default');
        $host->close();

        $this->settings($kernel)->save('general', ['site_name' => 'Closed host']);
        $this->settings($kernel)->reset();

        self::assertSame('Closed host', $this->settings($kernel)->getString('general.site_name'));
        self::assertFalse($host->isOpen(), 'the bundle does not reset a manager it does not own');
    }

    public function testRowWriterPortWritesWithoutTheEntity(): void
    {
        $kernel = $this->bootKernel();
        $writer = $this->container($kernel)->get(SettingRowWriterInterface::class);
        $reader = $this->container($kernel)->get(SettingRowReaderInterface::class);
        self::assertInstanceOf(SettingRowWriterInterface::class, $writer);
        self::assertInstanceOf(SettingRowReaderInterface::class, $reader);
        $at = new DateTimeImmutable('2026-11-10 12:00:00');

        $writer->writeRows([
            new StoredSettingRow('general', 'site_name', 'Written', false, null, $at),
            new StoredSettingRow('mailer', 'dsn', null, true, 'smtp://REDACTED-TEST', $at),
        ]);
        $writer->writeRows([new StoredSettingRow('general', 'site_name', 'Replaced', false, null, $at)], ['mailer.dsn', 'general.missing']);

        $rows = $reader->findAllRows();
        self::assertCount(1, $rows);
        self::assertSame(['general.site_name', 'Replaced'], [$rows[0]->key(), $rows[0]->value]);
        $writer->writeRows([], []);
    }

    public function testWorkerKernelRecoversTheBundleManagerAndNeverTouchesTheHostOne(): void
    {
        $kernel = $this->bootKernel(
            services: [WorkerProbeController::class => WorkerProbeController::class],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->add('probe_save', '/probe/save')->controller([WorkerProbeController::class, 'save']);
                $routes->add('probe_break', '/probe/break')->controller([WorkerProbeController::class, 'break']);
            },
            extensions: ['doctrine' => ['orm' => ['mappings' => ['Host' => self::HOST_MAPPING]]]],
        );
        $httpKernel = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $httpKernel);
        self::assertInstanceOf(TerminableInterface::class, $httpKernel);

        // Kept-alive worker: the same container serves every request; no services resetter between them.
        $request = static function (string $path) use ($httpKernel): array {
            $request  = Request::create($path);
            $response = $httpKernel->handle($request);
            $httpKernel->terminate($request, $response);

            /** @var array{host_open: bool, host_pending: int, host_rows: int, site_name: string|null} $data */
            $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            return $data;
        };

        self::assertSame(['host_open' => true, 'host_pending' => 1, 'host_rows' => 0, 'site_name' => 'one'], $request('/probe/save?value=one'));
        self::assertSame(['host_open' => true, 'host_pending' => 1, 'host_rows' => 0, 'site_name' => 'one'], $request('/probe/break'), 'a failed bundle write leaves the host manager open');
        self::assertSame(['host_open' => true, 'host_pending' => 2, 'host_rows' => 0, 'site_name' => 'two'], $request('/probe/save?value=two'), 'next request: a fresh bundle manager');

        // Flat memory over many requests in the same kernel (no rows kept by the bundle manager).
        $request('/probe/save?value=warm');
        $before = memory_get_usage();
        for ($i = 0; $i < 300; ++$i) {
            $request('/probe/save?value=v' . ($i % 2));
        }
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $before);
    }

    public function testDedicatedManagerWithAutoMappingOnTheDefaultOne(): void
    {
        $kernel   = $this->bootKernel(['doctrine' => ['entity_manager' => 'settings'], 'files' => ['enabled' => true]], extensions: ['doctrine' => $this->twoManagers(autoMapping: true)]);
        $registry = $this->registry($kernel);
        $host     = $this->hostManager($kernel, 'default');
        $settings = $this->hostManager($kernel, 'settings');

        self::assertSame('default', $registry->getDefaultManagerName(), 'the bundle manager never becomes the default one');
        foreach ([SettingValue::class, SettingFile::class] as $class) {
            self::assertTrue($host->getMetadataFactory()->isTransient($class), $class . ' is not mapped on the auto-mapped default manager');
            self::assertSame($settings, $registry->getManagerForClass($class));
        }
        (new SchemaTool($settings))->createSchema($settings->getMetadataFactory()->getAllMetadata());

        $note = new HostXmlNote('pending');
        $host->persist($note);
        $this->settings($kernel)->save('general', ['site_name' => 'Dedicated']);

        self::assertSame('"Dedicated"', $settings->getConnection()->fetchOne('SELECT value FROM instance_setting_value'));
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM host_xml_note'));
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note));
        self::assertNotContains('instance_setting_value', $host->getConnection()->createSchemaManager()->listTableNames(), 'nothing on the host database');
    }

    public function testDedicatedManagerWithoutDefaultEntityManagerKey(): void
    {
        $doctrine = $this->twoManagers(autoMapping: false);
        unset($doctrine['orm']['default_entity_manager']);
        $kernel = $this->bootKernel(['doctrine' => ['entity_manager' => 'settings']], extensions: ['doctrine' => $doctrine]);

        self::assertSame('default', $this->registry($kernel)->getDefaultManagerName());
        self::assertTrue($this->hostManager($kernel, 'default')->getMetadataFactory()->isTransient(SettingValue::class));
        self::assertFalse($this->hostManager($kernel, 'settings')->getMetadataFactory()->isTransient(SettingValue::class));
    }

    public function testAnUnknownDedicatedManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_instance_settings.doctrine.entity_manager is "missing", but no such Doctrine entity manager is configured (known: "default")');
        $this->bootKernel(['doctrine' => ['entity_manager' => 'missing']]);
    }

    /**
     * @return array{dbal: array<string, mixed>, orm: array<string, mixed>}
     */
    private function twoManagers(bool $autoMapping): array
    {
        return [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => ['settings' => ['url' => 'sqlite:///:memory:']],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default'  => ['connection' => 'default', 'auto_mapping' => $autoMapping, 'mappings' => ['HostXml' => self::HOST_XML_MAPPING]],
                    'settings' => ['connection' => 'settings'],
                ],
            ],
        ];
    }

    private function registry(InstanceSettingsTestKernel $kernel): ManagerRegistry
    {
        $registry = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function hostManager(InstanceSettingsTestKernel $kernel, string $name): EntityManagerInterface
    {
        $manager = $this->registry($kernel)->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function dispatcher(InstanceSettingsTestKernel $kernel): EventDispatcherInterface
    {
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
