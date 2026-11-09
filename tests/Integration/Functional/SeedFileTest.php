<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Command\PurgeFilesCommand;
use Nowo\InstanceSettingsBundle\Command\SeedCommand;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function mkdir;
use function symlink;

/**
 * 1.7 (IS-3) in a real kernel: the host seed file loaded by `nowo:instance-settings:seed` into the database store (file
 * rows served by the file route) and into the filesystem store (stable files per field, no blob), plus admin uploads,
 * replacement, clearing and purge with the filesystem store.
 */
final class SeedFileTest extends KernelTestCase
{
    private const SEED = <<<'YAML'
        schema: nowo-instance-settings-seed
        version: 1
        settings:
          brand:
            name: Beacon
          appearance:
            light_accent: '#0f766e'
        files:
          brand:
            logo_light: brand/logo-light.png
            app_icon: brand/app-icon.png
        YAML;

    public function testSeedCommandStoresFileRowsServedByTheFileRoute(): void
    {
        $base   = $this->seedTree();
        $kernel = $this->brandKernel($base);
        $events = [];
        $this->dispatcher($kernel)->addListener(SettingsChangedEvent::class, static function (SettingsChangedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $tester = new CommandTester((new Application($kernel))->find(SeedCommand::NAME));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_value'));
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_file'));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Seeded 4 setting(s) from the seed file.', $tester->getDisplay());
        // app_icon: original + 3 variants; logo_light: original.
        self::assertSame(5, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_file'));
        self::assertCount(2, $events);
        self::assertSame(SettingsChangedEvent::ORIGIN_SEED, $events[0]->origin);
        self::assertNull($events[0]->fileChanges['logo_light']['old']);

        $settings = $this->settings($kernel);
        $settings->reset();
        self::assertSame('Beacon', $settings->get('brand.name'));
        $icon = $settings->getFile('brand.app_icon');
        self::assertNotNull($icon);
        $client = $this->client($kernel, null);
        $client->request('GET', (string) $icon->url(192));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Nothing to seed', $tester->getDisplay());
        self::assertSame(5, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_file'), 'idempotent');
        self::assertCount(2, $events);

        $seeder = $this->container($kernel)->get(SeedFileSeeder::class);
        self::assertInstanceOf(SeedFileSeeder::class, $seeder, 'public for host-side importers');
    }

    public function testARefusedSeedFileWritesNothing(): void
    {
        $base = $this->seedTree();
        file_put_contents($base . '/config/seed.yaml', self::SEED . "\n    logo_dark: ../../outside.png\n");
        file_put_contents(dirname($base) . '/outside.png', ImageFactory::png(240, 80));
        $kernel = $this->brandKernel($base);

        $tester = new CommandTester((new Application($kernel))->find(SeedCommand::NAME));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('brand.logo_dark: path_outside_base', $tester->getDisplay());
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_value'));
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_file'));
    }

    public function testFilesystemStoreKeepsStableFilesAndNoBlob(): void
    {
        $base   = $this->seedTree();
        $files  = $base . '/config/instance-files';
        $kernel = $this->brandKernel($base, ['store' => 'filesystem', 'directory' => $files]);
        $admin  = $this->client($kernel);

        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[logo_light][file]', $base . '/config/brand/logo-light.png');
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertFileExists($files . '/brand/logo_light.png', 'stable name per field, ready to commit');
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_file'), 'no database blob');

        $settings = $this->settings($kernel);
        $settings->reset();
        $logo = $settings->getFile('brand.logo_light');
        self::assertNotNull($logo);
        self::assertSame(hash('sha256', (string) file_get_contents($files . '/brand/logo_light.png')), $logo->sha256);
        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', (string) $logo->url());
        self::assertSame(200, $anonymous->getResponse()->getStatusCode());
        self::assertSame((string) file_get_contents($files . '/brand/logo_light.png'), $anonymous->getResponse()->getContent());
        $staleUrl = (string) $logo->url();

        // Replacing with another type: the old file goes, the old URL is gone, the new URL differs (no stale cache).
        $settings->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::webp(240, 80, 20))]);
        self::assertFileDoesNotExist($files . '/brand/logo_light.png');
        self::assertFileExists($files . '/brand/logo_light.webp');
        $anonymous->request('GET', $staleUrl);
        self::assertSame(404, $anonymous->getResponse()->getStatusCode());
        $settings->reset();
        self::assertNotSame($staleUrl, $settings->getFile('brand.logo_light')?->url());

        // Clearing the field deletes its file.
        $settings->save('brand', ['logo_light' => null]);
        self::assertSame([], glob($files . '/brand/logo_light.*'));

        // The seed adopts the committed app icon in place and processes the other image into its stable file.
        $icon = ImageFactory::png(512, 512, 90);
        file_put_contents($files . '/brand/app_icon.png', $icon);
        file_put_contents($base . '/config/seed.yaml', "schema: nowo-instance-settings-seed\nversion: 1\nfiles:\n  brand:\n    app_icon: instance-files/brand/app_icon.png\n    logo_dark: brand/logo-light.png\n");
        $tester = new CommandTester((new Application($kernel))->find(SeedCommand::NAME));
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame($icon, (string) file_get_contents($files . '/brand/app_icon.png'));
        self::assertFileExists($files . '/brand/logo_dark.png');
        self::assertCount(3, (array) glob($files . '/brand/.variants/app_icon-*'));
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_file'));
        $settings->reset();
        $anonymous->request('GET', (string) $settings->getFile('brand.app_icon')?->url(180));
        self::assertSame(200, $anonymous->getResponse()->getStatusCode());

        file_put_contents($files . '/brand/.variants/app_icon-ffffffffffff-512.png', 'stale');
        $purge = new CommandTester((new Application($kernel))->find(PurgeFilesCommand::NAME));
        self::assertSame(Command::SUCCESS, $purge->execute([]));
        self::assertFalse(file_exists($files . '/brand/.variants/app_icon-ffffffffffff-512.png'));
        self::assertFileExists($files . '/brand/app_icon.png');
    }

