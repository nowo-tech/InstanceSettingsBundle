<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Settings;

use DateTimeImmutable;
use LogicException;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\File\SettingFileUrls;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingFileStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function file_put_contents;
use function json_encode;
use function str_contains;
use function str_repeat;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Image fields through InstanceSettings: upload, replace, remove, keep, refusals, getFile(), audit (log + event).
 */
final class ImageSettingsTest extends TestCase
{
    private InMemorySettingValueRepository $repository;

    private InMemorySettingFileStore $store;

    private RecordingDispatcher $dispatcher;

    /** @var AbstractLogger&object{records: list<array{message: string, context: array<string, mixed>}>} */
    private AbstractLogger $logger;

    private InstanceSettings $settings;

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingValueRepository();
        $this->store      = new InMemorySettingFileStore();
        $this->dispatcher = new RecordingDispatcher();
        $routes           = new RouteCollection();
        $routes->add(InstanceSettingsRoutes::FILE, new Route('/_settings-file/{section}/{field}/{file}.{ext}'));
        $this->logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $this->settings = new InstanceSettings(
            $this->repository,
            new SettingsSectionRegistry([new ImageFixtureSection(), new GeneralFixtureSection()]),
            new ValueNormalizer(),
            $this->dispatcher,
            null,
            new MockClock('2026-11-08 10:00:00'),
            $this->logger,
            $this->store,
            fileUrls: new SettingFileUrls(new UrlGenerator($routes, new RequestContext())),
        );
    }

    public function testUploadReplaceKeepAndRemove(): void
    {
        self::assertNull($this->settings->getFile('brand.logo'));
        self::assertNull($this->settings->get('brand.logo'), 'never the reference');

        self::assertSame(['logo'], $this->settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32))]));
        $file = $this->settings->getFile('brand.logo');
        self::assertNotNull($file);
        self::assertSame(['brand', 'logo', 'image/png', 64, 32, true], [$file->section, $file->field, $file->mimeType, $file->width, $file->height, $file->public]);
        self::assertSame('/_settings-file/brand/logo/' . substr($file->sha256, 0, 12) . '.png', $file->url());
        self::assertSame('/_settings-file/brand/logo/' . substr($file->sha256, 0, 12) . '-192.png', $file->url(192));
        self::assertSame([192, 32], $file->variants());
        self::assertNull($this->settings->get('brand.logo'));
        self::assertNull($this->settings->getString('brand.logo'));
        self::assertSame(['name' => 'Acme'], $this->settings->getSection('brand'), 'image fields are left out');
        self::assertTrue($this->settings->isOverridden('brand.logo'));
        self::assertSame($file, $this->settings->getFile('brand.logo'), 'memoized');

        // The same image again: nothing changes.
        self::assertSame([], $this->settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32))]));
        // The current reference keeps it.
        self::assertSame([], $this->settings->save('brand', ['logo' => 'isf:' . $file->sha256]));

        // Replace: the old image is orphaned, not deleted.
        self::assertSame(['logo'], $this->settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32, 10))]));
        $new = $this->settings->getFile('brand.logo');
        self::assertNotNull($new);
        self::assertNotSame($file->sha256, $new->sha256);
        self::assertNotNull($this->orphanedAt($file->sha256));
        self::assertNull($this->orphanedAt($new->sha256));

        // Re-uploading the old image un-orphans it.
        $this->settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32))]);
        self::assertNull($this->orphanedAt($file->sha256));

        // Remove.
        self::assertSame(['logo'], $this->settings->save('brand', ['logo' => null]));
        self::assertNull($this->settings->getFile('brand.logo'));
        self::assertFalse($this->settings->isOverridden('brand.logo'));
        self::assertNotNull($this->orphanedAt($file->sha256));
        self::assertSame([], $this->settings->save('brand', ['logo' => '']), 'nothing left to remove');
    }

    public function testAuditRecordsHashesNeverContent(): void
    {
        $this->settings->save('brand', ['name' => 'Beacon', 'logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32))], SettingsChangedEvent::ORIGIN_FORM);
        $sha = $this->settings->getFile('brand.logo')?->sha256;
        self::assertNotNull($sha);
        $this->settings->save('brand', ['logo' => null]);

        $first = $this->dispatcher->events[0];
        self::assertInstanceOf(SettingsChangedEvent::class, $first);
        self::assertSame(['name', 'logo'], $first->changedFields);
        self::assertSame(['logo' => ['old' => null, 'new' => $sha]], $first->fileChanges);
        $second = $this->dispatcher->events[1];
        self::assertInstanceOf(SettingsChangedEvent::class, $second);
        self::assertSame(['logo' => ['old' => $sha, 'new' => null]], $second->fileChanges);

        self::assertMatchesRegularExpression('/^logo=[0-9a-f]{12} image\/png 64x32 \d+B$/', (string) $this->logger->records[0]['context']['files']);
        self::assertSame('logo=removed', $this->logger->records[1]['context']['files']);
        $encoded = (string) json_encode($this->logger->records);
        self::assertFalse(str_contains($encoded, $sha), 'only a 12-character prefix is logged');

        // A save without image changes logs no files key and dispatches no file changes.
        $this->settings->save('brand', ['name' => 'Other']);
        self::assertArrayNotHasKey('files', $this->logger->records[2]['context']);
        $third = $this->dispatcher->events[2];
        self::assertInstanceOf(SettingsChangedEvent::class, $third);
        self::assertSame([], $third->fileChanges);
    }

    public function testRefusedInputsChangeNothing(): void
    {
        $foreign = 'isf:' . str_repeat('a', 64);
        foreach ([
            ['logo' => $foreign],
            ['logo' => '../../etc/passwd'],
            ['logo' => 42],
            ['logo' => ImageUpload::fromBytes('<svg onload="alert(1)"/>')],
            ['scan' => ImageUpload::fromBytes(ImageFactory::png())],
            ['logo' => ImageUpload::fromBytes(ImageFactory::png(100, 900))],
            ['icon' => ImageUpload::fromBytes(ImageFactory::png(64, 32))],
            ['icon' => ImageUpload::fromBytes(ImageFactory::png(16, 16))],
            ['name' => 'Ok', 'logo' => ImageUpload::fromBytes(ImageFactory::animatedWebp())],
        ] as $values) {
            try {
                $this->settings->save('brand', $values);
                self::fail('Expected InvalidSettingValueException');
            } catch (InvalidSettingValueException $e) {
                self::assertCount(1, $e->violations);
            }
        }
        self::assertSame([], $this->repository->rows);
        self::assertSame([], $this->store->rows);
    }

    public function testFormUploadsAreReadWithTheFieldCap(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'isf');
        file_put_contents($path, ImageFactory::jpeg());
        try {
            self::assertSame(['scan'], $this->settings->save('brand', ['scan' => new UploadedFile($path, 'scan.png', 'image/png', null, true)]));
            $scan = $this->settings->getFile('brand.scan');
            self::assertNotNull($scan);
            self::assertSame('image/jpeg', $scan->mimeType);
            self::assertFalse($scan->public);
        } finally {
            unlink($path);
        }
    }

    public function testRequiredImage(): void
    {
        $section = new class extends AbstractSettingsSection {
            public function getName(): string
            {
                return 'req';
            }

            public function getFields(): iterable
            {
                yield SettingField::image('logo', required: true);
            }
        };
        $settings = new InstanceSettings($this->repository, new SettingsSectionRegistry([$section]), fileStore: $this->store);

        try {
            $settings->save('req', ['logo' => null]);
            self::fail('Expected InvalidSettingValueException');
        } catch (InvalidSettingValueException $e) {
            self::assertSame(['logo' => ['This value should not be blank.']], $e->violations);
        }
    }

    public function testGetFileGuards(): void
    {
        try {
            $this->settings->getFile('brand.name');
            self::fail('Expected LogicException');
        } catch (LogicException $e) {
            self::assertSame('Instance setting "brand.name" is not an image field.', $e->getMessage());
        }

        // A reference whose file disappeared reads as no file.
        $this->settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png())]);
        $this->store->rows = [];
        $this->settings->reset();
        self::assertNull($this->settings->getFile('brand.logo'));

        // Without a store nothing can be read or written.
        $bare = new InstanceSettings($this->repository, new SettingsSectionRegistry([new ImageFixtureSection()]));
        self::assertNull($bare->getFile('brand.logo'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('need a file store');
        $bare->save('brand', ['icon' => ImageUpload::fromBytes(ImageFactory::png())]);
    }

    private function orphanedAt(string $sha): ?DateTimeImmutable
    {
        $record = $this->store->findImage('brand', 'logo', $sha)[0] ?? null;
        self::assertNotNull($record);

        return $record->orphanedAt;
    }

    public function testImageHash(): void
    {
        self::assertSame(str_repeat('b', 64), InstanceSettings::imageHash('isf:' . str_repeat('b', 64)));
        self::assertNull(InstanceSettings::imageHash('isf:xyz'));
        self::assertNull(InstanceSettings::imageHash(null));
    }

    public function testNoBlobIsKeptAcrossResets(): void
    {
        $this->settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png())]);
        self::assertNotNull($this->settings->getFile('brand.logo'));
        $lookups = $this->store->lookups;
        $this->settings->reset();
        self::assertNotNull($this->settings->getFile('brand.logo'));
        self::assertSame($lookups + 1, $this->store->lookups, 'the metadata memo is cleared by reset()');
        self::assertSame(0, $this->store->reads, 'reading settings never reads bytes');
    }
}
