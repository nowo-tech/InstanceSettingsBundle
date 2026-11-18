<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\InstanceSettingsBundle\Command\PurgeExpiryNoticesCommand;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\ExpiryNoticeChannelPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Configuration;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\Notify\DoctrineExpiryNoticeStore;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeChannelInterface;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeConfigurationCheck;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeLinks;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeStoreInterface;
use Nowo\InstanceSettingsBundle\Expiry\Notify\MailExpiryNoticeChannel;
use Nowo\InstanceSettingsBundle\Expiry\Notify\NotifyKitExpiryNoticeChannel;
use Nowo\InstanceSettingsBundle\Expiry\Notify\NotifyKitExpiryTypeProvider;
use Nowo\InstanceSettingsBundle\Expiry\Notify\SecretExpiryNotifier;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Throwable;

use function array_column;
use function array_keys;

/**
 * 1.21 (KIT-88): `expiring_secrets.notify` — defaults (off, no schema change), the channels picked from what is
 * installed, the services, the opt-in mapping and every refused configuration.
 */
final class ExpiryNotifierExtensionTest extends TestCase
{
    private const MAIL_KIT = ['NowoMailKitBundle' => 'Nowo\MailKitBundle\NowoMailKitBundle'];

    private const NOTIFY_KIT = ['NowoNotifyKitBundle' => 'Nowo\NotifyKitBundle\NowoNotifyKitBundle'];

    public function testConfigurationDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame([
            'enabled'             => false,
            'days'                => [30, 7, 1, 0],
            'roles'               => [],
            'recipient_provider'  => null,
            'mail_template'       => '@NowoInstanceSettingsBundle/expiry/notice',
            'mail_from'           => null,
            'link_route'          => null,
            'fallback_link_route' => null,
            'retention_days'      => 90,
            'channels'            => ['mail' => 'auto', 'notify_kit' => 'auto'],
            'notify_kit'          => ['recipient_type' => 'user', 'category' => 'instance_settings', 'declare_category' => true],
        ], $config['expiring_secrets']['notify']);

