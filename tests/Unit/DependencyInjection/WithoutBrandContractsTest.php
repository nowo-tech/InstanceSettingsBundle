<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\InstanceSettingsBundle\DependencyInjection\Configuration;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function spl_autoload_register;
use function str_starts_with;

/**
 * nowo-tech/brand-contracts is optional: hosts without it (and without brand.enabled) must build the configuration and
 * the container without loading any contract class (PHPStan checks that BrandSection::FIXED_SLOTS equals
 * BrandAssetSlot::FIXED).
 */
final class WithoutBrandContractsTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testConfigurationAndLoadNeverTouchTheContract(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (str_starts_with($class, 'Nowo\\Brand\\Contract\\')) {
                throw new LogicException('brand-contracts loaded: ' . $class);
            }
        }, true, true);

        $processed = (new Processor())->processConfiguration(new Configuration(), [['files' => ['enabled' => true]]]);
        self::assertFalse($processed['brand']['enabled']);

        $container = new ContainerBuilder();
        (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false]]], $container);
        self::assertFalse($container->getParameter('nowo_instance_settings.brand.enabled'));
    }
}
