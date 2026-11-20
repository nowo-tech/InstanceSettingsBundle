<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Kernel;

use function str_contains;

use const E_DEPRECATED;
use const E_USER_DEPRECATED;

/**
 * Booting with every optional table mapped (files, expiring secrets, runtime settings; the notice table is covered by the extension test) raises no
 * deprecation about the Doctrine mapping options: DoctrineBundle 3.4 deprecates `alias`, which the bundle no longer sets.
 */
final class DoctrineMappingDeprecationTest extends KernelTestCase
{
    public function testBootingRaisesNoMappingDeprecation(): void
    {
        $deprecations = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only, restored in the finally block)
        $previous = set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            if (str_contains($message, 'alias') || str_contains($message, 'mapping')) {
                $deprecations[] = $message;
            }

            return true;
        }, E_USER_DEPRECATED | E_DEPRECATED);

        try {
            $this->bootKernel([
                'files'            => ['enabled' => true],
                'expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC'],
                'runtime_setting'  => ['enabled' => true],
            ]);
        } finally {
            restore_error_handler();
            unset($previous);
        }

        self::assertSame([], $deprecations);
    }
}
