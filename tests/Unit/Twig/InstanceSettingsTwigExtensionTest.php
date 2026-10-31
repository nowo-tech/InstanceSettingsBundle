<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Twig;

use LogicException;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Twig\InstanceSettingsTwigExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;

final class InstanceSettingsTwigExtensionTest extends TestCase
{
    public function testFunctionPrintsPlainValuesAndRefusesSecrets(): void
    {
        $settings = new InstanceSettings(new InMemorySettingValueRepository(), new SettingsSectionRegistry([new MailerFixtureSection()]));
        $settings->save('mailer', ['dsn' => 'smtp://REDACTED-TEST']);
        $twig = new Environment(new ArrayLoader([
            'ok'     => "{{ instance_setting('mailer.from') }}",
            'secret' => "{{ instance_setting('mailer.dsn') }}",
        ]));
        $twig->addExtension(new InstanceSettingsTwigExtension($settings));

        self::assertSame('noreply@example.test', $twig->render('ok'));

        try {
            $twig->render('secret');
            self::fail('Expected a refusal');
        } catch (RuntimeError $e) {
            self::assertInstanceOf(LogicException::class, $e->getPrevious());
        }
    }
}
