<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Database;

use Nowo\InstanceSettingsBundle\Tests\Integration\Runtime\RuntimeSettingScenarios;

use function getenv;
use function is_string;

/**
 * 1.20: the runtime-setting adopter tests on a real database (`IS_TEST_DATABASE_URL`: MySQL / MariaDB / PostgreSQL),
 * skipped without it (the CI job runs with `--fail-on-skipped`).
 */
final class RuntimeSettingDatabaseTest extends RuntimeSettingScenarios
{
    protected function setUp(): void
    {
        $url = getenv('IS_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('IS_TEST_DATABASE_URL is not set (a MySQL / MariaDB / PostgreSQL database).');
        }
        parent::setUp();
    }

    protected function databaseUrl(string $run): string
    {
        return (string) getenv('IS_TEST_DATABASE_URL');
    }
}
