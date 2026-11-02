<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Form;

use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ProviderFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\FormFactorySupport;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormInterface;

/**
 * 1.3 provider-dependent rows: UiKit `visible-when` row attributes and hidden fields kept on submit.
 */
final class SettingsSectionVisibleWhenTest extends TestCase
{
    public function testRowsCarryTheVisibleWhenAttributes(): void
    {
        $form = $this->form(['provider' => 'smtp', 'smtp_host' => 'mail.example.test', 'smtp_port' => 25]);
        $view = $form->createView();

        self::assertSame([
            'data-nowo-ui-visible-when-field' => 'settings_section[provider]',
            'data-nowo-ui-visible-when-in'    => '["smtp"]',
        ], $view['smtp_host']->vars['row_attr']);
        self::assertSame('["api"]', $view['api_key']->vars['row_attr']['data-nowo-ui-visible-when-in'], 'secret rows too');
        self::assertSame('settings_section[tracking]', $view['tracking_domain']->vars['row_attr']['data-nowo-ui-visible-when-field']);
        self::assertSame('["1"]', $view['tracking_domain']->vars['row_attr']['data-nowo-ui-visible-when-in']);
        self::assertArrayNotHasKey('data-nowo-ui-visible-when-field', $view['label']->vars['row_attr']);
        self::assertTrue(SettingsPageFactory::hasVisibleWhenRows($view));

        $registry = new SettingsSectionRegistry([new GeneralFixtureSection()]);
        $plain    = FormFactorySupport::factory()->createNamed('settings_section', SettingsSectionType::class, [], ['section' => $registry->get('general'), 'fields' => $registry->fields('general')]);
        self::assertFalse(SettingsPageFactory::hasVisibleWhenRows($plain->createView()));
    }

    public function testUnnamedFormUsesTheBareFieldName(): void
    {
        $registry = new SettingsSectionRegistry([new ProviderFixtureSection()]);
        $form     = FormFactorySupport::factory()->createNamed('', SettingsSectionType::class, [], ['section' => $registry->get('transport'), 'fields' => $registry->fields('transport'), 'csrf_protection' => false]);
        self::assertSame('provider', $form->createView()['smtp_host']->vars['row_attr']['data-nowo-ui-visible-when-field']);
    }

    public function testHostRowAttrIsMerged(): void
    {
        $field = SettingField::string('host')->withVisibleWhen('provider', ['smtp'])->withFormOptions(['row_attr' => ['class' => 'host-row']]);
        $form  = FormFactorySupport::factory()->createNamed('settings_section', SettingsSectionType::class, [], [
            'section' => new ProviderFixtureSection(),
            'fields'  => ['provider' => SettingField::choice('provider', ['a' => 'smtp']), 'host' => $field],
        ]);
        $attr = $form->createView()['host']->vars['row_attr'];
        self::assertSame('host-row', $attr['class'] ?? null);
        self::assertSame('settings_section[provider]', $attr['data-nowo-ui-visible-when-field']);
    }

    public function testHiddenFieldsKeepTheirValueOnSubmit(): void
    {
        $form = $this->form(['provider' => 'smtp', 'smtp_host' => 'mail.example.test', 'smtp_port' => 25, 'api_region' => 'eu']);
        $form->submit([
            '_token'     => 'token-nowo_instance_settings_section',
            'provider'   => 'api',
            'label'      => 'x',
            'smtp_host'  => '',
            'smtp_port'  => 'not a number',
            'api_region' => 'us',
            'api_key'    => ['value' => 'k-123456'],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertSame('mail.example.test', $data['smtp_host'], 'hidden for api: kept');
        self::assertSame(25, $data['smtp_port'], 'hidden for api: an invalid value is not even transformed');
        self::assertSame('us', $data['api_region'], 'visible for api');
        self::assertNull($data['tracking_domain'] ?? null);
    }

    public function testKeepHiddenFieldsIgnoresNonArrayDataAndMissingChildren(): void
    {
        $form  = $this->form([]);
        $event = new FormEvent($form, null);
        SettingsSectionType::keepHiddenFields($event, [SettingField::string('ghost')->withVisibleWhen('provider', ['smtp'])]);
        self::assertNull($event->getData());

        $event = new FormEvent($form, ['provider' => 'api', 'ghost' => 'x']);
        SettingsSectionType::keepHiddenFields($event, [SettingField::string('ghost')->withVisibleWhen('provider', ['smtp'])]);
        self::assertSame(['provider' => 'api', 'ghost' => 'x'], $event->getData());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return FormInterface<mixed>
     */
    private function form(array $data): FormInterface
    {
        $registry = new SettingsSectionRegistry([new ProviderFixtureSection()]);

        return FormFactorySupport::factory()->createNamed('settings_section', SettingsSectionType::class, $data, [
            'section' => $registry->get('transport'),
            'fields'  => $registry->fields('transport'),
        ]);
    }
}
