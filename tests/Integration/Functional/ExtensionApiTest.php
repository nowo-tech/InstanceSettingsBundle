<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ActionsFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Extension API used by kits (SmsKit / MailKit): public route names, section actions with context, typed field
 * constraints, the access checker available with the admin UI off.
 */
final class ExtensionApiTest extends KernelTestCase
{
    public function testSectionActionsReceiveTheirContextAndRouteNamesArePublic(): void
    {
        $kernel = $this->bootKernel(services: ['sms_section' => ActionsFixtureSection::class]);
        $router = $kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertSame('/admin/settings/sections/sms', $router->generate(InstanceSettingsRoutes::SECTION, ['section' => 'sms']));
        foreach ([InstanceSettingsRoutes::INDEX, InstanceSettingsRoutes::PORTABILITY, InstanceSettingsRoutes::EXPORT, InstanceSettingsRoutes::IMPORT] as $route) {
            self::assertNotNull($router->getRouteCollection()->get($route));
        }

        $client = $this->client($kernel);
        $client->request('GET', '/admin/settings/sections/sms');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Test as admin', $html);
        self::assertStringContainsString('section=sms&amp;from=/admin/settings/sections/sms', $html);

        $crawler = $client->request('GET', '/admin/settings/sections/sms');
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), ['settings_section[bridge_url]' => 'http://insecure.example.test']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('HTTPS only.', (string) $client->getResponse()->getContent());
    }

    public function testAccessCheckerIsAvailableWithTheUiOff(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false]]);
        self::assertTrue($this->container($kernel)->has(InstanceSettingsAccessCheckerInterface::class));
        $guard = $this->container($kernel)->get(AccessGuard::class);
        self::assertInstanceOf(AccessGuard::class, $guard);
        self::assertFalse($guard->isGranted('mailer'), 'no authenticated user outside a request');
    }
}
