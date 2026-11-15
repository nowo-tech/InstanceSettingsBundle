<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Exception\SettingsConflictException;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\SectionSnapshot;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiringFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySecretExpiryStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingFileStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function str_contains;

/**
 * 1.19 (Beacon spec 239): a section form saved against the section as its editor opened it
 * ({@see InstanceSettings::snapshot()}, {@see InstanceSettings::saveSince()}).
 */
final class SnapshotSaveTest extends TestCase
{
    private InMemorySettingValueRepository $repository;

    private InMemorySecretExpiryStore $expiry;

    private InMemorySettingFileStore $files;

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingValueRepository();
        $this->expiry     = new InMemorySecretExpiryStore();
        $this->files      = new InMemorySettingFileStore();
    }

    public function testAFieldLeftAsShownKeepsTheOtherEditorsValue(): void
    {
        $mine   = $this->settings();
        $theirs = $this->settings();
        $mine->save('general', ['ratio' => 2.0]);
        $base = $mine->snapshot('general');

        $theirs->save('general', ['page_size' => 50, 'ratio' => 3.0]);
        $saved = $mine->saveSince('general', ['site_name' => 'Mine', 'page_size' => 20, 'ratio' => 2], $base);

        self::assertSame(['site_name'], $saved);
        self::assertSame(50, $theirs->getInt('general.page_size'));
        self::assertSame(3.0, $theirs->getFloat('general.ratio'), 'an int submitted for the float shown counts as left as shown');
        self::assertSame('Mine', $theirs->getString('general.site_name'));
    }

    public function testAFieldSetToWhatTheOtherEditorSavedIsNoConflict(): void
    {
        $mine   = $this->settings();
        $theirs = $this->settings();
        $base   = $mine->snapshot('general');

        $theirs->save('general', ['site_name' => 'Same']);

        self::assertSame([], $mine->saveSince('general', ['site_name' => 'Same'], $base));
    }

    public function testAFieldBothEditorsChangedIsAConflict(): void
    {
        $mine   = $this->settings();
        $theirs = $this->settings();
        $base   = $mine->snapshot('general');

        $theirs->save('general', ['site_name' => 'Theirs']);

        try {
            $mine->saveSince('general', ['site_name' => 'Mine', 'page_size' => 40], $base);
            self::fail('a conflict');
        } catch (SettingsConflictException $e) {
            self::assertSame('general', $e->section);
            self::assertSame(['site_name'], $e->fields);
            self::assertFalse(str_contains($e->getMessage(), 'Theirs'), 'no value in the message');
        }
        self::assertSame('Theirs', $theirs->getString('general.site_name'));
        self::assertSame(20, $theirs->getInt('general.page_size'), 'nothing of the refused save is written');
    }

    public function testASecretActedOnAfterAnotherEditorReplacedItIsAConflict(): void
    {
        $mine   = $this->settings();
        $theirs = $this->settings();
        $base   = $mine->snapshot('mailer');

        $theirs->save('mailer', ['dsn' => 'smtp://theirs']);

        $this->expectException(SettingsConflictException::class);
        $mine->saveSince('mailer', ['dsn' => null], $base);
    }

    public function testASecretNobodyChangedIsSaved(): void
    {
        $settings = $this->settings();
        $settings->save('mailer', ['dsn' => 'smtp://first']);
        $base = $settings->snapshot('mailer');

        self::assertSame(['dsn'], $settings->saveSince('mailer', ['dsn' => 'smtp://second'], $base));
        self::assertNotContains('smtp://first', $base->fingerprints, 'a fingerprint is never the secret');
    }

    public function testAnExpiryDateOfARotatedSecretIsAConflict(): void
    {
        $mine   = $this->settings();
        $theirs = $this->settings();
        $mine->save('providers', ['api_key' => 'sk-first', 'api_key:expires_on' => '2031-01-31']);
        $base = $mine->snapshot('providers');

        $theirs->save('providers', ['api_key' => 'sk-rotated']);

        try {
            $mine->saveSince('providers', ['api_key:expires_on' => '2032-06-30'], $base);
            self::fail('a conflict');
        } catch (SettingsConflictException $e) {
            self::assertSame(['api_key:expires_on'], $e->fields);
        }
        self::assertSame([], $this->expiry->findBySection('providers'));
    }

    public function testAnExpiryDateOfAnUnchangedSecretIsSaved(): void
    {
        $settings = $this->settings();
        $settings->save('providers', ['api_key' => 'sk-first', 'api_key:expires_on' => '2031-01-31']);
        $base = $settings->snapshot('providers');

        self::assertSame(['api_key:expires_on'], $settings->saveSince('providers', ['api_key:expires_on' => '2032-06-30'], $base));
    }

    public function testAnImageReplacedMeanwhileIsAConflictAndItsReferenceIsKept(): void
    {
        $mine   = $this->settings();
        $theirs = $this->settings();
        $base   = $mine->snapshot('brand');

        $theirs->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(40, 40))]);
        $reference = $theirs->snapshot('brand');
        self::assertNotSame($base->fingerprint('logo'), $reference->fingerprint('logo'));

        // The stored reference submitted again (an API caller) keeps the other editor's image.
        $file = $theirs->getFile('brand.logo');
        self::assertNotNull($file);
        $mine->reset();
        self::assertSame([], $mine->saveSince('brand', ['logo' => 'isf:' . $file->sha256], $base));

        $this->expectException(SettingsConflictException::class);
        $mine->saveSince('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png(20, 20))], $base);
    }

    public function testAFieldUnknownToTheSnapshotIsSavedAsUsual(): void
    {
        $settings = $this->settings();
        $base     = new SectionSnapshot('general', ['footer' => '0000000000000000']);

        self::assertSame(['site_name'], $settings->saveSince('general', ['site_name' => 'Plain'], $base));
    }

    public function testTheSnapshotOfAnUnstoredColourIsEmptyAndKeyedBySecret(): void
    {
        $one = $this->settings('one')->snapshot('general');
        $two = $this->settings('two')->snapshot('general');

        self::assertNotSame($one->fingerprints, $two->fingerprints, 'keyed with kernel.secret');
        self::assertSame($one->fingerprints, $this->settings('one')->snapshot('general')->fingerprints, 'stable');
    }

    private function settings(string $key = 'test-secret'): InstanceSettings
    {
        return new InstanceSettings(
            $this->repository,
            new SettingsSectionRegistry([new GeneralFixtureSection(), new MailerFixtureSection(), new ExpiringFixtureSection(), new ImageFixtureSection()]),
            new ValueNormalizer(),
            null,
            null,
            new MockClock('2026-11-15 10:00:00'),
            null,
            $this->files,
            expiryStore: $this->expiry,
            snapshotKey: $key,
        );
    }
}
