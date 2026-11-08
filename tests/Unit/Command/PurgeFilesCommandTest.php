<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Command\ExportSettingsCommand;
use Nowo\InstanceSettingsBundle\Command\PurgeFilesCommand;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingFileStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function count;
use function json_decode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * `purge-files`: retention timing, dry run, fields removed from code; `export --include-files`.
 */
final class PurgeFilesCommandTest extends TestCase
{
    public function testOrphanRetentionAndRemovedFields(): void
    {
        $registry   = new SettingsSectionRegistry([new ImageFixtureSection(), new GeneralFixtureSection()]);
        $repository = new InMemorySettingValueRepository();
        $store      = new InMemorySettingFileStore();
        $clock      = new MockClock('2026-11-01 12:00:00');
        $settings   = new InstanceSettings($repository, $registry, clock: $clock, fileStore: $store);

        $settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32, 1))]);
        $settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32, 2))]);
        $settings->save('brand', ['icon' => ImageUpload::fromBytes(ImageFactory::png(40, 40))]);
        // A row of a field that no longer exists in code, and a row of an unknown section: their images are not live.
        $repository->apply([(new SettingValue('brand', 'removed_field', new DateTimeImmutable()))->storeValue('isf:' . str_repeat('e', 64), new DateTimeImmutable())], []);
        $repository->apply([(new SettingValue('gone', 'logo', new DateTimeImmutable()))->storeValue('isf:' . str_repeat('f', 64), new DateTimeImmutable())], []);
        $store->save('brand', 'removed_field', (new ImageProcessor())->process(new ImageSpec(), ImageFactory::png(8, 8)), new DateTimeImmutable());

        $originals = static fn (): int => count(array_filter($store->rows, static fn (array $row): bool => $row['record']->variant === 0));
        self::assertSame(4, $originals());

        // Day 10: the replaced logo was orphaned on day 0 (retention 30), the removed field's image is marked now.
        $clock->modify('+10 days');
        $tester = new CommandTester(new PurgeFilesCommand($registry, $repository, $store, 30, $clock));
        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Would mark 1 newly unreferenced image(s); would delete 0', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Marked 1 newly unreferenced image(s); deleted 0', $tester->getDisplay());
        self::assertSame(4, $originals());

        // Day 31: the replaced logo goes; the removed field's image waits until day 40.
        $clock->modify('+21 days');
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Marked 0 newly unreferenced image(s); deleted 1', $tester->getDisplay());
        self::assertSame(3, $originals());

        $clock->modify('+10 days');
        $tester->execute([]);
        self::assertSame(2, $originals());
        self::assertNotNull($settings->getFile('brand.logo'), 'live images are never purged');
        self::assertNotNull($settings->getFile('brand.icon'));

        // Retention 0: an image removed now is deleted on the next run.
        $settings->save('brand', ['icon' => null]);
        (new CommandTester(new PurgeFilesCommand($registry, $repository, $store, 0, $clock)))->execute([]);
        self::assertSame(1, $originals());
    }

    public function testStaleMarksHealAndUnknownSectionsKeepTheirImages(): void
    {
        $registry   = new SettingsSectionRegistry([new ImageFixtureSection()]);
        $repository = new InMemorySettingValueRepository();
        $store      = new InMemorySettingFileStore();
        $clock      = new MockClock('2026-11-01 12:00:00');
        $settings   = new InstanceSettings($repository, $registry, clock: $clock, fileStore: $store);
        $settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32))]);
        $logo = (string) $settings->getFile('brand.logo')?->sha256;

        // A section that is not registered right now (feature flag, broken deploy) still has a row and an image.
        $gone = (new ImageProcessor())->process(new ImageSpec(), ImageFactory::png(8, 8));
        $store->save('gone', 'logo', $gone, new DateTimeImmutable());
        $repository->apply([(new SettingValue('gone', 'logo', new DateTimeImmutable()))->storeValue('isf:' . $gone->sha256(), new DateTimeImmutable())], []);

        // A stale run marked the live logo long ago.
        $store->orphan('brand', 'logo', $logo, new DateTimeImmutable('2026-01-01'));
        $tester = new CommandTester(new PurgeFilesCommand($registry, $repository, $store, 0, $clock));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Marked 0 newly unreferenced image(s); deleted 0', $tester->getDisplay());
        self::assertNull($store->findImage('brand', 'logo', $logo)[0]->orphanedAt, 'the stale mark is cleared');
        self::assertNotSame([], $store->findImage('gone', 'logo', $gone->sha256()), 'unknown sections keep their images');

        self::assertSame(Command::SUCCESS, $tester->execute(['--include-unknown-sections' => true]));
        self::assertStringContainsString('Marked 1 newly unreferenced image(s); deleted 1', $tester->getDisplay());
        self::assertSame([], $store->findImage('gone', 'logo', $gone->sha256()));
        self::assertNotNull($settings->getFile('brand.logo'));
    }

    public function testExportIncludeFilesOption(): void
    {
        $registry   = new SettingsSectionRegistry([new ImageFixtureSection()]);
        $repository = new InMemorySettingValueRepository();
        $store      = new InMemorySettingFileStore();
        $settings   = new InstanceSettings($repository, $registry, fileStore: $store);
        $settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png())]);
        $tester = new CommandTester(new ExportSettingsCommand(new ConfigPortability($registry, $settings, $repository, fileStore: $store)));

        $tester->execute([]);
        $light = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $light['version']);
        self::assertArrayNotHasKey('files', $light, 'off by default in the CLI');

        $tester->execute(['--include-files' => true]);
        self::assertSame(['brand.logo'], array_keys(json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['files']));
    }
}
