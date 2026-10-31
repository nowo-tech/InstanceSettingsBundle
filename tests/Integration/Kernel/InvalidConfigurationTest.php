<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Kernel;

use LogicException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Fail-closed container checks (REQ-SF-006 / REQ-UI-002 / REQ-SEC-005).
 */
final class InvalidConfigurationTest extends KernelTestCase
{
    public function testUnauthenticatedAccessOnlyInDebug(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('only allowed when kernel.debug is true');
        $this->bootKernel(['security' => ['allow_unauthenticated' => true]]);
    }

    public function testUnauthenticatedAccessInDebugBoots(): void
    {
        $kernel = $this->bootKernel(['security' => ['allow_unauthenticated' => true]], without: ['security'], debug: true);
        $client = $this->client($kernel, null);
        $client->request('GET', '/admin/settings/sections/general');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testUiWithoutSecurityBundleFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');
        $this->bootKernel(without: ['security']);
    }

    public function testDisabledUiNeedsNeitherSecurityNorCsrf(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false]], without: ['security']);
        self::assertSame('noreply@example.test', $this->settings($kernel)->get('mailer.from'));
    }

    public function testMissingDoctrineEncryptFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('register Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle');
        $this->bootKernel(without: ['doctrine_encrypt']);
    }

    public function testEnvPlaceholderSwitchIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a literal boolean');
        $this->bootKernel(['security' => ['allow_unauthenticated' => '%env(bool:SETTINGS_OPEN)%']]);
    }
}
