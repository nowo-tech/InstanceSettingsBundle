<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use LogicException;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Secret\SecretRevealer;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\RevealAuditListener;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\RevealFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

use function array_replace_recursive;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.15 (owner decision of 2026-11-13): an explicit Reveal action for a secret field declared `revealable: true`, for a
 * user holding the reveal roles only, one field per call, audited, never cacheable and never in a URL.
 */
final class SecretRevealTest extends KernelTestCase
{
    /** A throwaway value, never a real secret. */
    private const SECRET = 'placeholder-client-secret-7f3a';

    private const URL = '/admin/settings/sections/oauth/secrets/client_secret/reveal';

    /** Service id of the demo / test reveal limiter (`framework.rate_limiter.instance_settings_reveal`). */
    private const LIMITER = 'limiter.instance_settings_reveal';

    public function testADeveloperRevealsTheStoredSecretAndTheCallIsAudited(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        // The control is a button plus the endpoint URL and the CSRF token: the plaintext is never in the page.
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('[data-testid="instance-settings-reveal"]'));
        self::assertCount(1, $crawler->filter('[' . SettingsSectionType::REVEAL_ATTRIBUTE . '="client_secret"]'));
        self::assertCount(0, $crawler->filter('[' . SettingsSectionType::REVEAL_ATTRIBUTE . '="signing_key"]'), 'a secret without the opt-in gets no control');
        self::assertCount(1, $crawler->filter('script[data-nowo-instance-settings-reveal-script]'));
        self::assertCount(1, $crawler->filter('link[data-nowo-instance-settings-styles]'));
        self::assertSame(['client_secret' => self::URL], json_decode((string) $crawler->filter('[data-nowo-instance-settings-reveal-root]')->attr('data-urls'), true, 512, JSON_THROW_ON_ERROR));

