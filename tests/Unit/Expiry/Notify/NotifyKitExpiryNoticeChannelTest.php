<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Expiry\Notify;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeKey;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeLinks;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryRecipient;
use Nowo\InstanceSettingsBundle\Expiry\Notify\NotifyKitExpiryNoticeChannel;
use Nowo\InstanceSettingsBundle\Expiry\Notify\NotifyKitExpiryTypeProvider;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingNotifier;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Contract\Urgency;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

use function iterator_to_array;
use function str_repeat;

/**
 * 1.21 (KIT-88): the NotifyKit bell channel (one request per recipient, a local path, no secret, NotifyKit's own
 * dedupe key) and its two types.
 */
final class NotifyKitExpiryNoticeChannelTest extends TestCase
{
    public function testOneBellNotificationPerRecipient(): void
    {
        $notifier   = $this->notifier();
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['providers.api_key' => 'Clave API'], 'es', 'app');
        $channel = new NotifyKitExpiryNoticeChannel($notifier, $this->links(), 'member', $translator);
        self::assertSame('notify_kit', $channel->name());
        self::assertTrue($channel->supports(new ExpiryRecipient('3')));

        $event = new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 7, 7);
        $channel->send($event, new ExpiryRecipient('1', 'dev@example.test', 'es', true));
        $channel->send($event, new ExpiryRecipient('2', null, 'es'));
        $channel->send(new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 0, -2), new ExpiryRecipient('1'));

        [$first, $manager, $expired] = $notifier->requests;
        self::assertSame(NotifyKitExpiryNoticeChannel::TYPE_EXPIRING, $first->type);
        self::assertSame('member:1', $first->recipients[0]->key());
        self::assertNull($first->actor);
        self::assertNull($first->context);
        self::assertSame('/admin/settings/sections/providers', $first->url);
        self::assertSame(['credential' => 'Clave API', 'date' => '2026-12-31', 'days' => '7'], $first->params);
        self::assertSame(Urgency::Normal, $first->urgency);
        self::assertSame('secret_expiry:' . ExpiryNoticeKey::of($event, 'notify_kit', new ExpiryRecipient('1'))->hash(), $first->dedupeKey);
        self::assertStringStartsWith('secret_expiry:', $first->target);
        self::assertSame('/', $manager->url, 'no fallback route: the start page');
        self::assertNotSame($first->dedupeKey, $manager->dedupeKey);
        self::assertSame(NotifyKitExpiryNoticeChannel::TYPE_EXPIRED, $expired->type);
        self::assertSame(Urgency::High, $expired->urgency);
        self::assertSame(['credential' => 'providers.api_key', 'date' => '2026-12-31', 'days' => '2'], $expired->params, 'another locale falls back to the key');
    }

    public function testWithoutATranslatorTheLabelKeyIsSentAndALongIdFits(): void
    {
        $notifier = $this->notifier();
        (new NotifyKitExpiryNoticeChannel($notifier, $this->links()))->send(new SecretExpiryThresholdReachedEvent(str_repeat('s', 64), str_repeat('f', 64), 'providers.api_key', 'app', '2026-12-31', 1, 1), new ExpiryRecipient(str_repeat('9', 128)));
        self::assertSame('providers.api_key', $notifier->requests[0]->params['credential']);
        self::assertSame('user:' . str_repeat('9', 128), $notifier->requests[0]->recipients[0]->key());
        self::assertSame(Urgency::High, $notifier->requests[0]->urgency, 'one day left');
    }

    public function testTheTypeProviderDeclaresTwoInAppTypesAndItsCategory(): void
    {
        $types = iterator_to_array((new NotifyKitExpiryTypeProvider())->types(), false);
        self::assertSame([NotifyKitExpiryNoticeChannel::TYPE_EXPIRING, NotifyKitExpiryNoticeChannel::TYPE_EXPIRED], [$types[0]->key, $types[1]->key]);
        self::assertSame(['instance_settings', 'instance_settings'], [$types[0]->category, $types[1]->category]);
        self::assertSame([TypeDefinition::IN_APP => true], $types[0]->defaultChannels);
        self::assertSame(Urgency::High, $types[1]->urgency);
        self::assertSame('secret_expiry_notice.notify_expiring', $types[0]->titleKey);
        $categories = iterator_to_array((new NotifyKitExpiryTypeProvider())->categories(), false);
        self::assertSame('instance_settings', $categories[0]->key);
        self::assertSame('secret_expiry_notice.notify_category', $categories[0]->labelKey);

        $hosted = new NotifyKitExpiryTypeProvider('security', false);
        self::assertSame([], iterator_to_array($hosted->categories(), false));
        self::assertSame('security', iterator_to_array($hosted->types(), false)[0]->category);
    }

    private function links(): ExpiryNoticeLinks
    {
        $routes = new RouteCollection();
        $routes->add(InstanceSettingsRoutes::SECTION, new Route('/admin/settings/sections/{section}'));

        return new ExpiryNoticeLinks(new UrlGenerator($routes, new RequestContext(host: 'beacon.example.test', scheme: 'https')), InstanceSettingsRoutes::SECTION);
    }

    private function notifier(): RecordingNotifier
    {
        return new RecordingNotifier();
    }
}
