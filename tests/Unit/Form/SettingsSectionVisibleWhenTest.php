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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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

    public function testHiddenSecretKeepsOnlyTheClearIntent(): void
    {
        $form = $this->form(['provider' => 'smtp']);
        $form->submit(['_token' => 'token-nowo_instance_settings_section', 'provider' => 'smtp', 'smtp_host' => 'h', 'api_key' => ['value' => 'REDACTED-NEW', 'clear' => '1']]);
        self::assertTrue($form->isValid());
        self::assertSame(['value' => null, 'clear' => true], $form->get('api_key')->getData(), 'value dropped, clear kept');

        $form = $this->form(['provider' => 'smtp']);
        $form->submit(['_token' => 'token-nowo_instance_settings_section', 'provider' => 'smtp', 'smtp_host' => 'h', 'api_key' => ['value' => 'REDACTED-NEW', 'clear' => ['x']]]);
        self::assertSame(['value' => null, 'clear' => false], $form->get('api_key')->getData(), 'a non-string clear is dropped');

        $form = $this->form(['provider' => 'smtp'], false);
        $form->submit(['_token' => 'token-nowo_instance_settings_section', 'provider' => 'smtp', 'smtp_host' => 'h', 'api_key' => ['clear' => '1']]);
        self::assertTrue($form->isValid(), 'nothing stored: no clear box, the intent is dropped');
    }

    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function checkboxPosts(): iterable
    {
        yield 'absent' => [null, false];
        yield 'empty' => ['', true];
        yield 'zero' => ['0', true];
        yield 'yes' => ['yes', true];
        yield 'one' => ['1', true];
    }

    #[DataProvider('checkboxPosts')]
    public function testCheckboxControllerIsNormalizedLikeTheForm(mixed $posted, bool $visible): void
    {
        $form = $this->form(['provider' => 'none', 'tracking_domain' => 'stored.example.test']);
        $post = ['_token' => 'token-nowo_instance_settings_section', 'provider' => 'none', 'tracking_domain' => 'new.example.test'];
        if ($posted !== null) {
            $post['tracking'] = $posted;
        }
        $form->submit($post);
        $data = (array) $form->getData();

        self::assertSame($visible, $data['tracking']);
        self::assertSame($visible, $form->getConfig()->getOption('fields')['tracking_domain']->isVisibleFor($data), 'controller view');
        self::assertSame($visible ? 'new.example.test' : 'stored.example.test', $data['tracking_domain'], 'PRE_SUBMIT view');
    }

    public function testTextControllerIsTrimmedLikeTheForm(): void
    {
        $form = $this->form([]);
        self::assertSame('smtp', SettingsSectionType::submittedValue($form->get('label'), '  smtp '));
        self::assertSame(['x'], SettingsSectionType::submittedValue($form->get('label'), ['x']));
        self::assertSame('smtp', SettingsSectionType::submittedValue($form->get('provider'), 'smtp'));
    }

    public function testIntChoiceAndCheckboxControllersUseTheTypeTransformers(): void
    {
        $form = $this->form([]);
        self::assertSame(1, SettingsSectionType::submittedValue($form->get('smtp_port'), '01'));
        self::assertSame(25, SettingsSectionType::submittedValue($form->get('smtp_port'), ' 25 '));
        self::assertSame('abc', SettingsSectionType::submittedValue($form->get('smtp_port'), 'abc'), 'untransformable: raw');
        self::assertSame('nope', SettingsSectionType::submittedValue($form->get('provider'), 'nope'), 'invalid choice: raw');
        self::assertFalse(SettingsSectionType::submittedValue($form->get('tracking'), null));
        self::assertTrue(SettingsSectionType::submittedValue($form->get('tracking'), '0'));

        $int = FormFactorySupport::factory()->createNamed('settings_section', SettingsSectionType::class, [], [
            'section' => new ProviderFixtureSection(),
            'fields'  => ['level' => SettingField::choice('level', ['one' => 1, 'two' => 2]), 'x' => SettingField::string('x')->withVisibleWhen('level', [1])],
        ]);
        self::assertSame(2, SettingsSectionType::submittedValue($int->get('level'), '2'));

        $modelled = FormFactorySupport::factory()->createNamedBuilder('code', TextType::class)
            ->addModelTransformer(new CallbackTransformer(static fn (mixed $v): mixed => $v, static fn (mixed $v): string => 'model:' . $v))
            ->getForm();
        self::assertSame('model:smtp', SettingsSectionType::submittedValue($modelled, ' smtp '), 'model transformers too');
    }

    public function testEmptySubmissionTakesEmptyData(): void
    {
        $fields = [
            'provider' => SettingField::choice('provider', ['a' => 'smtp', 'b' => 'api'])->withFormOptions(['empty_data' => 'smtp']),
            'name'     => SettingField::string('name')->withFormOptions(['empty_data' => 'fallback']),
            'flag'     => SettingField::bool('flag'),
            'host'     => SettingField::string('host')->withVisibleWhen('provider', ['smtp']),
        ];
        $form = FormFactorySupport::factory()->createNamed('settings_section', SettingsSectionType::class, [], ['section' => new ProviderFixtureSection(), 'fields' => $fields]);

        self::assertSame('smtp', SettingsSectionType::submittedValue($form->get('provider'), ''));
        self::assertSame('smtp', SettingsSectionType::submittedValue($form->get('provider'), null));
        self::assertSame('fallback', SettingsSectionType::submittedValue($form->get('name'), '  '));
        self::assertFalse(SettingsSectionType::submittedValue($form->get('flag'), null), 'checkbox empty_data keeps it unchecked');

        $form->submit(['_token' => 'token-nowo_instance_settings_section', 'provider' => '', 'host' => 'h.example.test']);
        self::assertSame('smtp', $form->getData()['provider']);
        self::assertSame('h.example.test', $form->getData()['host'], 'empty provider resolves to empty_data smtp: host visible');
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
    private function form(array $data, bool $storedKey = true): FormInterface
    {
        $registry = new SettingsSectionRegistry([new ProviderFixtureSection()]);

        return FormFactorySupport::factory()->createNamed('settings_section', SettingsSectionType::class, $data, [
            'section'        => $registry->get('transport'),
            'fields'         => $registry->fields('transport'),
            'stored_secrets' => ['api_key' => $storedKey],
        ]);
    }
}
