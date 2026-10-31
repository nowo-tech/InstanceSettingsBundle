<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Form;

use Nowo\DoctrineEncryptBundle\Form\Type\SecretType;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\InstanceSettingsBundle\Form\PortabilityExportType;
use Nowo\InstanceSettingsBundle\Form\PortabilityImportType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\FormFactorySupport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

final class FormTypesTest extends TestCase
{
    public function testEveryPageFormIsAFormKitType(): void
    {
        foreach ([SettingsSectionType::class, PortabilityImportType::class, PortabilityExportType::class] as $class) {
            $attributes = (new ReflectionClass($class))->getAttributes(FormKitConfig::class);
            self::assertCount(1, $attributes, $class);
            self::assertSame(NowoInstanceSettingsBundle::FORM_KIT_PROFILE, $attributes[0]->getArguments()[0]);
        }
    }

    public function testSectionFormBuildsOneFieldPerSetting(): void
    {
        $registry = new SettingsSectionRegistry([new MailerFixtureSection(), new GeneralFixtureSection()]);
        $general  = FormFactorySupport::factory()->create(SettingsSectionType::class, ['site_name' => 'Acme'], [
            'section' => $registry->get('general'),
            'fields'  => $registry->fields('general'),
        ]);

        $types = [];
        foreach ($general as $name => $child) {
            $types[$name] = $child->getConfig()->getType()->getInnerType()::class;
        }
        self::assertSame([
            'site_name'        => TextType::class,
            'footer'           => TextareaType::class,
            'homepage'         => UrlType::class,
            'page_size'        => IntegerType::class,
            'ratio'            => NumberType::class,
            'mode'             => ChoiceType::class,
            'maintenance_lock' => CheckboxType::class,
        ], $types);
        self::assertSame('app', $general->get('mode')->getConfig()->getOption('choice_translation_domain'));
        self::assertTrue($general->get('site_name')->getConfig()->getOption('required'));
        self::assertSame('nowo_instance_settings_section', $general->getConfig()->getOption('csrf_token_id'));

        $mailer = FormFactorySupport::factory()->create(SettingsSectionType::class, [], [
            'section'        => $registry->get('mailer'),
            'fields'         => $registry->fields('mailer'),
            'stored_secrets' => ['dsn' => true],
        ]);
        $dsn = $mailer->get('dsn')->getConfig();
        self::assertInstanceOf(SecretType::class, $dsn->getType()->getInnerType());
        self::assertSame('stored', $dsn->getOption('current_value'), 'only the presence of the secret reaches the form');
        self::assertSame(0, $dsn->getOption('hint_visible_last'));
        self::assertTrue($mailer->get('dsn')->has('clear'));

        $empty = FormFactorySupport::factory()->create(SettingsSectionType::class, [], ['section' => $registry->get('mailer'), 'fields' => $registry->fields('mailer')]);
        self::assertNull($empty->get('dsn')->getConfig()->getOption('current_value'));
        self::assertFalse($empty->get('dsn')->has('clear'));
    }

    public function testPortabilityForms(): void
    {
        $import = FormFactorySupport::factory()->create(PortabilityImportType::class, null, ['max_bytes' => 2048]);
        self::assertInstanceOf(FileType::class, $import->get('file')->getConfig()->getType()->getInnerType());
        self::assertTrue($import->has('dry_run'));
        self::assertSame(PortabilityImportType::CSRF_TOKEN_ID, $import->getConfig()->getOption('csrf_token_id'));

        self::assertFalse(FormFactorySupport::factory()->create(PortabilityExportType::class)->has('include_secrets'));
        self::assertTrue(FormFactorySupport::factory()->create(PortabilityExportType::class, null, ['allow_secrets' => true])->has('include_secrets'));
    }
}
