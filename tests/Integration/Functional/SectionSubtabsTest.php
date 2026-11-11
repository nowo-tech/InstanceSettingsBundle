<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\EmbedHostController;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GroupedFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function file_put_contents;
use function in_array;
use function is_dir;
use function mkdir;
use function preg_match;
use function str_repeat;
use function substr_count;
use function sys_get_temp_dir;
use function tempnam;

/**
 * 1.14: `web_ui.section_layout: tabs` shows the field groups of a section as UiKit subtabs: one form (Save saves every
 * group, write-only secrets left blank keep their value), every panel visible until UiKit's tabs runtime upgrades the
 * root (no-JS), the open tab in `?group=` (kept by the redirect after a save), and the first tab with an error open
 * after a failed save, with an error badge. The embed supports it too; the default stacked layout is unchanged.
 */
final class SectionSubtabsTest extends KernelTestCase
{
    private const BRAND_URL = '/admin/settings/sections/brand';

    public function testTheBrandSectionRendersItsGroupsAsAccessibleSubtabs(): void
    {
        $admin   = $this->client($this->brandKernel('tabs'));
        $crawler = $admin->request('GET', self::BRAND_URL);
        self::assertSame(200, $admin->getResponse()->getStatusCode());

        $root = $crawler->filter('[data-nowo-instance-settings-tabs]');
        self::assertCount(1, $root);
        self::assertSame('nowo-instance-settings-brand-groups', $root->attr('id'));
        self::assertNotNull($root->attr('data-nowo-ui-tabs'));
        self::assertNotNull($root->attr('data-nowo-ui-tabs-upgrade'), 'progressive: stacked without JS');
        self::assertSame('nowo-instance-settings-brand-groups-identity', $root->attr('data-nowo-ui-tabs-active'));

        $list = $root->filter('[role="tablist"]');
        self::assertNotNull($list->attr('hidden'), 'the tab list waits for the runtime');
        self::assertSame('Setting groups', $list->attr('aria-label'));
        $tabs = $list->filter('[role="tab"]');
        self::assertSame(['identity', 'logos', 'extra_images'], $tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('data-nowo-instance-settings-group-tab')), 'Advanced holds no field: not shown');
        self::assertSame(['Identity', 'Logos', 'Extra images'], $tabs->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame(['true', 'false', 'false'], $tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('aria-selected')));
        self::assertSame(['0', '-1', '-1'], $tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('tabindex')));

        $panels = $root->filter('[data-nowo-ui-tabs-panel]');
        self::assertCount(3, $panels);
        foreach ($tabs as $i => $tab) {
            $tab   = new Crawler($tab);
            $panel = $panels->eq($i);
            self::assertSame($tab->attr('aria-controls'), $panel->attr('id'));
            self::assertSame($tab->attr('data-nowo-ui-tab-id'), $panel->attr('data-nowo-ui-tab-id'));
            self::assertNull($panel->attr('hidden'), 'every panel is visible without JS');
            self::assertSame($tab->text(), $panel->filter('legend')->text());
        }
        self::assertSame(['name', 'short_name', 'eyebrow', 'wordmark'], $this->fieldsOf($panels->eq(0)));
        self::assertSame(['logo_light', 'logo_dark', 'mark', 'app_icon', 'favicon'], $this->fieldsOf($panels->eq(1)));
        self::assertSame(['extra_mascot'], $this->fieldsOf($panels->eq(2)));

        // One form, one Save, the URL script once (UiKit's tabs runtime comes with the layout's default scripts).
        self::assertCount(1, $crawler->filter('form'));
        self::assertCount(1, $crawler->filter('button[type="submit"]'));
        self::assertCount(1, $crawler->filter('script[src$="/bundles/nowoinstancesettings/js/nowo-instance-settings-tabs.js"]'));
        self::assertCount(1, $crawler->filter('script[src$="/bundles/nowouikit/js/nowo-ui-tabs.js"]'));
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-group-errors"]'));
    }

    public function testTheGroupQueryOpensItsTab(): void
    {
        $admin = $this->client($this->brandKernel('tabs'));
        foreach (['?group=logos' => 'logos', '?group=advanced' => 'identity', '?group=nope' => 'identity', '?group[]=logos' => 'identity'] as $query => $active) {
            $crawler = $admin->request('GET', self::BRAND_URL . $query);
            self::assertSame(200, $admin->getResponse()->getStatusCode(), $query);
            self::assertSame('nowo-instance-settings-brand-groups-' . $active, $crawler->filter('[data-nowo-instance-settings-tabs]')->attr('data-nowo-ui-tabs-active'), $query);
            self::assertSame('true', $crawler->filter('[data-nowo-instance-settings-group-tab="' . $active . '"]')->attr('aria-selected'), $query);
        }
    }

    public function testSaveSavesEveryGroupAndComesBackToTheOpenTab(): void
    {
        $kernel  = $this->brandKernel('tabs');
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', self::BRAND_URL . '?group=logos');

        // The form has no action: it posts to the page URL (the URL script keeps ?group= in it).
        self::assertNull($crawler->filter('form[name="settings_section"]')->attr('action'));
        $form = $crawler->filter('form[name="settings_section"]')->form();
        $form->setValues(['settings_section[name]' => 'Grouped Acme']);
        self::upload($form, 'settings_section[logo_dark][file]', $this->png());
        self::upload($form, 'settings_section[extra_mascot][file]', $this->png());
        $admin->submit($form);

        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertSame(self::BRAND_URL . '?group=logos', $admin->getResponse()->headers->get('Location'));
        self::assertSame('Grouped Acme', $this->settings($kernel)->getString('brand.name'));
        self::assertNotNull($this->settings($kernel)->getFile('brand.logo_dark'));
        self::assertNotNull($this->settings($kernel)->getFile('brand.extra_mascot'));

        // Without ?group= the redirect has none.
        $crawler = $admin->request('GET', self::BRAND_URL);
        $admin->submit($crawler->filter('form[name="settings_section"]')->form());
        self::assertSame(self::BRAND_URL, $admin->getResponse()->headers->get('Location'));
    }

    public function testAFailedSaveOpensTheFirstTabWithAnErrorAndMarksIt(): void
    {
        $kernel  = $this->brandKernel('tabs');
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', self::BRAND_URL . '?group=extra_images');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        $form->setValues(['settings_section[name]' => str_repeat('x', 81)]);
        $form->setValues(['settings_section[wordmark]' => str_repeat('y', 41)]);
        $crawler = $admin->submit($form);

        self::assertSame(422, $admin->getResponse()->getStatusCode());
        self::assertSame('Acme', $this->settings($kernel)->getString('brand.name'), 'nothing saved');
        $root = $crawler->filter('[data-nowo-instance-settings-tabs]');
        self::assertSame('nowo-instance-settings-brand-groups-identity', $root->attr('data-nowo-ui-tabs-active'));
        $identity = $crawler->filter('[data-nowo-instance-settings-group-tab="identity"]');
        self::assertNotNull($identity->attr('data-invalid'));
        self::assertSame('2', $identity->filter('[data-testid="instance-settings-group-errors"]')->text());
        self::assertStringContainsString('2 field(s) with errors', $identity->text());
        self::assertNull($crawler->filter('[data-nowo-instance-settings-group-tab="logos"]')->attr('data-invalid'));
        self::assertNotNull($crawler->filter('[data-nowo-instance-settings-group-panel="identity"]')->attr('data-invalid'));
        self::assertCount(2, $crawler->filter('[data-testid="instance-settings-group-errors"]'), 'tab and legend of the identity group');
    }

    public function testTheAppearanceSectionHasItsFourGroups(): void
    {
        $admin   = $this->client($this->brandKernel('tabs'));
        $crawler = $admin->request('GET', '/admin/settings/sections/appearance');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertSame(['Theme', 'Light colours', 'Dark colours', 'Shape'], $crawler->filter('[role="tab"]')->each(static fn (Crawler $tab): string => $tab->text()));
        $panels = $crawler->filter('[data-nowo-ui-tabs-panel]');
        self::assertSame(['preset', 'preset_dark'], $this->fieldsOf($panels->eq(0)));
        self::assertSame(['corner_style', 'border_strength', 'footer_fixed'], $this->fieldsOf($panels->eq(3)));
        self::assertSame(7, substr_count($panels->eq(1)->html(), 'data-nowo-instance-settings-color-hex'));
    }

    public function testTheStackedLayoutIsTheDefaultAndIgnoresTheGroupQuery(): void
    {
        $admin   = $this->client($this->brandKernel(null));
        $crawler = $admin->request('GET', self::BRAND_URL . '?group=logos');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-tabs], [data-nowo-ui-tabs-panel], fieldset'));
        self::assertCount(0, $crawler->filter('script[src$="nowo-instance-settings-tabs.js"]'));

        $admin->submit($crawler->filter('form[name="settings_section"]')->form());
        self::assertSame(self::BRAND_URL, $admin->getResponse()->headers->get('Location'));
    }

    public function testAnEmbedShowsHostGroupsAndKeepsWriteOnlySecrets(): void
    {
        $kernel = $this->bootKernel(
            ['files' => ['enabled' => true]],
            [GroupedFixtureSection::class => GroupedFixtureSection::class, EmbedHostController::class => EmbedHostController::class],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->add('host_grouped', '/host/grouped')->controller([EmbedHostController::class, 'grouped'])->methods(['GET', 'POST']);
            },
        );
        $this->settings($kernel)->save('grouped', ['api_key' => 'REDACTED-TEST-key', 'title' => 'Before']);
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/host/grouped?tenant=acme&group=credentials');
        self::assertSame(200, $admin->getResponse()->getStatusCode());

        self::assertSame(['Basics', 'Credentials', 'Miscellaneous'], $crawler->filter('[role="tab"]')->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame('nowo-instance-settings-grouped-groups-credentials', $crawler->filter('[data-nowo-instance-settings-tabs]')->attr('data-nowo-ui-tabs-active'));
        $panels = $crawler->filter('[data-nowo-ui-tabs-panel]');
        self::assertSame(['title', 'notes'], $this->fieldsOf($panels->eq(0)));
        self::assertSame(['api_key', 'endpoint'], $this->fieldsOf($panels->eq(1)));
        self::assertSame(['banner'], $this->fieldsOf($panels->eq(2)));
        self::assertStringNotContainsString('REDACTED-TEST-key', (string) $admin->getResponse()->getContent());

        // The secret's panel is not the one changed; left blank, the stored secret is kept.
        $form = $crawler->filter('form[name="settings_section"]')->form();
        $form->setValues(['settings_section[title]' => 'After']);
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertSame('/host/grouped?tenant=acme&group=credentials', $admin->getResponse()->headers->get('Location'));
        self::assertSame('After', $this->settings($kernel)->getString('grouped.title'));
        self::assertSame('REDACTED-TEST-key', $this->settings($kernel)->getSecret('grouped.api_key'));

        $crawler = $admin->request('GET', '/host/grouped?tenant=acme');
        $admin->submit($crawler->filter('form[name="settings_section"]')->form());
        self::assertSame('/host/grouped?tenant=acme', $admin->getResponse()->headers->get('Location'));
    }

    /**
     * Field names of the rows in a panel, in order.
     *
     * @return list<string>
     */
    private function fieldsOf(Crawler $panel): array
    {
        $fields = [];
        foreach ($panel->filter('[name^="settings_section["]')->each(static fn (Crawler $input): string => (string) $input->attr('name')) as $name) {
            if (preg_match('/^settings_section\[([a-z0-9_]+)\]/', $name, $match) === 1 && !in_array($match[1], $fields, true)) {
                $fields[] = $match[1];
            }
        }

        return $fields;
    }

    private function brandKernel(?string $layout): InstanceSettingsTestKernel
    {
        $public = InstanceSettingsTestKernel::baseDir() . '/public-' . $this->name();
        if (!is_dir($public . '/brand')) {
            mkdir($public . '/brand', 0o777, true);
        }
        file_put_contents($public . '/brand/mascot.png', ImageFactory::png(64, 64));

        return $this->bootKernel(($layout === null ? [] : ['web_ui' => ['section_layout' => $layout]]) + ['brand' => [
            'enabled'      => true,
            'public_dir'   => $public,
            'defaults'     => ['name' => 'Acme'],
            'extra_images' => ['mascot' => ['label' => 'Mascot', 'max_width' => 512, 'max_height' => 512, 'square' => true, 'default' => 'brand/mascot.png']],
        ]], fixtures: false);
    }

    private function png(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'is-tabs');
        file_put_contents($path, ImageFactory::png(128, 128));

        return $path;
    }
}
