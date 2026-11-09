<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Embed;

use Nowo\InstanceSettingsBundle\Embed\SectionEmbedOptions;
use Nowo\InstanceSettingsBundle\Embed\SettingsSectionEmbedder;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\PageFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * IS-1 (1.6): the sections a host may list in its own menu are the allowlisted ones the user may open, and none when
 * the UI gate denies.
 */
final class SettingsSectionEmbedderTest extends TestCase
{
    public function testAccessibleSectionsNarrowAndRespectTheUiGate(): void
    {
        $options = SectionEmbedOptions::routes(['general' => 'host_general', 'mailer' => 'host_mailer', 'unknown' => 'host_unknown']);

        $all = $this->embedder(new AllowAllInstanceSettingsAccessChecker());
        self::assertSame(['mailer', 'general'], array_keys($all->accessibleSections()));
        self::assertSame(['mailer', 'general'], array_keys($all->accessibleSections($options)), 'registry order, unknown names ignored');
        self::assertSame(['general'], array_keys($all->accessibleSections(SectionEmbedOptions::routes(['general' => 'host_general']))));

        $noUi = $this->embedder(new class implements InstanceSettingsAccessCheckerInterface {
            public function canAccess(?object $user, ?string $area = null): bool
            {
                return $area !== null;
            }
        });
        self::assertSame([], $noUi->accessibleSections($options), 'the UI gate denies: nothing to list');
        self::assertCount(2, $noUi->accessibleSections(), 'the bundle page relies on the access listener for the UI gate');
    }

    private function embedder(InstanceSettingsAccessCheckerInterface $checker): SettingsSectionEmbedder
    {
        $registry = PageFixtures::registry();

        return new SettingsSectionEmbedder(
            $registry,
            new InstanceSettings(new InMemorySettingValueRepository(), $registry),
            new AccessGuard($checker),
            PageFixtures::factory(),
            Forms::createFormFactory(),
            new Environment(new ArrayLoader()),
            new UrlGenerator(new RouteCollection(), new RequestContext()),
        );
    }
}
