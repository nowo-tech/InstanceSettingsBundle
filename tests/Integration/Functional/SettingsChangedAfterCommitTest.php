<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Repository\SettingRowReaderInterface;
use Nowo\InstanceSettingsBundle\Repository\StoredSettingRow;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\FailingImportProvider;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;

/**
 * 1.10 (DDD P2) in a real kernel: {@see SettingsChangedEvent} after the commit of a host transaction around `save()`
 * and an import, nothing after a rollback, unchanged immediate dispatch without a host transaction; the row reader.
 */
final class SettingsChangedAfterCommitTest extends KernelTestCase
{
    /** @var list<string> */
    private array $events = [];

    public function testWithoutAHostTransactionTheEventFollowsTheSave(): void
    {
        $kernel = $this->kernelWithListener();

        $this->settings($kernel)->save('general', ['site_name' => 'Beacon']);

        self::assertSame(['general:site_name'], $this->events);
    }

    public function testAHostTransactionDelaysTheEventUntilItsCommit(): void
    {
        $kernel     = $this->kernelWithListener();
        $connection = $this->connection($kernel);

        $connection->beginTransaction();
        $this->settings($kernel)->save('general', ['site_name' => 'Beacon']);
        self::assertSame([], $this->events, 'not before the host commit');
        $connection->commit();

        self::assertSame(['general:site_name'], $this->events);
    }

    public function testAHostRollbackDropsTheEvent(): void
    {
        $kernel     = $this->kernelWithListener();
        $connection = $this->connection($kernel);

        $connection->beginTransaction();
        $this->settings($kernel)->save('general', ['site_name' => 'Beacon']);
        $connection->rollBack();
        $this->settings($kernel)->reset();

        self::assertSame([], $this->events);
        self::assertSame('Acme', $this->settings($kernel)->getString('general.site_name'));
    }

    public function testAFailedImportDispatchesNothing(): void
    {
        $kernel      = $this->kernelWithListener([FailingImportProvider::class => FailingImportProvider::class]);
        $portability = $this->container($kernel)->get(ConfigPortability::class);
        self::assertInstanceOf(ConfigPortability::class, $portability);

        $payload                                     = $portability->export(['general']);
        $payload['settings']['general']['site_name'] = 'Imported';
        $payload['providers']                        = ['failing' => ['enabled' => true]];

        try {
            $portability->import($payload);
            self::fail('Expected the provider failure.');
        } catch (RuntimeException $e) {
            self::assertSame('provider import failed', $e->getMessage());
        }
        self::assertSame([], $this->events, 'the general section saved before the failure was rolled back');

        unset($payload['providers']);
        $portability->import($payload);
        self::assertContains('general:site_name', $this->events, 'dispatched after the import committed');
    }

    public function testTheRowReaderReturnsPersistenceNeutralRows(): void
    {
        $kernel = $this->bootKernel();
        $this->settings($kernel)->save('mailer', ['dsn' => 'smtp://user:pass@mail.example.test:587', 'from_name' => 'Ops']);

        $reader = $this->container($kernel)->get(SettingRowReaderInterface::class);
        self::assertInstanceOf(SettingRowReaderInterface::class, $reader);
        $rows = $reader->findRowsBySection('mailer');

        self::assertSame(['mailer.dsn', 'mailer.from_name'], array_map(static fn (StoredSettingRow $row): string => $row->key(), $rows));
        self::assertSame([true, 'smtp://user:pass@mail.example.test:587', null], [$rows[0]->isSecret, $rows[0]->secret(), $rows[0]->value]);
        self::assertSame([false, 'Ops'], [$rows[1]->isSecret, $rows[1]->value]);
        self::assertCount(2, $reader->findAllRows());
    }

    /**
     * @param array<string, string> $services extra services
     */
    private function kernelWithListener(array $services = []): InstanceSettingsTestKernel
    {
        $kernel     = $this->bootKernel(services: $services);
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(SettingsChangedEvent::class, function (SettingsChangedEvent $event): void {
            foreach ($event->changedFields as $field) {
                $this->events[] = $event->section . ':' . $field;
            }
        });

        return $kernel;
    }
}
