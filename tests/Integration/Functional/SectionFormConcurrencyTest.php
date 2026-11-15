<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\DomCrawler\Form;

/**
 * 1.19 (Beacon spec 239): the section form carries the section as its editor opened it (`_base`), so a save keeps what
 * another editor saved meanwhile and refuses (409) a field both changed. A form posted without the token (a host template
 * that does not render it) saves as before 1.19.
 */
final class SectionFormConcurrencyTest extends KernelTestCase
{
    public function testAnUntouchedFieldKeepsTheOtherEditorsValue(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $form   = $this->form($client->request('GET', '/admin/settings/sections/general')->filter('form[name="settings_section"]')->form());

        $this->settings($kernel)->save('general', ['page_size' => 50]);
        $client->submit($form, ['settings_section[site_name]' => 'Mine']);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertSame(50, $this->settings($kernel)->getInt('general.page_size'));
        self::assertSame('Mine', $this->settings($kernel)->getString('general.site_name'));
    }

    public function testAFieldBothEditorsChangedIsRefusedWith409(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $form   = $this->form($client->request('GET', '/admin/settings/sections/general')->filter('form[name="settings_section"]')->form());

        $this->settings($kernel)->save('general', ['site_name' => 'Theirs']);
        $client->submit($form, ['settings_section[site_name]' => 'Mine']);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('changed this setting after you opened the page', (string) $client->getResponse()->getContent());
        $this->settings($kernel)->reset();
        self::assertSame('Theirs', $this->settings($kernel)->getString('general.site_name'));
    }

    public function testAFormWithoutTheTokenSavesAsBefore(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $form   = $client->request('GET', '/admin/settings/sections/general')->filter('form[name="settings_section"]')->form();
        $form->remove('settings_section[_base]');

        $this->settings($kernel)->save('general', ['page_size' => 50]);
        $client->submit($form, ['settings_section[site_name]' => 'Mine']);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertSame(20, $this->settings($kernel)->getInt('general.page_size'), 'without the token the form value is written, as before 1.19');
    }

    private function form(Form $form): Form
    {
        self::assertTrue($form->has('settings_section[_base]'), 'the token is rendered');

        return $form;
    }
}
