<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Portability;

use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\PortabilityEnvelope;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingFileStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function hash;
use function str_repeat;

/**
 * Envelope version 2: image references in `settings`, bytes in `files`, full re-validation on import (S2 test plan).
 */
final class ImagePortabilityTest extends TestCase
{
    private InMemorySettingFileStore $store;

    private InstanceSettings $settings;

    private ConfigPortability $portability;

    protected function setUp(): void
    {
        [, $this->store, $this->settings, $this->portability] = $this->instance();
    }

    public function testExportWithoutImageFieldsStaysVersionOne(): void
    {
        $registry    = new SettingsSectionRegistry([new GeneralFixtureSection()]);
        $repository  = new InMemorySettingValueRepository();
        $portability = new ConfigPortability($registry, new InstanceSettings($repository, $registry), $repository);

        $payload = $portability->export(null, false, true);
        self::assertSame(PortabilityEnvelope::VERSION_WITHOUT_FILES, $payload['version']);
        self::assertArrayNotHasKey('files', $payload);
        self::assertFalse($portability->hasImageFields());
    }

    public function testExportCarriesReferencesAndOptionallyTheBytes(): void
    {
        $this->settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32))]);
        $sha = (string) $this->settings->getFile('brand.logo')?->sha256;

        $light = $this->portability->export();
        self::assertSame(PortabilityEnvelope::VERSION, $light['version']);
        self::assertSame(['name' => 'Acme', 'logo' => 'isf:' . $sha, 'icon' => null, 'scan' => null], $light['settings']['brand']);
        self::assertArrayNotHasKey('files', $light);
        self::assertTrue($this->portability->hasImageFields());

        $full = $this->portability->export(['brand'], false, true);
        self::assertSame(['brand.logo'], array_keys($full['files']));
        self::assertSame('image/png', $full['files']['brand.logo']['type']);
        self::assertSame($sha, $full['files']['brand.logo']['sha256']);
        self::assertSame(hash('sha256', (string) base64_decode($full['files']['brand.logo']['data'], true)), $sha);
        self::assertArrayNotHasKey('local_only', $full['settings']['brand'], 'not portable');

        $this->expectException(PortabilityException::class);
        $this->expectExceptionMessage('files_too_large');
        [, , $cappedSettings, $capped] = $this->instance(100);
        $cappedSettings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32))]);
        $capped->export(null, false, true);
    }

    public function testRoundTripToAnotherInstance(): void
    {
        $this->settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(64, 32)), 'scan' => ImageUpload::fromBytes(ImageFactory::jpeg())]);
        $payload = $this->portability->export(null, false, true);

        [, $store, $settings, $portability] = $this->instance();
        $dry                                = $portability->import($payload, true);
        self::assertTrue($dry->dryRun);
        self::assertSame([], $store->rows, 'dry run writes nothing');

        $result = $portability->import($payload);
        self::assertContains('brand.logo', $result->changedKeys());
        self::assertContains('brand.scan', $result->changedKeys());
        self::assertSame($this->settings->getFile('brand.logo')?->sha256, $settings->getFile('brand.logo')?->sha256, 'PNG re-encode is stable');
        self::assertSame('image/jpeg', $settings->getFile('brand.scan')?->mimeType);

        // Importing the same file again changes nothing (the re-encode of the same bytes is deterministic).
        self::assertFalse($portability->import($payload)->hasChanges());
        // A reference equal to the current one needs no file; any other reference without its file is refused (a JPEG
        // is re-encoded on import, so its source reference may differ from the target's, depending on libjpeg).
        unset($payload['files']);
        $payload['settings']['brand']['scan'] = 'isf:' . str_repeat('9', 64);
        try {
            $portability->import($payload);
            self::fail('Expected missing_image_file');
        } catch (PortabilityException $e) {
            self::assertSame('missing_image_file:files.brand.scan', $e->getMessage());
        }
        unset($payload['settings']['brand']['scan']);
        self::assertFalse($portability->import($payload)->hasChanges());

        // null removes the stored image.
        $payload['settings']['brand']['logo'] = null;
        self::assertContains('brand.logo', $portability->import($payload)->changedKeys());
        self::assertNull($settings->getFile('brand.logo'));
    }

    public function testVersionOneFilesImportUnchanged(): void
    {
        $result = $this->portability->import(['schema' => 'nowo-instance-settings', 'version' => 1, 'settings' => ['brand' => ['name' => 'Beacon']]]);
        self::assertSame(['brand.name'], $result->changedKeys());

        $this->assertRefused('unknown_key:files', ['schema' => 'nowo-instance-settings', 'version' => 1, 'settings' => ['brand' => ['name' => 'B']], 'files' => []]);
        $this->assertRefused('unsupported_version', ['schema' => 'nowo-instance-settings', 'version' => 4, 'settings' => []]);
    }

    /**
     * Every tampering refuses the whole import and names the key, never the content.
     */
    public function testTamperedFilesAreRefused(): void
    {
        $png  = ImageFactory::png();
        $sha  = hash('sha256', $png);
        $file = ['type' => 'image/png', 'sha256' => $sha, 'data' => base64_encode($png)];
        $ok   = $this->envelope(['name' => 'Changed', 'logo' => 'isf:' . $sha], ['brand.logo' => $file]);

        $cases = [
            'invalid_files'                       => ['files' => 'nope'],
            'invalid_file:files.brand.logo'       => ['files' => ['brand.logo' => ['type' => 'image/png', 'sha256' => $sha]]],
            'invalid_file:files.brand.logo#2'     => ['files' => ['brand.logo' => ['type' => 'image/png', 'sha256' => $sha, 'data' => 1]]],
            'invalid_file:files.brand.logo#3'     => ['files' => ['brand.logo' => ['data' => base64_encode($png), 'sha256' => $sha, 'type' => 'image/png', 'extra' => 'x']]],
            'invalid_file:files.brand.logo#4'     => ['files' => ['brand.logo' => ['type' => 'image/png', 'sha256' => $sha, 'data' => '!!not base64!!']]],
            'invalid_file:files.brand.logo#5'     => ['files' => ['brand.logo' => ['type' => 'image/jpeg', 'sha256' => $sha, 'data' => base64_encode($png)]]],
            'invalid_file:files.brand.logo#6'     => ['files' => ['brand.logo' => ['type' => 'image/svg+xml', 'sha256' => $sha, 'data' => base64_encode($png)]]],
            'file_hash_mismatch:files.brand.logo' => ['files' => ['brand.logo' => ['type' => 'image/png', 'sha256' => $sha, 'data' => base64_encode(ImageFactory::png(64, 64, 1))]]],
            'unexpected_file:files.brand.logo'    => ['files' => ['brand.logo' => ['type' => 'image/png', 'sha256' => str_repeat('c', 64), 'data' => base64_encode($png)]]],
            'unexpected_file:files.brand.icon'    => ['files' => ['brand.logo' => $file, 'brand.icon' => $file]],
            'unexpected_file:files.brandlogo'     => ['files' => ['brandlogo' => $file]],
            'missing_image_file:files.brand.logo' => ['files' => []],
            'invalid_value:brand.logo'            => ['settings' => ['brand' => ['logo' => '/var/www/logo.png']]],
            'unknown_key:brand.nope'              => ['settings' => ['brand' => ['nope' => 'isf:' . $sha]]],
            'not_portable:brand.local_only'       => ['settings' => ['brand' => ['local_only' => 'isf:' . $sha]]],
        ];
        foreach ($cases as $reason => $override) {
            $this->assertRefused((string) preg_replace('/#\d+$/', '', $reason), $override + $ok);
        }

        // Content that is not an accepted image even though the hash matches: polyglot SVG, oversized, wrong type.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->assertRefused('invalid_file:files.brand.logo', $this->envelope(['logo' => 'isf:' . hash('sha256', $svg)], ['brand.logo' => ['type' => 'image/png', 'sha256' => hash('sha256', $svg), 'data' => base64_encode($svg)]]));
        $bomb = ImageFactory::pngHeader(30_000, 30_000);
        $this->assertRefused('invalid_file:files.brand.logo', $this->envelope(['logo' => 'isf:' . hash('sha256', $bomb)], ['brand.logo' => ['type' => 'image/png', 'sha256' => hash('sha256', $bomb), 'data' => base64_encode($bomb)]]));
        $big = ImageFactory::jpeg(700, 700);
        $big = hash('sha256', $big . str_repeat("\0", 200_000));
        $this->assertRefused('file_too_large:files.brand.scan', $this->envelope(['scan' => 'isf:' . $big], ['brand.scan' => ['type' => 'image/jpeg', 'sha256' => $big, 'data' => base64_encode(ImageFactory::jpeg(700, 700) . str_repeat("\0", 200_000))]]));
        // Within the base64 bound, one byte above the field cap once decoded.
        $slightly = base64_encode("\xFF\xD8\xFF" . str_repeat("\0", 199_998));
        $this->assertRefused('file_too_large:files.brand.scan', $this->envelope(['scan' => 'isf:' . str_repeat('d', 64)], ['brand.scan' => ['type' => 'image/jpeg', 'sha256' => str_repeat('d', 64), 'data' => $slightly]]));

        // Total cap.
        [, , , $capped] = $this->instance(100);
        try {
            $capped->import($ok);
            self::fail('Expected files_too_large');
        } catch (PortabilityException $e) {
            self::assertSame('files_too_large', $e->getMessage());
        }

        self::assertSame([], $this->store->rows, 'nothing was written');
        self::assertSame('Acme', $this->settings->get('brand.name'), 'all-or-nothing: the plain value of a refused file is not applied');

        self::assertSame(['brand.name', 'brand.logo'], $this->portability->import($ok)->changedKeys());
    }

    public function testFilesForAForbiddenSectionAreRefusedBeforeAnyDecoding(): void
    {
        $this->assertRefused('forbidden_section:brand', ['schema' => 'nowo-instance-settings', 'version' => 2, 'files' => ['brand.logo' => ['type' => 'x', 'sha256' => 'y', 'data' => 'z']], 'settings' => ['general' => ['site_name' => 'x']]], ['general']);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $allowed
     */
    private function assertRefused(string $reason, array $payload, ?array $allowed = null): void
    {
        try {
            $this->portability->import($payload, false, $allowed);
            self::fail('Expected PortabilityException ' . $reason);
        } catch (PortabilityException $e) {
            self::assertSame($reason, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $brand
     * @param array<string, mixed> $files
     *
     * @return array<string, mixed>
     */
    private function envelope(array $brand, array $files): array
    {
        return ['schema' => 'nowo-instance-settings', 'version' => 2, 'settings' => ['brand' => $brand], 'files' => $files];
    }

    /**
     * @return array{0: InMemorySettingValueRepository, 1: InMemorySettingFileStore, 2: InstanceSettings, 3: ConfigPortability}
     */
    private function instance(int $maxFileBytes = ConfigPortability::DEFAULT_MAX_FILE_BYTES): array
    {
        $registry   = new SettingsSectionRegistry([new ImageFixtureSection(), new GeneralFixtureSection(), new MailerFixtureSection()]);
        $repository = new InMemorySettingValueRepository();
        $store      = new InMemorySettingFileStore();
        $settings   = new InstanceSettings($repository, $registry, fileStore: $store);

        return [$repository, $store, $settings, new ConfigPortability($registry, $settings, $repository, fileStore: $store, maxFileBytes: $maxFileBytes)];
    }
}
