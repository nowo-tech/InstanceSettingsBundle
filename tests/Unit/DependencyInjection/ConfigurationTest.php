<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection;

use Nowo\InstanceSettingsBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

use function json_encode;

final class ConfigurationTest extends TestCase
{
    public function testSafeDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame(['table_prefix' => '', 'entity_manager' => null], $config['doctrine']);
        self::assertSame(['enabled' => true, 'path_prefix' => '/admin/settings', 'layout_template' => null, 'hidden_secrets' => 'drop', 'hidden_secrets_notice' => false, 'unlayered_styles' => false, 'section_layout' => 'stacked', 'section_group_parameter' => 'settings_group', 'templates' => [], 'section_icons' => [], 'navigation_hidden' => [], 'reveal_on_toggle' => false, 'reveal_toggle_only' => false], $config['web_ui']);
        self::assertSame(['access_roles' => ['ROLE_ADMIN'], 'area_roles' => [], 'reveal_roles' => ['ROLE_DEVELOPER'], 'reveal_rate_limiter' => null, 'access_checker' => null, 'allow_unauthenticated' => false], $config['security']);
        self::assertSame(['web_ui' => true, 'schema' => 'nowo-instance-settings', 'max_upload_bytes' => 1_048_576, 'sealed_secrets' => true, 'max_file_bytes' => 8_388_608], $config['portability']);
        self::assertSame(['enabled' => false, 'path_prefix' => '/_settings-file', 'orphan_retention' => 30, 'store' => 'doctrine', 'directory' => '%kernel.project_dir%/config/instance-files', 'crop' => ['enabled' => false, 'shape' => null, 'quality' => null, 'labels' => []]], $config['files']);
        self::assertSame(['file' => null, 'base_dir' => '%kernel.project_dir%'], $config['seed']);
    }

    public function testSectionIconsAndHiddenNavigation(): void
    {
        // 1.16: Beacon's use: brand and appearance embedded elsewhere, hidden from the bundle tab bar.
        $config = $this->process(['web_ui' => [
            'section_icons'     => ['brand' => 'tabler:palette', 'mailer' => 'config', 'sms_bridge' => 'bi:chat-dots', 'other' => 'filter_off'],
            'navigation_hidden' => ['brand', 'appearance'],
        ]])['web_ui'];
        self::assertSame(['brand' => 'tabler:palette', 'mailer' => 'config', 'sms_bridge' => 'bi:chat-dots', 'other' => 'filter_off'], $config['section_icons']);
        self::assertSame(['brand', 'appearance'], $config['navigation_hidden']);

        $invalid = [
            ['section_icons' => ['brand' => '']],
            ['section_icons' => ['brand' => 'Tabler:Palette']],
            ['section_icons' => ['brand' => 'ti ti-palette']],
            ['section_icons' => ['brand' => 'a:b:c']],
            ['section_icons' => ['brand' => '<svg>']],
            ['section_icons' => ['brand' => 3]],
            ['section_icons' => ['Brand' => 'config']],
            ['section_icons' => ['brand-x' => 'config']],
            ['navigation_hidden' => ['Brand']],
            ['navigation_hidden' => ['']],
            ['navigation_hidden' => ['../x']],
        ];
        foreach ($invalid as $webUi) {
            try {
                $this->process(['web_ui' => $webUi]);
                self::fail('Expected an invalid web_ui configuration: ' . json_encode($webUi));
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRevealOnToggle(): void
    {
        $config = $this->process(['web_ui' => ['reveal_on_toggle' => true, 'reveal_toggle_only' => true]])['web_ui'];
        self::assertTrue($config['reveal_on_toggle']);
        self::assertTrue($config['reveal_toggle_only']);

        $this->expectException(InvalidConfigurationException::class);
        $this->process(['web_ui' => ['reveal_on_toggle' => 'yes']]);
    }

    public function testCropConfiguration(): void
    {
        // 1.8: files.crop is false (default), true, or {shape, quality, labels}.
        self::assertSame(['enabled' => true, 'shape' => null, 'quality' => null, 'labels' => []], $this->process(['files' => ['crop' => true]])['files']['crop']);
        self::assertFalse($this->process(['files' => ['crop' => false]])['files']['crop']['enabled']);
        self::assertSame(
            ['shape' => 'circle', 'quality' => 0.8, 'labels' => ['apply' => 'Use'], 'enabled' => true],
            $this->process(['files' => ['crop' => ['shape' => 'circle', 'quality' => 0.8, 'labels' => ['apply' => 'Use']]]])['files']['crop'],
        );

        foreach ([['shape' => 'oval'], ['quality' => 2], ['aspect' => 1], ['max' => 512]] as $invalid) {
            try {
                $this->process(['files' => ['crop' => $invalid]]);
                self::fail('Expected an invalid crop configuration: ' . json_encode($invalid));
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSeedAndFilesystemStoreConfiguration(): void
    {
        $config = $this->process(['files' => ['store' => 'filesystem', 'directory' => '/srv/brand'], 'seed' => ['file' => '/srv/app/config/seed.yaml', 'base_dir' => '/srv/app']]);
        self::assertSame(['filesystem', '/srv/brand'], [$config['files']['store'], $config['files']['directory']]);
        self::assertSame(['file' => '/srv/app/config/seed.yaml', 'base_dir' => '/srv/app'], $config['seed']);

        // 1.7.1: '' passes the tree (it is what Symfony hands the closure for an %env(...)% value); the extension
        // refuses a literal one and the store / seeder an empty env value.
        self::assertSame('', $this->process(['seed' => ['base_dir' => '']])['seed']['base_dir']);
        self::assertSame('', $this->process(['files' => ['directory' => '']])['files']['directory']);

        foreach ([['files' => ['store' => 's3']], ['files' => ['directory' => ' ']], ['files' => ['directory' => 's3://bucket']], ['files' => ['directory' => 12]], ['seed' => ['base_dir' => 'phar://x']], ['seed' => ['base_dir' => "a\0b"]]] as $invalid) {
            try {
                $this->process($invalid);
                self::fail('Expected InvalidConfigurationException');
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFilesConfiguration(): void
    {
        self::assertSame(['enabled' => true, 'path_prefix' => '/media/brand', 'orphan_retention' => 0, 'store' => 'doctrine', 'directory' => '%kernel.project_dir%/config/instance-files', 'crop' => ['enabled' => false, 'shape' => null, 'quality' => null, 'labels' => []]], $this->process(['files' => ['enabled' => true, 'path_prefix' => '/media/brand', 'orphan_retention' => 0]])['files']);

        foreach ([['path_prefix' => 'media'], ['path_prefix' => '/'], ['path_prefix' => '/a b'], ['path_prefix' => '/x?y'], ['orphan_retention' => -1], ['enabled' => 'yes']] as $files) {
            try {
                $this->process(['files' => $files]);
                self::fail('Expected InvalidConfigurationException');
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
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
        $webUi = $this->process(['web_ui' => ['hidden_secrets' => 'keep_typed', 'hidden_secrets_notice' => true]])['web_ui'];
        self::assertSame('keep_typed', $webUi['hidden_secrets']);
        self::assertTrue($webUi['hidden_secrets_notice']);
    }

    public function testHiddenSecretsRefusesUnknownModes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['web_ui' => ['hidden_secrets' => 'keep']]);
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
        yield 'group parameter' => [['web_ui' => ['section_group_parameter' => 'a-b']], 'section_group_parameter must contain only'];
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
