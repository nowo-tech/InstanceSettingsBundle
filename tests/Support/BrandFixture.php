<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Generator;
use Nowo\InstanceSettingsBundle\Brand\AppearanceSection;
use Nowo\InstanceSettingsBundle\Brand\BrandDefaults;
use Nowo\InstanceSettingsBundle\Brand\BrandFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Brand\BrandPalettes;
use Nowo\InstanceSettingsBundle\Brand\BrandSection;
use Nowo\InstanceSettingsBundle\Brand\InstanceBrandProvider;
use Nowo\InstanceSettingsBundle\File\SettingFileUrls;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The brand bridge wired by hand on in-memory storage (unit tests): the two sections, the store, the provider.
 */
final class BrandFixture
{
    public readonly InMemorySettingValueRepository $repository;

    public readonly InMemorySettingFileStore $store;

    public readonly InstanceSettings $settings;

    public readonly InstanceBrandProvider $provider;

    public readonly BrandSection $brandSection;

    public readonly AppearanceSection $appearanceSection;

    public function __construct(
        public readonly BrandDefaults $defaults = new BrandDefaults('Acme'),
        public readonly BrandPalettes $palettes = new BrandPalettes(),
        ?TranslatorInterface $translator = null,
        ?LoggerInterface $logger = null,
        ?BrandFallbackProviderInterface $fallbacks = null,
        ?SettingValueRepositoryInterface $repository = null,
        bool $router = true,
    ) {
        $this->repository = new InMemorySettingValueRepository();
        $this->store      = new InMemorySettingFileStore();
        $routes           = new RouteCollection();
        $routes->add(InstanceSettingsRoutes::FILE, new Route('/_settings-file/{section}/{field}/{file}.{ext}'));

        $this->brandSection = new BrandSection($this->defaults, $fallbacks);
        $list               = [];
        $sections           = (static function () use (&$list): Generator {
            yield from $list;
        })();
        // The registry iterates lazily (as the container's tagged iterator), so the appearance section can depend on
        // the provider that depends on the settings.
        $this->settings = new InstanceSettings(
            $repository ?? $this->repository,
            new SettingsSectionRegistry($sections),
            new ValueNormalizer(Validation::createValidator()),
            null,
            null,
            null,
            null,
            $this->store,
            fileUrls: new SettingFileUrls($router ? new UrlGenerator($routes, new RequestContext()) : null),
        );
        $this->provider          = new InstanceBrandProvider($this->settings, $this->defaults, $this->palettes, $translator, $logger);
        $this->appearanceSection = new AppearanceSection($this->palettes, $this->provider, $fallbacks);
        $list                    = [$this->brandSection, $this->appearanceSection];
    }

    /**
     * @return list<SettingsSectionInterface>
     */
    public function sections(): array
    {
        return [$this->brandSection, $this->appearanceSection];
    }
}
