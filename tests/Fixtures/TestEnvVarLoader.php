<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;

/**
 * Environment variables of a test kernel without touching the process environment or the superglobals: the container
 * asks this loader for a variable it does not find there. The values live in a JSON file of the test base directory
 * (removed with it after each test); {@see self::set()} them before booting the kernel.
 */
final class TestEnvVarLoader implements EnvVarLoaderInterface
{
    /**
     * Sets one variable for the next kernels.
     */
    public static function set(string $name, string $value): void
    {
        if (!is_dir(dirname(self::file()))) {
            mkdir(dirname(self::file()), 0o755, true);
        }
        file_put_contents(self::file(), (string) json_encode([$name => $value] + self::read()));
    }

    /**
     * @return array<string, string>
     */
    public function loadEnvVars(): array
    {
        return self::read();
    }

    /**
     * @return array<string, string>
     */
    private static function read(): array
    {
        $vars = is_file(self::file()) ? json_decode((string) file_get_contents(self::file()), true) : [];
        $read = [];
        foreach (is_array($vars) ? $vars : [] as $name => $value) {
            $read[(string) $name] = (string) $value;
        }

        return $read;
    }

    private static function file(): string
    {
        return InstanceSettingsTestKernel::baseDir() . '/test-env.json';
    }
}
