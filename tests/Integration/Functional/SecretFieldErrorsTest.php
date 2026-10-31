<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\SecretConstraintSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;

/**
 * A refused secret value shows its violation next to the secret field (MailKit report).
 */
final class SecretFieldErrorsTest extends KernelTestCase
{
    public function testSecretViolationIsRenderedNextToTheField(): void
    {
        $kernel  = $this->bootKernel(services: ['transport_section' => SecretConstraintSection::class]);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/sections/transport');
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), ['settings_section[dsn][value]' => 'http://not-a-dsn']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('#<li id="settings_section_dsn_error1">Use an smtp:// or smtps:// DSN\.</li>#', $html, 'the violation renders on the secret field row');
        self::assertStringNotContainsString('not-a-dsn', $html, 'the refused secret is not rendered back');
        self::assertNull($this->settings($kernel)->getSecret('transport.dsn'));
    }
}