    public function testASymlinkedFieldFileIsNeverWrittenThrough(): void
    {
        $base   = $this->seedTree();
        $files  = $base . '/config/instance-files';
        $kernel = $this->brandKernel($base, ['store' => 'filesystem', 'directory' => $files]);
        mkdir($files . '/brand', 0o755, true);
        symlink($base . '/victim.png', $files . '/brand/logo_light.png');

        try {
            $this->settings($kernel)->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80))]);
            self::fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('symlink', $e->getMessage());
        }
        self::assertFileDoesNotExist($base . '/victim.png');
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne("SELECT COUNT(*) FROM instance_setting_value WHERE name = 'logo_light'"), 'rolled back');
    }

    /**
     * A host tree: `<base>/config/seed.yaml` and its images in `<base>/config/brand/`.
     */
    private function seedTree(): string
    {
        $base = InstanceSettingsTestKernel::baseDir() . '/seed-' . $this->name();
        mkdir($base . '/config/brand', 0o777, true);
        file_put_contents($base . '/config/brand/logo-light.png', ImageFactory::png(240, 80));
        file_put_contents($base . '/config/brand/app-icon.png', ImageFactory::png(512, 512));
        file_put_contents($base . '/config/seed.yaml', self::SEED);

        return $base;
    }

    /**
     * @param array<string, mixed> $files extra `files` options
     */
    private function brandKernel(string $base, array $files = []): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            [
                'brand' => ['enabled' => true, 'defaults' => ['name' => 'Acme']],
                'files' => $files,
                'seed'  => ['file' => $base . '/config/seed.yaml', 'base_dir' => $base],
            ],
            fixtures: false,
            extensions: ['nowo_ui_kit' => ['appearance' => ['enabled' => true]]],
        );
    }

    private function dispatcher(InstanceSettingsTestKernel $kernel): EventDispatcherInterface
    {
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