        $client->request('POST', self::URL, ['_token' => $this->token($crawler)]);
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['key' => 'oauth.client_secret', 'value' => self::SECRET], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        // Never cacheable, never indexed, never a referrer (REQ-SEC the reveal response holds a plaintext secret).
        self::assertTrue($response->headers->getCacheControlDirective('no-store'), 'no-store');
        self::assertTrue($response->headers->getCacheControlDirective('private'), 'private');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));

        // The audit entry names the field, the actor and the time — never the value (REQ-OBS-001).
        $listener = $this->container($kernel)->get('app.reveal_listener');
        self::assertInstanceOf(RevealAuditListener::class, $listener);
        self::assertCount(1, $listener->events);
        self::assertSame('oauth.client_secret', $listener->events[0]->key());
        self::assertSame('developer', $listener->events[0]->actor);
        self::assertTrue($listener->events[0]->stored);
        self::assertNotSame('', $listener->events[0]->revealedAt->format('c'));

        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertStringContainsString(SecretRevealer::LOG_REVEALED, $logger->dump());
        self::assertStringContainsString('oauth.client_secret', $logger->dump());
        self::assertStringNotContainsString(self::SECRET, $logger->dump(), 'no secret value in any log line');
    }

    public function testAUserWithoutTheRevealRoleSeesNoControlAndIsRefusedByTheEndpoint(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);

        // The page of a user who may open the section but not reveal carries no reveal markup at all.
        $token = $this->token($this->client($kernel, 'developer')->request('GET', '/admin/settings/sections/oauth'));

        $admin   = $this->client($kernel, 'admin');
        $crawler = $admin->request('GET', '/admin/settings/sections/oauth');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-reveal"]'));
        self::assertCount(0, $crawler->filter('[' . SettingsSectionType::REVEAL_ATTRIBUTE . ']'));

        // And the endpoint refuses the call itself: the token is valid (the browser and its session are the same), the
        // roles are not — a hidden control is never the only check.
        $admin->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(403, $admin->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $admin->getResponse()->getContent());

        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertStringContainsString(SecretRevealer::REASON_FORBIDDEN, $logger->dump());
        self::assertStringNotContainsString(self::SECRET, $logger->dump());
    }

    public function testASecretWithoutTheOptInIsRefusedEvenWhenAskedForDirectly(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('oauth', ['signing_key' => self::SECRET]);
        $client = $this->client($kernel, 'developer');
        $token  = $this->token($client->request('GET', '/admin/settings/sections/oauth'));

        $client->request('POST', '/admin/settings/sections/oauth/secrets/signing_key/reveal', ['_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());

        // A look-alike field name never reaches the declared field (REQ-SEC-006: the names are matched byte for byte).
        $client->request('POST', '/admin/settings/sections/oauth/secrets/client_secret_/reveal', ['_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        // Not a plain field either.
        $client->request('POST', '/admin/settings/sections/oauth/secrets/client_id/reveal', ['_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        // A look-alike section name: nothing is declared under it, so the field is not revealable there either.
        $client->request('POST', '/admin/settings/sections/oauth2/secrets/client_secret/reveal', ['_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertStringContainsString(SecretRevealer::REASON_NOT_REVEALABLE, $logger->dump());
    }

    public function testWithoutACsrfTokenNothingIsRevealed(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client = $this->client($kernel, 'developer');
        $client->request('GET', '/admin/settings/sections/oauth');

        $client->request('POST', self::URL);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', self::URL, ['_token' => 'wrong']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());

        // GET is not a reveal (the field name is in the path, the secret only ever in a POST response body).
        $client->request('GET', self::URL);
        self::assertSame(405, $client->getResponse()->getStatusCode());
    }

    public function testARevealableFieldWithoutAStoredSecretAnswersNotStored(): void
    {
        $kernel  = $this->boot();
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');

        $client->request('POST', self::URL, ['_token' => $this->token($crawler)]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(['key' => 'oauth.client_secret', 'error' => 'not_stored'], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));

        $listener = $this->container($kernel)->get('app.reveal_listener');
        self::assertInstanceOf(RevealAuditListener::class, $listener);
        self::assertCount(1, $listener->events, 'an allowed reveal is audited even when nothing is stored');
        self::assertFalse($listener->events[0]->stored);
    }

    public function testTheRateLimiterRefusesBulkHarvesting(): void
    {
        $kernel = $this->boot(extensions: $this->limiter(2));
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client = $this->client($kernel, 'developer');
        $token  = $this->token($client->request('GET', '/admin/settings/sections/oauth'));

        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());

        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertStringContainsString(SecretRevealer::REASON_RATE_LIMITED, $logger->dump());
    }

    public function testNothingAboutARevealIsKeptBetweenRequests(): void
    {
        // REQ-RUNTIME-002: the same kernel (worker mode, no reset) answers the value stored now, not the one it read
        // in an earlier request.
        $kernel = $this->boot();
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client = $this->client($kernel, 'developer');
        $token  = $this->token($client->request('GET', '/admin/settings/sections/oauth'));

        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(self::SECRET, $this->value($client));

        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET . '-rotated']);
        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(self::SECRET . '-rotated', $this->value($client));

        // And the stored secret is never revealed to a later anonymous request either.
        $anonymous = $this->client($kernel, null);
        $anonymous->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(401, $anonymous->getResponse()->getStatusCode());
    }

    public function testARevealableFieldWithoutARateLimiterFailsClosed(): void
    {
        // Owner decision of 2026-11-13: the feature cannot be deployed without a brake. The section definitions
        // refuse to load (router warm-up / cache:warmup, else the first request), naming the field and the option.
        $kernel = $this->bootKernel(services: [RevealFixtureSection::class => RevealFixtureSection::class]);

        $registry = $this->container($kernel)->get(SettingsSectionRegistry::class);
        self::assertInstanceOf(SettingsSectionRegistry::class, $registry);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Instance settings section "oauth" declares the revealable secret field "client_secret", but nowo_instance_settings.security.reveal_rate_limiter is not set');
        $registry->all();
    }

    public function testAHostWithoutARevealableFieldNeedsNoRateLimiter(): void
    {
        // Nothing changes for a host that does not use the feature: no limiter, no error, no reveal markup.
        $kernel  = $this->bootKernel();
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-reveal"]'));
        self::assertCount(0, $crawler->filter('[' . SettingsSectionType::REVEAL_ATTRIBUTE . ']'));
        $registry = $this->container($kernel)->get(SettingsSectionRegistry::class);
        self::assertInstanceOf(SettingsSectionRegistry::class, $registry);
        self::assertFalse($registry->field('mailer', 'dsn')->revealable, 'the fixture secret is write-only');
    }

    public function testTheKillSwitchNeedsNoRateLimiterEither(): void
    {
        // reveal_roles: [] switches every reveal off, so a missing limiter blocks nothing: turning the feature off
        // must never be harder than turning it on.
        $kernel = $this->bootKernel(['security' => ['reveal_roles' => []]], services: [
            RevealFixtureSection::class => RevealFixtureSection::class,
            'logger'                    => RecordingLogger::class,
        ]);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-reveal"]'), 'no control with reveals switched off');
        $client->request('POST', self::URL, ['_token' => 'whatever']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
    }

    /**
     * A kernel with the revealable fixture section and a reveal rate limiter (required since the owner decision of
     * 2026-11-13: a revealable field without one fails closed).
     *
     * @param array<string, mixed> $config nowo_instance_settings (the limiter is set unless `$config` says otherwise)
     * @param array<string, array<string, mixed>> $extensions other extension configs (the limiter is declared unless `framework` is given)
     * @param array<string, string> $services extra services (default: the fixture section, the audit listener, the logger)
     */
    private function boot(array $config = [], array $extensions = [], ?array $services = null): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            array_replace_recursive(['security' => ['reveal_rate_limiter' => self::LIMITER]], $config),
            services: $services ?? [
                RevealFixtureSection::class => RevealFixtureSection::class,
                'app.reveal_listener'       => RevealAuditListener::class,
                'logger'                    => RecordingLogger::class,
            ],
            extensions: $extensions === [] ? $this->limiter() : $extensions,
        );
    }

    /**
     * `framework.rate_limiter` declaring {@see self::LIMITER} with `$limit` reveals per hour.
     *
     * @return array<string, array<string, mixed>>
     */
    private function limiter(int $limit = 100): array
    {
        return ['framework' => ['rate_limiter' => ['instance_settings_reveal' => ['policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour']]]];
    }

    private function value(KernelBrowser $client): string
    {
        /** @var array{value?: string} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload['value'] ?? '';
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('[data-nowo-instance-settings-reveal-root]')->attr('data-token');
    }
}
