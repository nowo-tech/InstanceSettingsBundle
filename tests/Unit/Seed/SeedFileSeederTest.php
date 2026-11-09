<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Seed;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GdImage;
use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Seed\SeedFileException;
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;
use Nowo\InstanceSettingsBundle\Seed\SeedReport;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SeedFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\BrandFixture;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingFileStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RacingRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validation;

use function array_column;
use function bin2hex;
use function count;
use function dirname;
use function file_put_contents;
use function hash;
use function implode;
use function is_dir;
use function json_encode;
use function mkdir;
use function random_bytes;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

final class SeedFileSeederTest extends TestCase
{
    private const SEED = <<<'YAML'
        schema: nowo-instance-settings-seed
        version: 1
        settings:
          brand:
            name: Beacon
            eyebrow: Uptime for teams
            wordmark: ''
          appearance:
            preset_dark: obsidian
            light_accent: '#0F766E'
            dark_accent: '#34D399'
        files:
          brand:
            logo_light: brand/logo-light.png
            app_icon: brand/app-icon.png
            favicon: brand/favicon.png
            mark: ~
        YAML;

    private string $base;

    private BrandFixture $brand;

    private SettingsSectionRegistry $registry;

    private RecordingDispatcher $dispatcher;

    private ?AbstractLogger $logger = null;

    private ?BrandFixture $fsBrand = null;

