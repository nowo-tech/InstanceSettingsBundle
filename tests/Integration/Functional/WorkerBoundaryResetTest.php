<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\EventListener\WorkerBoundaryResetListener;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Repository\SettingRowWriterInterface;
use Nowo\InstanceSettingsBundle\Repository\StoredSettingRow;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event;

use function array_keys;
use function gc_collect_cycles;
use function glob;
use function hash_file;
use function json_encode;
use function memory_get_usage;

use const JSON_THROW_ON_ERROR;

/**
 * REQ-RUNTIME-002: one kept-alive kernel where the services resetter never runs (`messenger:consume --no-reset`, a long
 * console process). Another process changes the stored rows between units of work (raw SQL on the shared database,
 * never through this kernel's services); every message / command must read the current value, and the bundle's private
 * entity manager must not carry rows or a closed state from one unit to the next.
 *
 * 1.14 (audit MH-I-I1 / I2): the same for an encrypted secret rotated by another process, and a nested console command
 * inside an outer command's transaction (its boundaries must not drop the outer transaction's staged files).
 *
 * The Messenger worker events are dispatched by name (symfony/messenger is not a dependency of the bundle), exactly as
 * the worker dispatches them: Received before the handler, Handled or Failed after it.
 */
final class WorkerBoundaryResetTest extends KernelTestCase
{
    private const MESSAGES = 600;

    private const COMMANDS = 200;

    /** Two "tenants" of the consumer: each message reads one of them, in alternation. */
    private const KEYS = [['general', 'site_name'], ['mailer', 'from_name']];

