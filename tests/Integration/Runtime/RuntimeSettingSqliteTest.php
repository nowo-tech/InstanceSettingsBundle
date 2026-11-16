<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Runtime;

use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;

/**
 * 1.20: the runtime-setting adopter tests on an SQLite file shared by the kernels of a test.
 */
final class RuntimeSettingSqliteTest extends RuntimeSettingScenarios
{
    protected function databaseUrl(string $run): string
    {
        return 'sqlite:///' . InstanceSettingsTestKernel::baseDir() . '/' . $run . '.sqlite';
    }
}
