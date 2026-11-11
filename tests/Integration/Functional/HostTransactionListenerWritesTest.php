<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use ArrayObject;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Event\SettingsPendingEventsInterface;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\HostEntity\HostNote;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * 1.11.1: {@see SettingsChangedEvent} is dispatched after the outermost commit, at a point where no transaction is
 * open, so a listener that persists and flushes an entity on the application manager (an audit row) commits it
 * normally. Mirrors an application flow that saves several sections and flushes its own entities once, inside its own
 * transaction (commit, then the same with a rollback).
 */
final class HostTransactionListenerWritesTest extends KernelTestCase
{
    private const HOST_MAPPING = [
        'type'      => 'attribute',
        'dir'       => '%kernel.project_dir%/tests/Fixtures/HostEntity',
        'prefix'    => 'Nowo\InstanceSettingsBundle\Tests\Fixtures\HostEntity',
        'is_bundle' => false,
    ];

    public function testSeveralSavesAndAHostFlushInsideTheHostTransactionThenCommit(): void
    {
        $kernel = $this->bootKernel(extensions: ['doctrine' => ['orm' => ['mappings' => ['Host' => self::HOST_MAPPING]]]]);
        $host   = $this->host($kernel);
        $seen   = $this->auditListener($kernel, $host);
        $conn   = $host->getConnection();

        $conn->beginTransaction();
        $this->settings($kernel)->save('general', ['site_name' => 'Beacon']);
        $this->settings($kernel)->save('mailer', ['from_name' => 'Ops']);
        $own = new HostNote('ops entity');
        $host->persist($own);
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($own), 'the bundle did not flush the host entity');
        self::assertSame(0, $this->notes($kernel));
        $host->flush();
        self::assertSame([], $seen->getArrayCopy(), 'nothing inside the host transaction');
        $conn->commit();
        self::assertSame([], $seen->getArrayCopy(), 'nothing from inside the commit');

        // The end of the request dispatches them (the http_kernel service: no services resetter in between).
        $httpKernel = $this->container($kernel)->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $httpKernel);
        self::assertInstanceOf(TerminableInterface::class, $httpKernel);
        $request = Request::create('/no-such-page');
        $httpKernel->terminate($request, $httpKernel->handle($request));

        self::assertSame(['general:false', 'mailer:false'], $seen->getArrayCopy(), 'once per save, outside any transaction');
        self::assertFalse($conn->isTransactionActive());
        self::assertSame(3, $this->notes($kernel), 'the host entity and both audit rows are committed');
        $this->settings($kernel)->reset();
        self::assertSame('Beacon', $this->settings($kernel)->getString('general.site_name'));
        self::assertSame(0, $this->pending($kernel)->countPending());
    }

    public function testTheSameFlowRolledBack(): void
    {
        $kernel = $this->bootKernel(extensions: ['doctrine' => ['orm' => ['mappings' => ['Host' => self::HOST_MAPPING]]]]);
        $host   = $this->host($kernel);
        $seen   = $this->auditListener($kernel, $host);
        $conn   = $host->getConnection();

        $conn->beginTransaction();
        $this->settings($kernel)->save('general', ['site_name' => 'Beacon']);
        $this->settings($kernel)->save('mailer', ['from_name' => 'Ops']);
        $host->persist(new HostNote('ops entity'));
        $host->flush();
        $conn->rollBack();

        self::assertSame(0, $this->pending($kernel)->dispatchPending());
        self::assertSame([], $seen->getArrayCopy(), 'no event after a rollback');
        self::assertSame(0, $this->notes($kernel));
        $this->settings($kernel)->reset();
        self::assertSame('Acme', $this->settings($kernel)->getString('general.site_name'), 'the settings rows were rolled back');
    }

    public function testOutsideATransactionTheListenerWriteCommitsAtOnce(): void
    {
        $kernel = $this->bootKernel(extensions: ['doctrine' => ['orm' => ['mappings' => ['Host' => self::HOST_MAPPING]]]]);
        $host   = $this->host($kernel);
        $seen   = $this->auditListener($kernel, $host);

        $this->settings($kernel)->save('general', ['site_name' => 'Beacon']);

        self::assertSame(['general:false'], $seen->getArrayCopy());
        self::assertSame(1, $this->notes($kernel));
    }

    /**
     * An audit listener like an application's: persists and flushes a row on the host manager.
     *
     * @return ArrayObject<int, string>
     */
    private function auditListener(InstanceSettingsTestKernel $kernel, EntityManagerInterface $host): ArrayObject
    {
        $seen       = new ArrayObject();
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(SettingsChangedEvent::class, static function (SettingsChangedEvent $event) use ($seen, $host): void {
            $seen->append($event->section . ':' . ($host->getConnection()->isTransactionActive() ? 'true' : 'false'));
            $host->persist(new HostNote('audit ' . $event->section));
            $host->flush();
        });

        return $seen;
    }

    private function host(InstanceSettingsTestKernel $kernel): EntityManagerInterface
    {
        $host = $this->container($kernel)->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);

        return $host;
    }

    private function pending(InstanceSettingsTestKernel $kernel): SettingsPendingEventsInterface
    {
        $pending = $this->container($kernel)->get(SettingsPendingEventsInterface::class);
        self::assertInstanceOf(SettingsPendingEventsInterface::class, $pending);

        return $pending;
    }

    private function notes(InstanceSettingsTestKernel $kernel): int
    {
        return (int) $this->host($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note');
    }
}