        $container = $this->load([]);
        self::assertFalse($container->hasDefinition(SecretExpiryNotifier::class), 'off by default');
        self::assertFalse($container->hasAlias(ExpiryNoticeStoreInterface::class));
    }

    public function testDaysMustBeDistinctAndNonEmpty(): void
    {
        foreach ([[], [7, 7]] as $days) {
            try {
                (new Processor())->processConfiguration(new Configuration(), [['expiring_secrets' => ['notify' => ['days' => $days]]]]);
                self::fail('Expected a refusal');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('notify.days must be a non-empty list of distinct day counts', $e->getMessage());
            }
        }
    }

    public function testMailKitAndNotifyKitInstalledGiveBothChannels(): void
    {
        $container = $this->load($this->notify(), self::MAIL_KIT + self::NOTIFY_KIT);

        $notifier = $container->getDefinition(SecretExpiryNotifier::class);
        self::assertTrue($notifier->isPublic());
        self::assertSame([['event' => SecretExpiryThresholdReachedEvent::class, 'method' => '__invoke']], $notifier->getTag('kernel.event_listener'));
        self::assertSame('app.expiry_recipients', (string) $notifier->getArgument(0));
        self::assertSame(ExpiryNoticeStoreInterface::class, (string) $notifier->getArgument(1));
        $channels = $notifier->getArgument(2);
        self::assertInstanceOf(TaggedIteratorArgument::class, $channels);
        self::assertSame(NowoInstanceSettingsBundle::EXPIRY_CHANNEL_TAG, $channels->getTag());
        self::assertSame([MailExpiryNoticeChannel::class, NotifyKitExpiryNoticeChannel::class], array_keys($container->findTaggedServiceIds(NowoInstanceSettingsBundle::EXPIRY_CHANNEL_TAG)));
        self::assertTrue($container->getAutoconfiguredInstanceof()[ExpiryNoticeChannelInterface::class]->hasTag(NowoInstanceSettingsBundle::EXPIRY_CHANNEL_TAG), 'host channels');
        self::assertSame([30, 7, 1, 0], $notifier->getArgument(3));
        self::assertSame(['ROLE_ADMIN'], $notifier->getArgument(4), 'no roles: security.access_roles');

        self::assertSame(DoctrineExpiryNoticeStore::class, (string) $container->getAlias(ExpiryNoticeStoreInterface::class));
        self::assertSame([InstanceSettingsRoutes::SECTION, null], [$container->getDefinition(ExpiryNoticeLinks::class)->getArgument(1), $container->getDefinition(ExpiryNoticeLinks::class)->getArgument(2)]);
        $mail = $container->getDefinition(MailExpiryNoticeChannel::class);
        self::assertSame(['@NowoInstanceSettingsBundle/expiry/notice', null], [$mail->getArgument(3), $mail->getArgument(5)]);
        self::assertSame(['user'], [$container->getDefinition(NotifyKitExpiryNoticeChannel::class)->getArgument(2)]);
        $check = $container->getDefinition(ExpiryNoticeConfigurationCheck::class);
        self::assertTrue($check->hasTag('kernel.cache_warmer'));
        self::assertSame([[InstanceSettingsRoutes::SECTION], '@NowoInstanceSettingsBundle/expiry/notice'], [$check->getArgument(2), $check->getArgument(3)]);
        self::assertSame([PurgeExpiryNoticesCommand::NAME], array_column($container->getDefinition(PurgeExpiryNoticesCommand::class)->getTag('console.command'), 'command'));
        self::assertSame(90, $container->getDefinition(PurgeExpiryNoticesCommand::class)->getArgument(1));
        $types = $container->getDefinition(NotifyKitExpiryTypeProvider::class);
        self::assertTrue($types->hasTag('nowo_notify_kit.type_provider'));
        self::assertSame(['instance_settings', true], $types->getArguments());
    }

    public function testCustomOptions(): void
    {
        $container = $this->load($this->notify([
            'days'                => [7, 0],
            'roles'               => ['ROLE_MANAGER', 'ROLE_DEVELOPER'],
            'mail_template'       => 'emails/secret_expiry',
            'mail_from'           => 'noreply@example.test',
            'link_route'          => 'app_settings',
            'fallback_link_route' => 'app_home',
            'channels'            => ['mail' => 'on', 'notify_kit' => 'off'],
            'notify_kit'          => ['recipient_type' => 'member', 'category' => 'security', 'declare_category' => false],
        ]), self::NOTIFY_KIT);
        $notifier = $container->getDefinition(SecretExpiryNotifier::class);
        self::assertSame([7, 0], $notifier->getArgument(3));
        self::assertSame(['ROLE_MANAGER', 'ROLE_DEVELOPER'], $notifier->getArgument(4));
        self::assertSame([MailExpiryNoticeChannel::class], array_keys($container->findTaggedServiceIds(NowoInstanceSettingsBundle::EXPIRY_CHANNEL_TAG)), 'mail on without MailKit (symfony/mailer), the bell off');
        self::assertFalse($container->hasDefinition(NotifyKitExpiryNoticeChannel::class));
        self::assertSame(['emails/secret_expiry', 'noreply@example.test'], [$container->getDefinition(MailExpiryNoticeChannel::class)->getArgument(3), $container->getDefinition(MailExpiryNoticeChannel::class)->getArgument(5)]);
        self::assertSame(['app_settings', 'app_home'], [$container->getDefinition(ExpiryNoticeLinks::class)->getArgument(1), $container->getDefinition(ExpiryNoticeLinks::class)->getArgument(2)]);
        self::assertSame(['app_settings', 'app_home'], $container->getDefinition(ExpiryNoticeConfigurationCheck::class)->getArgument(2));
    }

    public function testNotifyKitAloneAndNoLinkWithoutTheAdminUi(): void
    {
        $container = $this->load($this->notify([], ['web_ui' => ['enabled' => false]]), self::NOTIFY_KIT, withSecurity: false);
        self::assertFalse($container->hasDefinition(MailExpiryNoticeChannel::class), 'auto: no MailKit, no mail');
        self::assertTrue($container->hasDefinition(NotifyKitExpiryNoticeChannel::class));
        self::assertNull($container->getDefinition(ExpiryNoticeLinks::class)->getArgument(1));
        self::assertSame([[], null], [$container->getDefinition(ExpiryNoticeConfigurationCheck::class)->getArgument(2), $container->getDefinition(ExpiryNoticeConfigurationCheck::class)->getArgument(3)], 'no route, no mail template to check');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>, bool, bool, class-string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'expiring secrets off' => [['expiring_secrets' => ['notify' => ['enabled' => true, 'recipient_provider' => 'x']]], self::MAIL_KIT, true, true, LogicException::class, 'needs expiring_secrets.enabled: true'];
        yield 'no recipient provider' => [['expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true]]], self::MAIL_KIT, true, true, InvalidConfigurationException::class, 'recipient_provider must be the id of a service'];
        yield 'blank recipient provider' => [['expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true, 'recipient_provider' => ' ']]], self::MAIL_KIT, true, true, InvalidConfigurationException::class, 'recipient_provider must be the id of a service'];
        yield 'a day that never fires' => [['expiring_secrets' => ['enabled' => true, 'thresholds' => [30, 7], 'notify' => ['enabled' => true, 'recipient_provider' => 'x', 'days' => [30, 14]]]], self::MAIL_KIT, true, true, InvalidConfigurationException::class, 'notify.days must be among expiring_secrets.thresholds (30, 7); 14 never fire(s).'];
        yield 'no roles at all' => [['security' => ['access_roles' => [], 'access_checker' => 'app.checker'], 'expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true, 'recipient_provider' => 'x']]], self::MAIL_KIT, true, true, InvalidConfigurationException::class, 'notify.roles is empty and so is security.access_roles'];
        yield 'invalid mail_from' => [['expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true, 'recipient_provider' => 'x', 'mail_from' => 'not an address']]], self::MAIL_KIT, true, true, InvalidConfigurationException::class, 'notify.mail_from must be an e-mail address'];
        yield 'mail on without symfony/mailer' => [['expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true, 'recipient_provider' => 'x', 'channels' => ['mail' => 'on']]]], [], false, true, LogicException::class, 'notify.channels.mail is on but symfony/mailer is not installed'];
        yield 'bell on without NotifyKit' => [['expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true, 'recipient_provider' => 'x', 'channels' => ['notify_kit' => 'on']]]], self::MAIL_KIT, true, false, LogicException::class, 'notify.channels.notify_kit is on but nowo-tech/notify-kit-bundle'];
        yield 'bell on, bundle not registered' => [['expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true, 'recipient_provider' => 'x', 'channels' => ['notify_kit' => 'on']]]], self::MAIL_KIT, true, true, LogicException::class, 'notify.channels.notify_kit is on but nowo-tech/notify-kit-bundle'];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $bundles
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('refusals')]
    public function testRefusedConfigurations(array $config, array $bundles, bool $mailer, bool $notifyKit, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        $this->load($config, $bundles, mailer: $mailer, notifyKit: $notifyKit);
    }

    public function testTheMappingIsPrependedOnlyWithTheFlag(): void
    {
        $container = $this->prepended([['expiring_secrets' => ['enabled' => true]]]);
        self::assertArrayNotHasKey(NowoInstanceSettingsExtension::NOTICE_MAPPING, $container->getExtensionConfig('doctrine')[0]['orm']['mappings']);
        self::assertFalse($container->getParameter(NowoInstanceSettingsExtension::NOTICE_MAPPED_PARAMETER));

        $container = $this->prepended([['expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true]]], ['expiring_secrets' => 'x'], ['expiring_secrets' => ['notify' => 'x']]]);
        $mapping   = $container->getExtensionConfig('doctrine')[0]['orm']['mappings'][NowoInstanceSettingsExtension::NOTICE_MAPPING];
        self::assertSame('Nowo\InstanceSettingsBundle\ExpiryNoticeEntity', $mapping['prefix']);
        self::assertStringEndsWith('/ExpiryNoticeEntity', $mapping['dir']);
        self::assertTrue($container->getParameter(NowoInstanceSettingsExtension::NOTICE_MAPPED_PARAMETER));

        $container = $this->prepended([['expiring_secrets' => ['notify' => ['enabled' => true]]], ['expiring_secrets' => ['notify' => ['enabled' => false]]]]);
        self::assertFalse($container->getParameter(NowoInstanceSettingsExtension::NOTICE_MAPPED_PARAMETER), 'the last value wins');

        $container = $this->prepended([['doctrine' => ['entity_manager' => 'settings'], 'expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true]]]]);
        self::assertSame([], $container->getExtensionConfig('doctrine'));
        self::assertTrue($container->getParameter(NowoInstanceSettingsExtension::DEDICATED_NOTICE_MAPPING_PARAMETER));
    }

    public function testTheBuildFailsWithoutAnyChannelButAcceptsAHostChannel(): void
    {
        $container = $this->load($this->notify(['channels' => ['mail' => 'off', 'notify_kit' => 'off']]), self::MAIL_KIT + self::NOTIFY_KIT);
        (new ExpiryNoticeChannelPass())->process($this->load([]));
        $container->register('app.sms_channel')->addTag(NowoInstanceSettingsBundle::EXPIRY_CHANNEL_TAG);
        (new ExpiryNoticeChannelPass())->process($container);
        $container->removeDefinition('app.sms_channel');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('expiring_secrets.notify.enabled has no channel');
        (new ExpiryNoticeChannelPass())->process($container);
    }

    public function testAFlagPrependedAfterTheBundleFailsClearly(): void
    {
        $container = $this->container(self::MAIL_KIT);
        $container->setParameter(NowoInstanceSettingsExtension::NOTICE_MAPPED_PARAMETER, false);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('expiring_secrets.notify.enabled was set by a configuration prepended after this bundle');
        (new NowoInstanceSettingsExtension(mailer: true, notifyKit: true))->load([$this->notify()], $container);
    }

    /**
     * @param array<string, mixed> $notify
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private function notify(array $notify = [], array $more = []): array
    {
        return ['expiring_secrets' => ['enabled' => true, 'notify' => ['enabled' => true, 'recipient_provider' => 'app.expiry_recipients'] + $notify]] + $more;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $bundles
     */
    private function load(array $config, array $bundles = [], bool $withSecurity = true, bool $mailer = true, bool $notifyKit = true): ContainerBuilder
    {
        $container = $this->container($bundles, $withSecurity);
        (new NowoInstanceSettingsExtension(mailer: $mailer, notifyKit: $notifyKit))->load([$config], $container);

        return $container;
    }

    /**
     * @param array<string, string> $bundles
     */
    private function container(array $bundles, bool $withSecurity = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', $bundles);
        if ($withSecurity) {
            $container->registerExtension($this->extension('security'));
        }

        return $container;
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function prepended(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension('doctrine'));
        $container->registerExtension(new NowoInstanceSettingsExtension());
        foreach ($configs as $config) {
            $container->loadFromExtension('nowo_instance_settings', $config);
        }
        (new NowoInstanceSettingsExtension())->prepend($container);

        return $container;
    }

    private function extension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
