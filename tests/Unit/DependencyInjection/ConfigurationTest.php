<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection;

use Nowo\InstanceSettingsBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testSafeDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame(['table_prefix' => '', 'entity_manager' => null], $config['doctrine']);
        self::assertSame(['enabled' => true, 'path_prefix' => '/admin/settings', 'layout_template' => null, 'templates' => []], $config['web_ui']);
        self::assertSame(['access_roles' => ['ROLE_ADMIN'], 'area_roles' => [], 'access_checker' => null, 'allow_unauthenticated' => false], $config['security']);
        self::assertSame(['web_ui' => true, 'schema' => 'nowo-instance-settings', 'max_upload_bytes' => 1_048_576, 'sealed_secrets' => true], $config['portability']);
    }

    public function testFullConfiguration(): void
    {
        $config = $this->process([
            'doctrine'    => ['table_prefix' => 'app_', 'entity_manager' => 'settings'],
            'web_ui'      => ['path_prefix' => '/ops/settings', 'layout_template' => 'admin.html.twig', 'templates' => ['section' => 'host/section.html.twig']],
            'security'    => ['access_roles' => [], 'access_checker' => 'app.checker', 'area_roles' => ['mailer' => ['ROLE_SUPER_ADMIN']]],
            'portability' => ['web_ui' => false, 'schema' => 'acme-config', 'max_upload_bytes' => 2048, 'sealed_secrets' => false],
        ]);

        self::assertSame('app_', $config['doctrine']['table_prefix']);
        self::assertSame(['section' => 'host/section.html.twig'], $config['web_ui']['templates']);
        self::assertSame(['mailer' => ['ROLE_SUPER_ADMIN']], $config['security']['area_roles']);
        self::assertSame('acme-config', $config['portability']['schema']);
        self::assertSame([], $this->process(['security' => ['access_roles' => [], 'allow_unauthenticated' => true]])['security']['access_roles']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): iterable
    {
        yield 'prefix chars' => [['doctrine' => ['table_prefix' => 'a-b']], 'table_prefix must contain only'];
        yield 'path relative' => [['web_ui' => ['path_prefix' => 'admin']], 'path_prefix must be an absolute path'];
        yield 'path root' => [['web_ui' => ['path_prefix' => '/']], 'path_prefix must be an absolute path'];
        yield 'template page' => [['web_ui' => ['templates' => ['list' => 'x.html.twig']]], 'Unknown nowo_instance_settings.web_ui.templates page(s) "list"'];
        yield 'empty roles' => [['security' => ['access_roles' => ['', ' ']]], 'access_roles must contain at least one non-empty role'];
        yield 'empty area roles' => [['security' => ['area_roles' => ['mailer' => []]]], 'should have at least 1 element'];
        yield 'schema' => [['portability' => ['schema' => 'Bad Schema']], 'portability.schema must match'];
        yield 'upload min' => [['portability' => ['max_upload_bytes' => 10]], 'max_upload_bytes'];
        yield 'unknown key' => [['nope' => true], 'Unrecognized option "nope"'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalid')]
    public function testInvalidConfiguration(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process($config);
    }

    public function testRolesHelper(): void
    {
        self::assertSame(['ROLE_A', 'ROLE_B'], Configuration::roles([' ROLE_A ', '', 3, 'ROLE_B']));
        self::assertSame([], Configuration::roles('ROLE_A'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
