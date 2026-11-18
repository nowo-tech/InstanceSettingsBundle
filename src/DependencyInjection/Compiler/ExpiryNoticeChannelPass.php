<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\Expiry\Notify\SecretExpiryNotifier;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails the build when the default expiry notifier is on (1.21, `expiring_secrets.notify.enabled: true`) but no
 * channel can deliver: neither MailKit / symfony/mailer, nor NotifyKit, nor a host service implementing
 * `ExpiryNoticeChannelInterface`. A notifier without channels would mark thresholds as announced and send nothing.
 */
final class ExpiryNoticeChannelPass implements CompilerPassInterface
{
    /**
     * Throws a LogicException when the notifier has no channel.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SecretExpiryNotifier::class) || $container->findTaggedServiceIds(NowoInstanceSettingsBundle::EXPIRY_CHANNEL_TAG) !== []) {
            return;
        }

        throw new LogicException('NowoInstanceSettingsBundle: expiring_secrets.notify.enabled has no channel. Install nowo-tech/mail-kit-bundle or nowo-tech/notify-kit-bundle, set notify.channels.mail: on (symfony/mailer), or register a service implementing ExpiryNoticeChannelInterface.');
    }
}
