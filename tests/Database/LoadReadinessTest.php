<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Database;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Repository\SettingRowWriterInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Repository\StoredSettingRow;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiringFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SeedFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Throwable;

/**
 * 1.19 (Beacon spec 239): the races of the settings write paths, on a real database. Each test plays two requests in one
 * process: this kernel, and a peer kernel on its own connection to the same database. The peer runs either between the
 * page load and the submit of a form, or right before this request's first write ({@see StatementHook}).
 *
 * Every test fails on 1.18.1 (lost or reverted values, a 500 on a unique key or a lock wait, a stale memo) and passes on
 * 1.19, on MySQL 8.4 and PostgreSQL 16, with DBAL 3 and 4.
 */
final class LoadReadinessTest extends DatabaseTestCase
{
    /** The first write of this request on the settings table (1.19: the section lock; 1.18: the row itself). */
    private const FIRST_WRITE = '/^\s*(INSERT|UPDATE|DELETE)\b.*\binstance_setting_value\b/is';

    private const EXPIRING = ['expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC']];

    /**
     * R1 — a save judged "unchanged" on a stale read: the peer changes `site_name` after this save read the section and
     * before it wrote. 1.18.1 skipped `site_name` (same as read) and lost this request's value.
     */
    public function testASaveRereadsTheSectionUnderItsLock(): void
    {
        $kernel = $this->boot(hook: true);
        $peer   = $this->boot();
        $this->settings($kernel)->save('general', ['site_name' => 'Mine', 'page_size' => 10]);

        $this->hook($kernel)->arm(self::FIRST_WRITE, function () use ($peer): void {
            $this->settings($peer)->save('general', ['site_name' => 'Theirs']);
        });
        $saved = $this->settings($kernel)->save('general', ['site_name' => 'Mine', 'page_size' => 30]);

        self::assertSame(1, $this->hook($kernel)->fired);
        self::assertContains('site_name', $saved, 'the value is written again over the concurrent one');
        self::assertSame('Mine', $this->stored($kernel, 'general', 'site_name'), 'the last save wins');
        self::assertSame(30, $this->stored($kernel, 'general', 'page_size'));
    }

    /**
     * R2 — two first saves of one field: the peer inserts the row after this save found none. 1.18.1 failed with a unique
     * key violation (HTTP 500).
     */
    public function testTwoFirstSavesOfOneFieldBothSucceed(): void
    {
        $kernel = $this->boot(hook: true);
        $peer   = $this->boot();

        $this->hook($kernel)->arm(self::FIRST_WRITE, function () use ($peer): void {
            $this->settings($peer)->save('general', ['site_name' => 'Theirs']);
        });
        $saved = $this->settings($kernel)->save('general', ['site_name' => 'Mine']);

        self::assertSame(1, $this->hook($kernel)->fired);
        self::assertSame(['site_name'], $saved);
        self::assertSame('Mine', $this->stored($kernel, 'general', 'site_name'));
    }

    /**
     * R3 — the section form reverted a field another editor saved after the page was loaded (lost update). 1.19 keeps the
     * other editor's value for a field this editor left as shown.
     */
    public function testTheFormKeepsAFieldAnotherEditorSavedMeanwhile(): void
    {
        $kernel = $this->boot();
        $peer   = $this->boot();
        $client = $this->client($kernel);
        $form   = $client->request('GET', '/admin/settings/sections/general')->filter('form[name="settings_section"]')->form();

        $this->settings($peer)->save('general', ['page_size' => 50]);
        $client->submit($form, ['settings_section[site_name]' => 'Mine']);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('Mine', $this->stored($kernel, 'general', 'site_name'));
        self::assertSame(50, $this->stored($kernel, 'general', 'page_size'), 'the other editor\'s value is kept');
    }

    /**
     * R3 — both editors changed the same field: 1.18.1 silently overwrote the other editor's value. 1.19 refuses the
     * save with 409 and an error on the field; nothing is written.
     */
    public function testTheFormRefusesAFieldBothEditorsChanged(): void
    {
        $kernel = $this->boot();
        $peer   = $this->boot();
        $client = $this->client($kernel);
        $form   = $client->request('GET', '/admin/settings/sections/general')->filter('form[name="settings_section"]')->form();

        $this->settings($peer)->save('general', ['site_name' => 'Theirs']);
        $client->submit($form, ['settings_section[site_name]' => 'Mine', 'settings_section[page_size]' => '40']);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('changed this setting after you opened the page', (string) $client->getResponse()->getContent());
        self::assertSame('Theirs', $this->stored($kernel, 'general', 'site_name'));
        self::assertNull($this->stored($kernel, 'general', 'page_size'), 'nothing of the refused save is written');
    }

    /**
     * R4 — a secret stored by another editor between this request's checks and its write: 1.18.1 overwrote it (or failed
     * with a unique key violation). 1.19 compares under the section lock and refuses with 409.
     */
    public function testTheFormRefusesASecretStoredMeanwhile(): void
    {
        $kernel = $this->boot(hook: true);
        $peer   = $this->boot();
        $client = $this->client($kernel);
        $form   = $client->request('GET', '/admin/settings/sections/mailer')->filter('form[name="settings_section"]')->form();

        $this->hook($kernel)->arm(self::FIRST_WRITE, function () use ($peer): void {
            $this->settings($peer)->save('mailer', ['dsn' => 'smtp://theirs.example.test']);
        });
        $client->submit($form, ['settings_section[dsn][value]' => 'smtp://mine.example.test']);

        self::assertSame(1, $this->hook($kernel)->fired);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('theirs.example.test', (string) $client->getResponse()->getContent(), 'no secret in the answer');
        $this->settings($peer)->reset();
        self::assertSame('smtp://theirs.example.test', $this->settings($peer)->getSecret('mailer.dsn'));
    }

    /**
     * R5 — an expiry date typed for the secret shown, after another editor rotated it: 1.18.1 put the old secret's date
     * on the new secret. 1.19 refuses with 409 (the date belongs to the secret it was typed for).
     */
    public function testTheFormRefusesTheDateOfARotatedSecret(): void
    {
        $kernel = $this->boot(self::EXPIRING, ['providers_section' => ExpiringFixtureSection::class]);
        $peer   = $this->boot(self::EXPIRING, ['providers_section' => ExpiringFixtureSection::class]);
        $this->settings($kernel)->save('providers', ['api_key' => 'sk-first', 'api_key:expires_on' => '2031-01-31']);
        $client = $this->client($kernel);
        $form   = $client->request('GET', '/admin/settings/sections/providers')->filter('form[name="settings_section"]')->form();

        $this->settings($peer)->save('providers', ['api_key' => 'sk-rotated']);
        $client->submit($form, ['settings_section[api_key:expires_on]' => '2032-06-30']);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertFalse($this->connection($kernel)->fetchOne('SELECT expires_on FROM instance_setting_secret_expiry WHERE section = ? AND name = ?', ['providers', 'api_key']), 'the rotated secret has no date');
    }

    /**
     * R6 — a save that fails (here a lock wait) must not leave its values in the request memo: 1.18.1 changed the
     * memoised rows in place and kept serving the unsaved value for the rest of the request (or worker unit).
     */
    public function testAFailedSaveLeavesNothingInTheMemo(): void
    {
        $kernel = $this->boot();
        $peer   = $this->boot();
        $this->settings($kernel)->save('general', ['site_name' => 'Stored']);
        self::assertSame('Stored', $this->settings($kernel)->getString('general.site_name'));

        $this->lockTimeout($kernel, 1);
        $failed = $this->connection($peer)->transactional(function ($blocker) use ($kernel): bool {
            $blocker->fetchAllAssociative('SELECT id FROM instance_setting_value WHERE section = ? AND name = ? FOR UPDATE', ['general', 'site_name']);
            try {
                $this->settings($kernel)->save('general', ['site_name' => 'Unsaved']);
            } catch (Throwable) {
                // Expected: the lock wait timed out.
                return true;
            }

            return false;
        });

        self::assertTrue($failed, 'the save waits for the lock and gives up');

        self::assertSame('Stored', $this->settings($kernel)->getString('general.site_name'), 'the memo never serves an unsaved value');
    }

    /**
     * R7 — a transient failure on a route of the bundle answers 503 with Retry-After (1.18.1: 500); the storage error is
     * only the previous exception of a fixed-message ServiceUnavailableHttpException.
     */
    public function testALockWaitOnTheSectionPageAnswers503(): void
    {
        $kernel = $this->boot();
        $peer   = $this->boot();
        $this->settings($kernel)->save('general', ['site_name' => 'Stored']);
        $client = $this->client($kernel);
        $form   = $client->request('GET', '/admin/settings/sections/general')->filter('form[name="settings_section"]')->form();

        $this->lockTimeout($kernel, 1);
        $this->connection($peer)->transactional(static function ($blocker) use ($client, $form): void {
            $blocker->fetchAllAssociative('SELECT id FROM instance_setting_value WHERE section = ? AND name = ? FOR UPDATE', ['general', 'site_name']);
            $client->submit($form, ['settings_section[site_name]' => 'Mine']);
        });

        $response = $client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('Stored', $this->stored($kernel, 'general', 'site_name'));
    }

    /**
     * R8 — an import and a concurrent first save of one of its fields: 1.18.1 failed with a unique key violation.
     */
    public function testAnImportAndAConcurrentFirstSave(): void
    {
        $kernel      = $this->boot(hook: true);
        $peer        = $this->boot();
        $portability = $this->container($kernel)->get(ConfigPortability::class);
        self::assertInstanceOf(ConfigPortability::class, $portability);
        $this->settings($kernel)->save('general', ['site_name' => 'Imported']);
        $payload = $portability->export(['general']);
        $this->connection($kernel)->executeStatement('DELETE FROM instance_setting_value');
        $this->settings($kernel)->reset();

        $this->hook($kernel)->arm(self::FIRST_WRITE, function () use ($peer): void {
            $this->settings($peer)->save('general', ['site_name' => 'Theirs']);
        });
        $result = $portability->import($payload);

        self::assertSame(1, $this->hook($kernel)->fired);
        self::assertContains('general.site_name', $result->changedKeys());
        self::assertSame('Imported', $this->stored($kernel, 'general', 'site_name'));
    }

    /**
     * R9 — a raw row write ({@see SettingRowWriterInterface}) and a concurrent first save: 1.18.1 failed with a unique
     * key violation.
     */
    public function testARawRowWriteAndAConcurrentFirstSave(): void
    {
        $kernel = $this->boot(hook: true);
        $peer   = $this->boot();
        $writer = $this->container($kernel)->get(SettingRowWriterInterface::class);
        self::assertInstanceOf(SettingRowWriterInterface::class, $writer);

        $this->hook($kernel)->arm(self::FIRST_WRITE, function () use ($peer): void {
            $this->settings($peer)->save('general', ['site_name' => 'Theirs']);
        });
        $writer->writeRows([new StoredSettingRow('general', 'site_name', 'Restored', false, null, new DateTimeImmutable())]);

        self::assertSame(1, $this->hook($kernel)->fired);
        self::assertSame('Restored', $this->stored($kernel, 'general', 'site_name'));
    }

    /**
     * Not a race of 1.18.1 (kept as a regression test): a seed and a concurrent admin save of the same field. The seed is
     * insert-only and re-checks under the lock (1.19) or retries its unit (1.18), so the admin's value stays.
     */
    public function testASeedNeverOverwritesAConcurrentSave(): void
    {
        $kernel   = $this->boot(hook: true);
        $peer     = $this->boot();
        $registry = new SettingsSectionRegistry([new SeedFixtureSection([SettingFallback::plain('host', 'seeded.example.test'), SettingFallback::plain('port', 2525)])]);
        $seeder   = new FallbackSeeder($registry, $this->repository($kernel), $this->settings($kernel));

        $this->hook($kernel)->arm(self::FIRST_WRITE, function () use ($peer): void {
            $writer = $this->container($peer)->get(SettingRowWriterInterface::class);
            self::assertInstanceOf(SettingRowWriterInterface::class, $writer);
            $writer->writeRows([new StoredSettingRow('relay', 'host', 'admin.example.test', false, null, new DateTimeImmutable())]);
        });
        $report = $seeder->seed();

        self::assertSame(1, $this->hook($kernel)->fired);
        self::assertSame(['relay.port'], $report->seeded);
        self::assertSame('admin.example.test', $this->stored($kernel, 'relay', 'host'));
        self::assertSame(2525, $this->stored($kernel, 'relay', 'port'));
    }

    private function repository(InstanceSettingsTestKernel $kernel): SettingValueRepositoryInterface
    {
        $repository = $this->container($kernel)->get(SettingValueRepositoryInterface::class);
        self::assertInstanceOf(SettingValueRepositoryInterface::class, $repository);

        return $repository;
    }
}
