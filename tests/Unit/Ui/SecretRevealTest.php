<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Ui;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Event\SecretRevealedEvent;
use Nowo\InstanceSettingsBundle\Ui\SecretReveal;
use PHPUnit\Framework\TestCase;

/**
 * 1.15: what the Reveal control carries into the page (never a secret) and the audit record it leads to.
 */
final class SecretRevealTest extends TestCase
{
    public function testTheControlCarriesOnlyUrlsAndThePerSectionTokenId(): void
    {
        $reveal = new SecretReveal('oauth', ['client_secret' => '/admin/settings/sections/oauth/secrets/client_secret/reveal']);

        self::assertSame('nowo_instance_settings_secret_reveal_oauth', $reveal->tokenId());
        self::assertSame('{"client_secret":"/admin/settings/sections/oauth/secrets/client_secret/reveal"}', $reveal->urlsJson());
        self::assertSame('{}', (new SecretReveal('oauth', []))->urlsJson());
    }

    public function testTheAuditRecordNamesTheFieldTheActorAndTheTime(): void
    {
        $event = new SecretRevealedEvent('oauth', 'client_secret', 'developer', new DateTimeImmutable('2026-11-13 09:00:00'));

        self::assertSame('oauth.client_secret', $event->key());
        self::assertSame('developer', $event->actor);
        self::assertTrue($event->stored);
    }
}