    protected function setUp(): void
    {
        $this->base       = sys_get_temp_dir() . '/nowo-is-seed-' . bin2hex(random_bytes(6));
        $this->brand      = new BrandFixture();
        $this->registry   = new SettingsSectionRegistry([...$this->brand->sections(), new SeedFixtureSection()]);
        $this->dispatcher = new RecordingDispatcher();
        $this->write('config/brand/logo-light.png', ImageFactory::png(240, 80));
        $this->write('config/brand/app-icon.png', ImageFactory::png(512, 512));
        $this->write('config/brand/favicon.png', ImageFactory::png(64, 64));
        $this->write('config/seed.yaml', self::SEED);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->base);
    }

    public function testSeedsValuesAndImagesOnceAndAuditsLikeASave(): void
    {
        $seeder = $this->seeder();
        $report = $seeder->seedFile($this->base . '/config/seed.yaml');

        self::assertSame(['brand', 'appearance'], $report->sections);
        self::assertSame(['brand.name', 'brand.eyebrow', 'brand.logo_light', 'brand.app_icon', 'brand.favicon', 'appearance.preset_dark', 'appearance.light_accent'], $report->seeded);
        self::assertSame(['brand.wordmark' => SeedReport::SKIP_EMPTY, 'brand.mark' => SeedReport::SKIP_EMPTY, 'appearance.dark_accent' => SeedFileSeeder::SKIP_DEFAULT], $report->skipped);
        self::assertFalse($report->dryRun);

        $settings = $this->brand->settings;
        self::assertSame('Beacon', $settings->get('brand.name'));
        self::assertSame('#0f766e', $settings->get('appearance.light_accent'), 'lower-cased by the section filter');
        self::assertFalse($settings->isOverridden('appearance.dark_accent'));
        $icon = $settings->getFile('brand.app_icon');
        self::assertNotNull($icon);
        self::assertSame([512, 512, [512, 192, 180]], [$icon->width, $icon->height, $icon->variants()]);
        self::assertNotNull($settings->getFile('brand.logo_light')?->url());

        $events = $this->dispatcher->events;
        self::assertCount(2, $events);
        self::assertInstanceOf(SettingsChangedEvent::class, $events[0]);
        self::assertSame(['brand', SettingsChangedEvent::ORIGIN_SEED, ['name', 'eyebrow', 'logo_light', 'app_icon', 'favicon']], [$events[0]->section, $events[0]->origin, $events[0]->changedFields]);
        self::assertSame(['old' => null, 'new' => $icon->sha256], $events[0]->fileChanges['app_icon']);
        self::assertSame([], $events[0]->secretFields);
        $messages = array_column($this->logs(), 1);
        self::assertSame(['Instance settings saved.', 'Instance settings saved.', 'Instance settings seeded from a seed file.'], $messages);
        self::assertStringContainsString('app_icon=' . substr($icon->sha256, 0, 12) . ' image/png 512x512', (string) $this->logs()[0][2]['files']);
        self::assertArrayNotHasKey('files', $this->logs()[1][2], 'no image in the appearance section');
        self::assertStringNotContainsString('Beacon', json_encode($this->logs(), JSON_THROW_ON_ERROR), 'names only, never values');

        $rows  = count($this->brand->store->rows);
        $again = $seeder->seedFile($this->base . '/config/seed.yaml');
        self::assertFalse($again->hasChanges(), 'idempotent');
        self::assertSame(SeedReport::SKIP_STORED, $again->skipped['brand.app_icon']);
        self::assertSame(SeedReport::SKIP_STORED, $again->skipped['brand.name']);
        self::assertCount($rows, $this->brand->store->rows);
        self::assertCount(2, $this->dispatcher->events);
    }

    public function testStoredValuesAreNeverOverwritten(): void
    {
        $this->brand->settings->save('brand', ['name' => 'Admin choice']);
        $report = $this->seeder()->seed(['settings' => ['brand' => ['name' => 'Beacon']]], $this->base . '/config');

        self::assertSame([], $report->seeded);
        self::assertSame(['brand.name' => SeedReport::SKIP_STORED], $report->skipped);
        self::assertSame('Admin choice', $this->brand->settings->get('brand.name'));
    }

    public function testDryRunValidatesAndWritesNothing(): void
    {
        $report = $this->seeder()->seedFile($this->base . '/config/seed.yaml', dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertContains('brand.logo_light', $report->seeded);
        self::assertSame([], $this->brand->repository->rows);
        self::assertSame([], $this->brand->store->rows);
        self::assertSame([], $this->dispatcher->events);
        self::assertSame('Instance settings seed file checked (dry run).', $this->logs()[0][1]);
    }

    public function testEveryProblemIsReportedAndNothingIsWritten(): void
    {
        $errors = $this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seed([
            'extra'    => true,
            'settings' => [
                'nope'       => ['x' => 1],
                'brand'      => ['name' => 'Fine', 'colour' => 'x', 'logo_light' => 'brand/logo-light.png', 'eyebrow' => ['a list']],
                'relay'      => ['token' => 'tok-123', 'port' => 'not a port'],
                'appearance' => 'not a mapping',
            ],
            'files' => ['brand' => ['name' => 'brand/logo-light.png']],
        ], $this->base . '/config', ['brand', 'relay', 'appearance', 'ghost']));

        self::assertSame([
            'extra: unknown_key',
            'ghost: unknown_section',
            'nope: unknown_section',
            'settings.appearance: not_a_mapping',
            'brand.colour: unknown_field',
            'brand.logo_light: image_field_in_settings',
            'brand.eyebrow: not_scalar',
            'brand.name: not_an_image_field',
            'relay.token: secret_field',
            'relay.port: invalid_value',
        ], $this->codes($errors));
        self::assertStringNotContainsString('tok-123', implode("\n", $errors), 'never a value');
        self::assertSame([], $this->brand->repository->rows);
        self::assertSame([], $this->dispatcher->events);

        self::assertSame(['files: not_a_mapping'], $this->codes($this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seed(['files' => 'x'], $this->base))));
        self::assertSame(['brand.logo_light: files_disabled'], $this->codes($this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seed(['files' => ['brand' => ['logo_light' => 'brand/logo-light.png']]], $this->base . '/config'), null)));
    }

    public function testImagePathsAreConfinedToTheBaseDirectory(): void
    {
        $this->write('outside/evil.png', ImageFactory::png(240, 80));
        $outside = dirname($this->base) . '/' . bin2hex(random_bytes(4)) . '-outside.png';
        file_put_contents($outside, ImageFactory::png(240, 80));
        symlink($outside, $this->base . '/config/brand/link.png');
        mkdir($this->base . '/config/brand/dir.png');
        $seeder = new SeedFileSeeder($this->registry, $this->brand->repository, $this->brand->settings, $this->base . '/config', fileStore: $this->brand->store);

        $paths = [
            'logo_light' => $this->base . '/config/brand/logo-light.png',
            'logo_dark'  => '../outside/evil.png',
            'mark'       => 'brand/link.png',
            'app_icon'   => 'phar://brand/app-icon.png',
            'favicon'    => 'data:image/png;base64,iVBORw0KGgo=',
        ];
        self::assertSame([
            'brand.logo_light: path_invalid',
            'brand.logo_dark: path_outside_base',
            'brand.mark: path_outside_base',
            'brand.app_icon: path_invalid',
            'brand.favicon: path_invalid',
        ], $this->codes($this->refused(fn (): SeedReport => $seeder->seed(['files' => ['brand' => $paths]], $this->base . '/config'))));

        $more = ['logo_light' => 'http://example.test/logo.png', 'logo_dark' => "brand/logo-light.png\0.png", 'mark' => 'brand/missing.png', 'favicon' => 'brand/dir.png', 'app_icon' => 42];
        self::assertSame([
            'brand.logo_light: path_invalid',
            'brand.logo_dark: path_invalid',
            'brand.mark: path_not_found',
            'brand.favicon: not_a_file',
            'brand.app_icon: path_invalid',
        ], $this->codes($this->refused(fn (): SeedReport => $seeder->seed(['files' => ['brand' => $more]], $this->base . '/config'))));

        self::assertSame(['file: path_outside_base'], $this->codes($this->refused(fn (): SeedReport => $seeder->seed([], $this->base))));
        self::assertSame(['file: path_outside_base'], $this->codes($this->refused(fn (): SeedReport => (new SeedFileSeeder($this->registry, $this->brand->repository, $this->brand->settings, $this->base . '/missing'))->seed([], $this->base))));
        self::assertSame(['file: not_a_file'], $this->codes($this->refused(fn (): SeedReport => $seeder->seed([], $this->base . '/config/seed.yaml'))));
        @unlink($outside);
    }

    public function testImagesGoThroughTheUploadChecks(): void
    {
        $this->write('config/brand/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>');
        $this->write('config/brand/svg.png', "<?xml version=\"1.0\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"/>");
        $this->write('config/brand/small-icon.png', ImageFactory::png(256, 256));
        $this->write('config/brand/text.png', 'hello');
        $this->write('config/brand/huge.png', "\x89PNG\r\n\x1a\n" . str_repeat('x', 1_048_577));
        $this->write('config/brand/wide.png', ImageFactory::png(100, 70));

        $errors = $this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seed(['files' => ['brand' => [
            'logo_light' => 'brand/logo.svg',
            'logo_dark'  => 'brand/svg.png',
            'app_icon'   => 'brand/small-icon.png',
            'favicon'    => 'brand/text.png',
            'mark'       => 'brand/wide.png',
        ]]], $this->base . '/config'));
        self::assertSame(['brand.logo_light: svg_not_supported', 'brand.logo_dark: svg_not_supported', 'brand.app_icon: dimensions_too_small', 'brand.favicon: invalid_image', 'brand.mark: not_square'], $this->codes($errors));
        self::assertStringContainsString('use a PNG or WebP file', $errors[0]);

        self::assertSame(['brand.logo_light: too_large'], $this->codes($this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seed(['files' => ['brand' => ['logo_light' => 'brand/huge.png']]], $this->base . '/config'))));
    }

    public function testSeedFileFormats(): void
    {
        $this->write('config/seed.json', (string) json_encode(['schema' => SeedFileSeeder::SCHEMA, 'version' => 1, 'settings' => ['brand' => ['name' => 'Json']]]));
        $report = $this->seeder()->seedFile($this->base . '/config/seed.json');
        self::assertSame(['brand.name'], $report->seeded);

        $cases = [
            'seed.txt'   => ['schema: x', 'file: unsupported_format'],
            'bad.yaml'   => ["schema: [unclosed\n", 'file: parse_error'],
            'bad.json'   => ['{"schema":', 'file: parse_error'],
            'scalar.yml' => ['just a string', 'file: parse_error'],
            'schema.yml' => ["schema: other\nversion: 1\n", 'schema: invalid_schema'],
            'v2.yml'     => ["schema: nowo-instance-settings-seed\nversion: 2\n", 'version: unsupported_version'],
            'big.yaml'   => ['# ' . str_repeat('x', SeedFileSeeder::MAX_SEED_FILE_BYTES), 'file: file_too_large'],
        ];
        foreach ($cases as $name => [$content, $code]) {
            $this->write('config/' . $name, $content);
            self::assertSame([$code], $this->codes($this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seedFile($this->base . '/config/' . $name))), $name);
        }

        self::assertSame(['file: no_file'], $this->codes($this->refused(static fn (SeedFileSeeder $seeder): SeedReport => $seeder->seedFile())));
        $default = new SeedFileSeeder($this->registry, $this->brand->repository, $this->brand->settings, $this->base, $this->base . '/config/seed.yaml', $this->brand->store);
        self::assertSame($this->base . '/config/seed.yaml', $default->defaultFile());
        self::assertSame(['brand'], $default->seedFile(sections: ['brand'], dryRun: true)->sections);
        self::assertSame(['file: path_not_found'], $this->codes($this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seedFile($this->base . '/config/none.yaml'))));
        self::assertSame(['file: path_invalid'], $this->codes($this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seedFile('phar://' . $this->base . '/config/seed.yaml'))));

        self::assertSame(['schema: invalid_schema'], $this->codes($this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seed(['schema' => 'x'], $this->base))));
        self::assertSame(['version: unsupported_version'], $this->codes($this->refused(fn (SeedFileSeeder $seeder): SeedReport => $seeder->seed(['schema' => SeedFileSeeder::SCHEMA, 'version' => '1'], $this->base))));
    }

    public function testSeedImageStoresOneImageFillOnly(): void
    {
        $seeder = $this->seeder();
        $report = $seeder->seedImage('brand.logo_light', $this->base . '/config/brand/logo-light.png');
        self::assertSame(['brand.logo_light'], $report->seeded);
        self::assertNotNull($this->brand->settings->getFile('brand.logo_light'));

        self::assertSame(['brand.logo_dark'], $seeder->seedImage('brand.logo_dark', 'config/brand/logo-light.png', true)->seeded, 'relative to the base directory');
        self::assertSame(['brand.logo_light' => SeedReport::SKIP_STORED], $seeder->seedImage('brand.logo_light', 'config/brand/logo-light.png')->skipped);
        self::assertSame(['brand.name: not_an_image_field'], $this->codes($this->refused(static fn (): SeedReport => $seeder->seedImage('brand.name', 'config/brand/logo-light.png'))));
        self::assertSame(['brand.: unknown_field'], $this->codes($this->refused(static fn (): SeedReport => $seeder->seedImage('brand', 'config/brand/logo-light.png'))));
        self::assertSame(['brand.logo_dark: path_outside_base'], $this->codes($this->refused(static fn (): SeedReport => $seeder->seedImage('brand.logo_dark', '/etc/passwd'))));
    }

    public function testAConcurrentAdminSaveWinsAndALostRaceRetriesOnce(): void
    {
        $inner      = new InMemorySettingValueRepository();
        $repository = new RacingRepository($inner, new SettingValue('relay', 'host', new DateTimeImmutable()));
        $registry   = new SettingsSectionRegistry([new SeedFixtureSection()]);
        $settings   = new InstanceSettings($repository, $registry);
        $data       = ['settings' => ['relay' => ['host' => 'seed.example.test', 'port' => 2525]]];

        $report = (new SeedFileSeeder($registry, $repository, $settings, $this->base))->seed($data, $this->base);
        self::assertSame(['relay.port'], $report->seeded);
        self::assertSame(['relay.host' => SeedReport::SKIP_STORED], $report->skipped);
        self::assertSame(2, $repository->attempts);
        self::assertSame('admin.example.test', $settings->get('relay.host'));

        $racing        = new RacingRepository(new InMemorySettingValueRepository(), new SettingValue('relay', 'host', new DateTimeImmutable()));
        $racing->races = 5;
        try {
            (new SeedFileSeeder($registry, $racing, new InstanceSettings($racing, $registry), $this->base))->seed($data, $this->base);
            self::fail('expected the unique violation');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(2, $racing->attempts);
        }
    }

    public function testTheFilesystemStoreAdoptsACommittedFileLosslessly(): void
    {
        $files = $this->base . '/config/instance-files';
        $store = new FilesystemSettingFileStore($files);
        // A committed JPEG with EXIF / GPS and appended bytes: adopted without them, never served raw (JG-L3), and
        // without a re-encode (1.7.1): the pixels are untouched.
        $bytes = ImageFactory::jpegWithExif(1, 512, 512) . '<html><script>alert(1)</script>';
        $this->write('config/instance-files/brand/app_icon.jpg', $bytes);
        // A committed logo with no row yet: the seed points elsewhere, the committed file wins (JG-L5).
        $committed = ImageFactory::png(240, 80, 5);
        $this->write('config/instance-files/brand/logo_dark.png', $committed);
        touch($files . '/brand/logo_dark.png', time() - 3600);
        $darkStat = [filemtime($files . '/brand/logo_dark.png'), fileinode($files . '/brand/logo_dark.png')];

        $report = $this->filesystemSeeder($store)->seed(['files' => ['brand' => [
            'app_icon'   => 'instance-files/brand/app_icon.jpg',
            'logo_light' => 'brand/logo-light.png',
            'logo_dark'  => 'brand/logo-light.png',
        ]]], $this->base . '/config');
        self::assertSame(['brand.app_icon', 'brand.logo_light', 'brand.logo_dark'], $report->seeded);

        $adopted = (string) file_get_contents($files . '/brand/app_icon.jpg');
        self::assertSame(ImageFactory::jpeg(512, 512), $adopted, 'exactly the image without the EXIF segment and the trailer');
        self::assertStringNotContainsString('<script>', $adopted);
        self::assertStringNotContainsString('GPSLatitude', $adopted);
        self::assertSame($this->pixels($bytes), $this->pixels($adopted), 'decoded pixels identical');
        self::assertSame(hash('sha256', $adopted), $this->brandFile('brand.app_icon')->sha256);
        self::assertFileExists($files . '/brand/logo_light.png', 'a seed image elsewhere is processed into the stable file');

        // The clean committed PNG is adopted as it is: no write (same bytes, mtime and inode).
        clearstatcache();
        self::assertSame($committed, (string) file_get_contents($files . '/brand/logo_dark.png'));
        self::assertSame($darkStat, [filemtime($files . '/brand/logo_dark.png'), fileinode($files . '/brand/logo_dark.png')]);
        self::assertSame(hash('sha256', $committed), $this->brandFile('brand.logo_dark')->sha256, 'the committed file, not the seed path');
        self::assertSame(FilesystemSettingFileStore::GITIGNORE_CONTENT, (string) file_get_contents($files . '/brand/.variants/.gitignore'));

        // A fresh database (an e2e reset) seeds again from the same files: nothing is rewritten on disk.
        $paths  = [$files . '/brand/app_icon.jpg', $files . '/brand/logo_light.png', $files . '/brand/logo_dark.png'];
        $before = [];
        foreach ($paths as $path) {
            touch($path, time() - 7200);
            clearstatcache();
            $before[$path] = [hash_file('sha256', $path), filemtime($path), fileinode($path)];
        }
        $again = $this->filesystemSeeder($store)->seed(['files' => ['brand' => [
            'app_icon'   => 'instance-files/brand/app_icon.jpg',
            'logo_light' => 'brand/logo-light.png',
            'logo_dark'  => 'brand/logo-light.png',
        ]]], $this->base . '/config');
        self::assertSame(['brand.app_icon', 'brand.logo_light', 'brand.logo_dark'], $again->seeded);
        clearstatcache();
        foreach ($paths as $path) {
            self::assertSame($before[$path], [hash_file('sha256', $path), filemtime($path), fileinode($path)], $path);
        }
    }

    private function filesystemSeeder(FilesystemSettingFileStore $store): SeedFileSeeder
    {
        $this->fsBrand = new BrandFixture(store: $store);

        return new SeedFileSeeder(new SettingsSectionRegistry($this->fsBrand->sections()), $this->fsBrand->repository, $this->fsBrand->settings, $this->base, fileStore: $store, normalizer: new ValueNormalizer(Validation::createValidator()));
    }

    private function brandFile(string $key): StoredSettingFile
    {
        $file = $this->fsBrand?->settings->getFile($key);
        self::assertInstanceOf(StoredSettingFile::class, $file);

        return $file;
    }

    private function pixels(string $bytes): string
    {
        $image = imagecreatefromstring($bytes);
        self::assertInstanceOf(GdImage::class, $image);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function testAnInvalidBaseDirectoryIsRefusedWhenTheSeederIsBuilt(): void
    {
        foreach (['' => 'a local directory path', ' ' => 'a local directory path', 's3://bucket' => 'a local directory path', "a\0b" => 'a local directory path', 'config' => 'an absolute path', './seed' => 'an absolute path'] as $base => $reason) {
            try {
                new SeedFileSeeder($this->registry, $this->brand->repository, $this->brand->settings, (string) $base);
                self::fail('Expected the refusal.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('seed.base_dir must be ' . $reason, $e->getMessage());
            }
        }
    }

    /**
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function logs(): array
    {
        return $this->logger->records ?? [];
    }

    private function seeder(?SettingFileStoreInterface $store = null, ?SettingValueRepositoryInterface $repository = null, ?InstanceSettingsInterface $settings = null): SeedFileSeeder
    {
        $logger = $this->logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };

        return new SeedFileSeeder(
            $this->registry,
            $repository ?? $this->brand->repository,
            $settings ?? $this->brand->settings,
            $this->base,
            fileStore: $store ?? $this->brand->store,
            normalizer: new ValueNormalizer(Validation::createValidator()),
            logger: $logger,
            dispatcher: $this->dispatcher,
        );
    }

    /**
     * @param callable(SeedFileSeeder): mixed $callback
     *
     * @return list<string>
     */
    private function refused(callable $callback, ?SettingFileStoreInterface $store = new InMemorySettingFileStore()): array
    {
        $seeder = $store instanceof SettingFileStoreInterface
            ? $this->seeder($store)
            : new SeedFileSeeder($this->registry, $this->brand->repository, $this->brand->settings, $this->base, normalizer: new ValueNormalizer(Validation::createValidator()));
        try {
            $callback($seeder);
            self::fail('Expected a SeedFileException.');
        } catch (SeedFileException $e) {
            return $e->errors;
        }
    }

    /**
     * `<where>: <code>` of each problem line.
     *
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private function codes(array $errors): array
    {
        $codes = [];
        foreach ($errors as $line) {
            $codes[] = (string) preg_replace('/ \(.*\)$/s', '', $line);
        }

        return $codes;
    }

    private function write(string $path, string $content): void
    {
        $full = $this->base . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0o777, true);
        }
        file_put_contents($full, $content);
    }
}
