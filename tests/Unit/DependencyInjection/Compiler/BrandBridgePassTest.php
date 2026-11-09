<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\InstanceSettingsBundle\Brand\InstanceBrandProvider;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\BrandBridgePass;
use Nowo\UiKitBundle\DependencyInjection\Compiler\BrandAppearanceProviderPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bridge takes UiKit's appearance provider over only with brand.enabled, UiKit's appearance on and no host
 * provider, in either pass order (audit IS-I6).
 */
final class BrandBridgePassTest extends TestCase
{
    /**
     * @return iterable<string, array{list<CompilerPassInterface>}>
     */
    public static function orders(): iterable
    {
        yield 'bridge first' => [[new BrandBridgePass(), new BrandAppearanceProviderPass()]];
        yield 'UiKit first' => [[new BrandAppearanceProviderPass(), new BrandBridgePass()]];
    }

    /**
     * @param list<CompilerPassInterface> $passes
     */
    #[DataProvider('orders')]
    public function testTheBridgeWinsInEitherOrder(array $passes): void
    {
        $container = $this->container();
        foreach ($passes as $pass) {
            $pass->process($container);
        }

        self::assertSame(InstanceBrandProvider::class, (string) $container->getAlias('nowo_ui_kit.appearance.provider'));
        self::assertTrue($container->getAlias('nowo_ui_kit.appearance.provider')->isPublic(), 'visibility kept');
    }

    public function testAHostProviderInConfigWins(): void
    {
        $container = $this->container(providerIsDefault: false);
        $container->setAlias('nowo_ui_kit.appearance.provider', 'app.provider');
        (new BrandBridgePass())->process($container);

        self::assertSame('app.provider', (string) $container->getAlias('nowo_ui_kit.appearance.provider'));
    }

    public function testAHostAliasInItsServicesWins(): void
    {
        $container = $this->container();
        $container->setAlias('nowo_ui_kit.appearance.provider', 'app.provider');
        (new BrandBridgePass())->process($container);

        self::assertSame('app.provider', (string) $container->getAlias('nowo_ui_kit.appearance.provider'));
    }

    /**
     * @param list<CompilerPassInterface> $passes
     */
    #[DataProvider('orders')]
    public function testUiKitBrandOffKeepsTheConfigProvider(array $passes): void
    {
        // Audit IU-L1: with `appearance.brand: off` UiKit never registers its brand candidate.
        $container = $this->container(candidate: false);
        foreach ($passes as $pass) {
            $pass->process($container);
        }

        self::assertSame('nowo_ui_kit.appearance.config_provider', (string) $container->getAlias('nowo_ui_kit.appearance.provider'));
    }

    public function testAHostBrandProviderNextToTheBridgeFailsTheBuild(): void
    {
        // Audit IU-I2: two brand sources.
        foreach (['alias' => false, 'definition' => true] as $case => $definition) {
            $container = $this->container();
            if ($definition) {
                $container->removeAlias(BrandProviderInterface::class);
                $container->register(BrandProviderInterface::class, stdClass::class);
            } else {
                $container->setAlias(BrandProviderInterface::class, 'app.brand');
            }
            try {
                (new BrandBridgePass())->process($container);
                self::fail($case);
            } catch (LogicException $e) {
                self::assertStringContainsString('Use one brand source', $e->getMessage(), $case);
            }
        }

        // Without brand.enabled the host provider is the only brand: nothing to refuse.
        $host = $this->container(brand: false);
        $host->setAlias(BrandProviderInterface::class, 'app.brand');
        (new BrandBridgePass())->process($host);
        self::assertSame('app.brand', (string) $host->getAlias(BrandProviderInterface::class));
    }

    public function testNothingChangesWithoutTheConditions(): void
    {
        foreach ([
            'brand off'         => $this->container(brand: false),
            'appearance off'    => $this->container(appearance: false),
            'no parameters'     => new ContainerBuilder(),
            'no bridge service' => $this->container(bridge: false),
        ] as $case => $container) {
            (new BrandBridgePass())->process($container);
            self::assertSame($container->hasAlias('nowo_ui_kit.appearance.provider') ? 'nowo_ui_kit.appearance.config_provider' : null, $container->hasAlias('nowo_ui_kit.appearance.provider') ? (string) $container->getAlias('nowo_ui_kit.appearance.provider') : null, $case);
        }

        $noAlias = $this->container();
        $noAlias->removeAlias('nowo_ui_kit.appearance.provider');
        (new BrandBridgePass())->process($noAlias);
        self::assertFalse($noAlias->hasAlias('nowo_ui_kit.appearance.provider'));
    }

    private function container(bool $brand = true, bool $appearance = true, bool $providerIsDefault = true, bool $bridge = true, bool $candidate = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(BrandBridgePass::PARAMETER, $brand);
        $container->setParameter('nowo_ui_kit.appearance.enabled', $appearance);
        $container->setParameter('nowo_ui_kit.appearance.provider_is_default', $providerIsDefault);
        $container->register('nowo_ui_kit.appearance.config_provider', stdClass::class);
        if ($candidate) {
            $container->register(BrandAppearanceProviderPass::CANDIDATE, stdClass::class);
        }
        $container->setAlias('nowo_ui_kit.appearance.provider', 'nowo_ui_kit.appearance.config_provider')->setPublic(true);
        if ($bridge) {
            $container->register(InstanceBrandProvider::class, stdClass::class);
            $container->setAlias(BrandProviderInterface::class, InstanceBrandProvider::class);
        }

        return $container;
    }
}