    public function testMessengerConsumerWithoutResetReadsWhatAnotherProcessSaved(): void
    {
        $kernel     = $this->bootKernel();
        $dispatcher = $this->dispatcher($kernel);
        $holder     = $this->container($kernel)->get(SettingsEntityManager::class);
        self::assertInstanceOf(SettingsEntityManager::class, $holder);
        $this->seed($kernel);
        $before = 0;

        for ($i = 0; $i < self::MESSAGES; ++$i) {
            [$section, $name] = self::KEYS[$i % 2];
            $expected         = 'REDACTED-TEST-value-' . $i;
            $this->changeOutOfBand($kernel, $section, $name, $expected);

            $dispatcher->dispatch(new Event(), WorkerBoundaryResetListener::MESSENGER_RECEIVED);
            self::assertSame($expected, $this->settings($kernel)->getString($section . '.' . $name), 'message #' . $i);
            $private = $holder->get();
            $private->find(SettingValue::class, 1);
            if ($i % 50 === 7) {
                $private->close(); // e.g. a failed write in the handler
            }
            $dispatcher->dispatch(new Event(), $i % 3 === 0 ? WorkerBoundaryResetListener::MESSENGER_FAILED : WorkerBoundaryResetListener::MESSENGER_HANDLED);

            self::assertSame([], $private->getUnitOfWork()->getIdentityMap(), 'message #' . $i . ': no managed row survives the message');
            self::assertNotSame($private, $holder->get(), 'message #' . $i . ': the next message gets a fresh private manager');
            self::assertTrue($holder->get()->isOpen());

            if ($i === 49) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 550 messages.');
    }

    public function testLongRunningConsoleCommandsReadWhatAnotherProcessSaved(): void
    {
        $kernel     = $this->bootKernel();
        $dispatcher = $this->dispatcher($kernel);
        $this->seed($kernel);
        $command = new Command('app:worker-probe');
        $input   = new ArrayInput([]);
        $output  = new NullOutput();

        for ($i = 0; $i < self::COMMANDS; ++$i) {
            [$section, $name] = self::KEYS[$i % 2];
            $expected         = 'REDACTED-TEST-command-' . $i;
            $this->changeOutOfBand($kernel, $section, $name, $expected);

            $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, $output), ConsoleEvents::COMMAND);
            self::assertSame($expected, $this->settings($kernel)->getString($section . '.' . $name), 'command #' . $i);
            $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS), ConsoleEvents::TERMINATE);
        }
    }

    public function testAnEncryptedSecretRotatedByAnotherProcessIsReadDecryptedOnTheNextMessage(): void
    {
        $kernel     = $this->bootKernel();
        $dispatcher = $this->dispatcher($kernel);
        $this->seed($kernel);

        // Audit MH-I-I1: what another worker writes for a secret is the sealed column (same DoctrineEncrypt key).
        $sealed = [];
        foreach (['REDACTED-TEST-secret-a', 'REDACTED-TEST-secret-b', 'REDACTED-TEST-secret-c'] as $secret) {
            $this->writer($kernel)->writeRows([new StoredSettingRow('mailer', 'dsn', null, true, $secret, new DateTimeImmutable('2026-11-11 12:00:00'))]);
            $cipher = $this->connection($kernel)->fetchOne('SELECT secret_value FROM ' . SettingValue::TABLE . " WHERE section = 'mailer' AND name = 'dsn'");
            self::assertIsString($cipher);
            self::assertStringNotContainsString($secret, $cipher, 'stored sealed');
            $sealed[$secret] = $cipher;
        }
        $dispatcher->dispatch(new Event(), WorkerBoundaryResetListener::MESSENGER_HANDLED);
        $secrets = array_keys($sealed);

        for ($i = 0; $i < 60; ++$i) {
            $expected = $secrets[$i % 3];
            $changed  = $this->connection($kernel)->executeStatement(
                'UPDATE ' . SettingValue::TABLE . " SET secret_value = ? WHERE section = 'mailer' AND name = 'dsn'",
                [$sealed[$expected]],
            );
            self::assertSame(1, $changed);

            $dispatcher->dispatch(new Event(), WorkerBoundaryResetListener::MESSENGER_RECEIVED);
            self::assertSame($expected, $this->settings($kernel)->getSecret('mailer.dsn'), 'message #' . $i);
            self::assertTrue($this->settings($kernel)->hasSecret('mailer.dsn'));
            $dispatcher->dispatch(new Event(), WorkerBoundaryResetListener::MESSENGER_HANDLED);
        }
    }

    public function testANestedCommandInsideATransactionKeepsTheOuterFileChanges(): void
    {
        $files  = InstanceSettingsTestKernel::baseDir() . '/worker-' . $this->name() . '/files';
        $kernel = $this->bootKernel([
            'brand' => ['enabled' => true, 'defaults' => ['name' => 'Acme']],
            'files' => ['store' => 'filesystem', 'directory' => $files],
        ], fixtures: false, extensions: ['nowo_ui_kit' => ['appearance' => ['enabled' => true]]]);
        $dispatcher = $this->dispatcher($kernel);
        $settings   = $this->settings($kernel);
        $connection = $this->connection($kernel);
        $outer      = new Command('app:outer');
        $nested     = new Command('app:nested');
        $input      = new ArrayInput([]);
        $output     = new NullOutput();

        // Audit MH-I-I2: an outer command opens a transaction and saves an image (staged until the commit), then runs
        // a nested command; the nested command's boundaries reset the memo and the private manager, never the staged
        // files of the outer transaction (the boundary listener has no file store reset on purpose).
        $dispatcher->dispatch(new ConsoleCommandEvent($outer, $input, $output), ConsoleEvents::COMMAND);
        $connection->beginTransaction();
        $settings->save('brand', ['name' => 'Outer', 'logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80))]);
        self::assertSame([], glob($files . '/brand/logo_light.*') ?: [], 'staged until the outer commit');

        $dispatcher->dispatch(new ConsoleCommandEvent($nested, $input, $output), ConsoleEvents::COMMAND);
        self::assertSame('Outer', $this->settings($kernel)->getString('brand.name'), 'the nested command reads inside the transaction');
        $dispatcher->dispatch(new ConsoleTerminateEvent($nested, $input, $output, Command::SUCCESS), ConsoleEvents::TERMINATE);

        $connection->commit();
        $dispatcher->dispatch(new ConsoleTerminateEvent($outer, $input, $output, Command::SUCCESS), ConsoleEvents::TERMINATE);
        self::assertFileExists($files . '/brand/logo_light.png', 'applied by the outer commit');
        $file = $this->settings($kernel)->getFile('brand.logo_light');
        self::assertNotNull($file);
        self::assertSame(hash_file('sha256', $files . '/brand/logo_light.png'), $file->sha256);
        self::assertSame('Outer', $this->settings($kernel)->getString('brand.name'));
    }

    public function testABoundaryNeverBuildsTheSettingsServices(): void
    {
        $kernel = $this->bootKernel();
        $this->dispatcher($kernel)->dispatch(new Event(), WorkerBoundaryResetListener::MESSENGER_HANDLED);

        self::assertFalse($this->container($kernel)->initialized(SettingsEntityManager::class));
        self::assertFalse($this->container($kernel)->initialized(InstanceSettings::class));
    }

    private function writer(InstanceSettingsTestKernel $kernel): SettingRowWriterInterface
    {
        $writer = $this->container($kernel)->get(SettingRowWriterInterface::class);
        self::assertInstanceOf(SettingRowWriterInterface::class, $writer);

        return $writer;
    }

    private function seed(InstanceSettingsTestKernel $kernel): void
    {
        $at = new DateTimeImmutable('2026-11-11 12:00:00');
        $this->writer($kernel)->writeRows([
            new StoredSettingRow('general', 'site_name', 'first', false, null, $at),
            new StoredSettingRow('mailer', 'from_name', 'first', false, null, $at),
        ]);
    }

    /**
     * What another worker / process does: a committed row change this kernel's services are never told about.
     */
    private function changeOutOfBand(InstanceSettingsTestKernel $kernel, string $section, string $name, string $value): void
    {
        $changed = $this->connection($kernel)->executeStatement(
            'UPDATE ' . SettingValue::TABLE . ' SET value = ? WHERE section = ? AND name = ?',
            [json_encode($value, JSON_THROW_ON_ERROR), $section, $name],
        );
        self::assertSame(1, $changed);
    }

    private function dispatcher(InstanceSettingsTestKernel $kernel): EventDispatcherInterface
    {
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
