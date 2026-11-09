<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\Seed\SeedFileException;
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\TestEnvVarLoader;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Throwable;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function symlink;

/**
 * 1.7.1: `files.directory` and `seed.base_dir` accept `%env(...)%`. The container cannot see the value (Symfony hands the
 * tree validation '' for it), so the path rules — local path, not under the public web root even before the directory
 * exists (JG-L4 / JG-L6) — are applied at run time by the store and the seeder.
 */
final class EnvPathsTest extends KernelTestCase
{
    private const FILES = 'IS171_FILES_DIR';

    private const BASE = 'IS171_SEED_BASE';

    public function testEnvDirectoriesBootAndAreUsedAtRunTime(): void
    {
        $base = InstanceSettingsTestKernel::baseDir() . '/env-ok';
        mkdir($base . '/seed/brand', 0o755, true);
        file_put_contents($base . '/seed/brand/logo.png', ImageFactory::png(240, 80));
        // Relative segments are fine as long as they resolve outside the public root.
        $this->env(self::FILES, $base . '/x/../files');
        $this->env(self::BASE, $base . '/seed/../seed');
        $kernel = $this->kernel();

        $report = $this->seeder($kernel)->seedImage('brand.logo_light', 'brand/logo.png');
        self::assertSame(['brand.logo_light'], $report->seeded);
        self::assertFileExists($base . '/files/brand/logo_light.png');
        self::assertFileExists($base . '/files/brand/.variants/.gitignore');
    }

    public function testAnEmptyOrWrapperEnvValueIsRefusedForBothKeys(): void
    {
        $valid = InstanceSettingsTestKernel::baseDir() . '/env-valid';
        foreach (['' => 'a local directory path', '  ' => 'a local directory path', 's3://bucket/files' => 'a local directory path', 'phar://app.phar' => 'a local directory path', 'var/files' => 'an absolute path', '../files' => 'an absolute path'] as $value => $reason) {
            // The store is built when the kernel warms up (the DBAL middleware): the boot itself fails.
            $this->env(self::FILES, (string) $value);
            $this->env(self::BASE, $valid);
            $this->storeRefusal('files.directory must be ' . $reason);

            $this->env(self::FILES, $valid);
            $this->env(self::BASE, (string) $value);
            $kernel = $this->kernel();
            $this->refusal(fn (): mixed => $this->seeder($kernel), 'seed.base_dir must be ' . $reason);
        }
    }

    public function testAnEnvDirectoryUnderThePublicRootIsRefusedWhateverItsSpellingAndBeforeItExists(): void
    {
        $base = InstanceSettingsTestKernel::baseDir();
        mkdir($base . '/web', 0o755, true);
        mkdir($base . '/src', 0o755, true);
        symlink($base . '/web', $base . '/linked');
        $this->env(self::BASE, $base);
        foreach (['/web/files', '/src/../web/files', '//web/files', '/./web/files', '/web', '/linked/new/dir', '/src/../linked/x'] as $suffix) {
            $this->env(self::FILES, $base . $suffix);
            $this->storeRefusal('under the public web root');
        }
        self::assertDirectoryDoesNotExist($base . '/web/files');
        self::assertDirectoryDoesNotExist($base . '/web/new');
    }

    public function testAnEnvBaseDirConfinesToWhereItResolves(): void
    {
        $base = InstanceSettingsTestKernel::baseDir() . '/env-base';
        mkdir($base . '/seed/brand', 0o755, true);
        mkdir($base . '/other', 0o755, true);
        file_put_contents($base . '/other/logo.png', ImageFactory::png(240, 80));
        $this->env(self::FILES, $base . '/files');
        // `..` resolves to <base>/seed: a path that climbs out of it is still outside.
        $this->env(self::BASE, $base . '/other/../seed');
        $kernel = $this->kernel();

        try {
            $this->seeder($kernel)->seedImage('brand.logo_light', $base . '/other/logo.png');
            self::fail('Expected the refusal.');
        } catch (SeedFileException $e) {
            self::assertStringContainsString('path_outside_base', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($base . '/files');
    }

    private function kernel(): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            $this->config(),
            ['test.env_var_loader' => TestEnvVarLoader::class],
            fixtures: false,
            extensions: ['nowo_ui_kit' => ['appearance' => ['enabled' => true]]],
        );
    }

    /**
     * Boots a kernel whose store cannot be built and expects the refusal. Not tracked by the parent class: its shutdown
     * (Doctrine clears the entity manager, whose middleware needs the store) fails the same way, and is tolerated.
     */
    private function storeRefusal(string $message): void
    {
        $config = $this->config();
        $kernel = new InstanceSettingsTestKernel(
            'env_refusal_' . bin2hex(random_bytes(6)),
            static function (ContainerConfigurator $container) use ($config): void {
                $container->extension('nowo_instance_settings', $config);
                $container->extension('nowo_ui_kit', ['appearance' => ['enabled' => true]]);
                $container->services()->defaults()->autowire()->autoconfigure()->set('test.env_var_loader', TestEnvVarLoader::class);
            },
            static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
            },
        );
        try {
            $this->refusal(static function () use ($kernel): mixed {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                self::assertInstanceOf(ContainerInterface::class, $container);

                return $container->get(SettingFileStoreInterface::class);
            }, $message);
        } finally {
            try {
                $kernel->shutdown();
            } catch (RuntimeException) {
                // The same refusal again.
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'brand' => ['enabled' => true, 'public_dir' => InstanceSettingsTestKernel::baseDir() . '/web', 'defaults' => ['name' => 'Acme']],
            'files' => ['store' => 'filesystem', 'directory' => '%env(' . self::FILES . ')%'],
            'seed'  => ['base_dir' => '%env(' . self::BASE . ')%'],
        ];
    }

    private function seeder(InstanceSettingsTestKernel $kernel): SeedFileSeeder
    {
        $seeder = $this->container($kernel)->get(SeedFileSeeder::class);
        self::assertInstanceOf(SeedFileSeeder::class, $seeder);

        return $seeder;
    }

    private function env(string $name, string $value): void
    {
        TestEnvVarLoader::set($name, $value);
    }

    /**
     * Runs `$callback` and expects a refusal whose message (or a previous one) contains `$message`.
     */
    private function refusal(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException|RuntimeException $e) {
            $messages = '';
            for ($cause = $e; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
                $messages .= $cause->getMessage() . "\n";
            }
            self::assertStringContainsString($message, $messages);

            return;
        } catch (Throwable $e) {
            self::fail('Unexpected ' . $e::class . ': ' . $e->getMessage());
        }
        self::fail('Expected a refusal: ' . $message);
    }
}
