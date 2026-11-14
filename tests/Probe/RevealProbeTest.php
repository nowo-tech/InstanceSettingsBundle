<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Probe;

use Nowo\InstanceSettingsBundle\EventListener\InstanceSettingsAccessListener;
use Nowo\InstanceSettingsBundle\Secret\RevealRefusalLog;
use Nowo\InstanceSettingsBundle\Secret\SecretRevealer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\RevealAuditListener;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\RevealFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use Symfony\Component\DomCrawler\Crawler;

use function array_replace_recursive;
use function json_decode;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * Probes of the 1.15 secret reveal, lifted from the security audit of 2026-11-13 and kept permanently: they try to get
 * the plaintext out without the reveal role by every route the auditor tried, and they pin the answers of the shapes
 * that must never change (the kill switch, a host checker that grants only part of the three checks, an inconsistent
 * checker, `allow_unauthenticated`, the same field name in two sections, a battery of look-alike paths, a secret that
 * is not text).
 */
final class RevealProbeTest extends KernelTestCase
{
    private const SECRET = 'placeholder-client-secret-7f3a';
    private const URL    = '/admin/settings/sections/oauth/secrets/client_secret/reveal';

    /**
     * A kernel with the revealable fixture section and the reveal rate limiter the bundle requires for it.
     *
     * @param array<string, mixed> $config nowo_instance_settings
     * @param array<string, string> $extraServices extra service id => class
     * @param array<string, array<string, mixed>>|null $extensions other extension configs (null = the 100 / hour limiter)
     */
    private function boot(array $config = [], array $extraServices = [], bool $debug = false, ?array $extensions = null): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            array_replace_recursive(['security' => ['reveal_rate_limiter' => 'limiter.instance_settings_reveal']], $config),
            services: [
                RevealFixtureSection::class => RevealFixtureSection::class,
                'app.reveal_listener'       => RevealAuditListener::class,
                'logger'                    => RecordingLogger::class,
            ] + $extraServices,
            debug: $debug,
            extensions: $extensions ?? ['framework' => ['rate_limiter' => ['instance_settings_reveal' => ['policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 hour']]]],
        );
    }

    /**
     * The reveal CSRF token of the rendered page, or '' when the page carries no Reveal control.
     */
    private function token(Crawler $c): string
    {
        $n = $c->filter('[data-nowo-instance-settings-reveal-root]');

        return $n->count() === 0 ? '' : (string) $n->attr('data-token');
    }

    public function testEmptyRevealRolesRefuseEveryone(): void
    {
        $kernel = $this->boot(['security' => ['reveal_roles' => []]]);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-reveal"]'), 'no control');
        $client->request('POST', self::URL, ['_token' => 'whatever']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertStringNotContainsString(self::SECRET, $logger->dump());
    }

    public function testRevealRoleWithoutTheSectionArea(): void
    {
        // Custom checker: UI gate + _reveal yes, the section's own area never.
        $kernel = $this->boot(['security' => ['access_checker' => 'app.checker']], ['app.checker' => RevealOnlyChecker::class]);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client = $this->client($kernel, 'developer');
        $client->request('POST', self::URL, ['_token' => 'whatever']);
        $status = $client->getResponse()->getStatusCode();
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        self::assertSame(403, $status, 'reveal role without the section area');
    }

    public function testSectionAreaWithoutTheRevealArea(): void
    {
        $kernel = $this->boot(['security' => ['access_checker' => 'app.checker']], ['app.checker' => SectionOnlyChecker::class]);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-reveal"]'));
        $client->request('POST', self::URL, ['_token' => 'whatever']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
    }

    public function testEverythingButTheUiGate(): void
    {
        $kernel = $this->boot(['security' => ['access_checker' => 'app.checker']], ['app.checker' => NoUiGateChecker::class]);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client = $this->client($kernel, 'developer');
        $client->request('POST', self::URL, ['_token' => 'whatever']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
    }

    public function testFlakyCheckerCannotBeTalkedIntoAReveal(): void
    {
        $kernel = $this->boot(['security' => ['access_checker' => 'app.checker']], ['app.checker' => FlakyChecker::class]);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client = $this->client($kernel, 'developer');
        for ($i = 0; $i < 4; ++$i) {
            $client->request('POST', self::URL, ['_token' => 'whatever']);
            self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent(), "call {$i}");
        }
    }

    public function testAllowUnauthenticatedGrantsRevealToAnyone(): void
    {
        $kernel = $this->boot(['security' => ['allow_unauthenticated' => true, 'access_roles' => []]], debug: true);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client  = $this->client($kernel, null);
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent(), 'page still has no value');
        $client->request('POST', self::URL, ['_token' => $this->token($crawler)]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'anonymous reveal with allow_unauthenticated');
        self::assertStringContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        $listener = $this->container($kernel)->get('app.reveal_listener');
        self::assertInstanceOf(RevealAuditListener::class, $listener);
        self::assertNull($listener->events[0]->actor, 'the audit record cannot name an actor');
    }

    public function testSameFieldNameRevealableInOneSectionOnly(): void
    {
        $kernel = $this->boot([], ['app.other_section' => OtherFixtureSection::class]);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $this->settings($kernel)->save('oauth_other', ['client_secret' => self::SECRET . '-other']);
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');
        $token   = $this->token($crawler);
        $other   = $client->request('GET', '/admin/settings/sections/oauth_other');
        self::assertCount(0, $other->filter('[data-testid="instance-settings-reveal"]'), 'no control in the other section');
        $client->request('POST', '/admin/settings/sections/oauth_other/secrets/client_secret/reveal', ['_token' => $this->token($other)]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        // And the token of section oauth does not authorise a reveal in oauth_other.
        $client->request('POST', '/admin/settings/sections/oauth_other/secrets/client_secret/reveal', ['_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testPathParameterBattery(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client = $this->client($kernel, 'developer');
        $token  = $this->token($client->request('GET', '/admin/settings/sections/oauth'));
        $cases  = [
            'uppercase'            => '/admin/settings/sections/oauth/secrets/Client_Secret/reveal',
            'upper section'        => '/admin/settings/sections/OAuth/secrets/client_secret/reveal',
            'cyrillic lookalike'   => '/admin/settings/sections/oauth/secrets/' . rawurlencode("\u{0441}lient_secret") . '/reveal',
            'turkish dotless'      => '/admin/settings/sections/oauth/secrets/' . rawurlencode("cl\u{0131}ent_secret") . '/reveal',
            'fullwidth'            => '/admin/settings/sections/oauth/secrets/' . rawurlencode("\u{FF43}lient_secret") . '/reveal',
            'trailing space'       => '/admin/settings/sections/oauth/secrets/client_secret%20/reveal',
            'leading space'        => '/admin/settings/sections/oauth/secrets/%20client_secret/reveal',
            'embedded newline'     => '/admin/settings/sections/oauth/secrets/client_secret%0A/reveal',
            'trailing nul'         => '/admin/settings/sections/oauth/secrets/client_secret%00/reveal',
            'plural'               => '/admin/settings/sections/oauth/secrets/client_secrets/reveal',
            'encoded slash'        => '/admin/settings/sections/oauth/secrets/client%2Fsecret/reveal',
            'double encoded slash' => '/admin/settings/sections/oauth/secrets/client%252Fsecret/reveal',
            'encoded dot segment'  => '/admin/settings/sections/oauth/secrets/%2E%2E%2Fclient_secret/reveal',
            'section traversal'    => '/admin/settings/sections/oauth%2Fsecrets%2Fclient_secret/secrets/client_secret/reveal',
            'padded section'       => '/admin/settings/sections/%20oauth/secrets/client_secret/reveal',
            'section newline'      => '/admin/settings/sections/oauth%0A/secrets/client_secret/reveal',
            'unknown section'      => '/admin/settings/sections/nope/secrets/client_secret/reveal',
            'reserved area name'   => '/admin/settings/sections/_reveal/secrets/client_secret/reveal',
            'long field'           => '/admin/settings/sections/oauth/secrets/' . str_repeat('a', 70) . '/reveal',
            'tab'                  => '/admin/settings/sections/oauth/secrets/client_secret%09/reveal',
            'nbsp'                 => '/admin/settings/sections/oauth/secrets/client_secret' . rawurlencode("\u{00A0}") . '/reveal',
            'zwsp'                 => '/admin/settings/sections/oauth/secrets/client_secret' . rawurlencode("\u{200B}") . '/reveal',
            'kelvin'               => '/admin/settings/sections/oauth/secrets/' . rawurlencode("\u{212A}") . 'lient_secret/reveal',
        ];
        $report = [];
        foreach ($cases as $label => $uri) {
            $client->request('POST', $uri, ['_token' => $token]);
            $response       = $client->getResponse();
            $body           = (string) $response->getContent();
            $report[$label] = $response->getStatusCode();
            self::assertStringNotContainsString(self::SECRET, $body, $label . ' leaked the plaintext');
        }
        // Not one of the 23 variants may be answered: a 200 here would mean a look-alike reached the declared field.
        self::assertNotContains(200, $report, 'battery statuses: ' . json_encode($report));
    }

    public function testNotStoredShapeOnlyReachableWithEveryCheck(): void
    {
        $kernel = $this->boot();
        $client = $this->client($kernel, 'developer');
        $token  = $this->token($client->request('GET', '/admin/settings/sections/oauth'));
        // revealable, nothing stored
        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('not_stored', (string) $client->getResponse()->getContent());
        // unknown field in a known section
        $client->request('POST', '/admin/settings/sections/oauth/secrets/nope/reveal', ['_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('not_stored', (string) $client->getResponse()->getContent());
        // a user who is not allowed never sees either shape
        $admin = $this->client($kernel, 'admin');
        $admin->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(403, $admin->getResponse()->getStatusCode());
        $admin->request('POST', '/admin/settings/sections/oauth/secrets/nope/reveal', ['_token' => $token]);
        self::assertSame(403, $admin->getResponse()->getStatusCode());
    }

    public function testANonTextSecretIsRefusedCleanlyAndAuditedAsRefused(): void
    {
        // Audit F2: a stored secret that is not UTF-8 text cannot be delivered as JSON. The answer is a clean 409 with
        // the no-store headers, no byte of the value, and the audit trail records a refusal instead of a reveal.
        $kernel = $this->boot();
        $binary = "binary-key-\xC3\x28-\xFF";
        $this->settings($kernel)->save('oauth', ['client_secret' => $binary]);
        $client = $this->client($kernel, 'developer');
        $token  = $this->token($client->request('GET', '/admin/settings/sections/oauth'));

        $client->request('POST', self::URL, ['_token' => $token]);
        $response = $client->getResponse();
        $body     = (string) $response->getContent();

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['key' => 'oauth.client_secret', 'error' => SecretRevealer::REASON_NOT_TEXT], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('binary-key-', $body, 'not even the readable part of the value');
        self::assertTrue($response->headers->getCacheControlDirective('no-store'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $listener = $this->container($kernel)->get('app.reveal_listener');
        self::assertInstanceOf(RevealAuditListener::class, $listener);
        self::assertSame([], $listener->events, 'nothing left, so nothing is audited as revealed');
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertStringContainsString(SecretRevealer::REASON_NOT_TEXT, $logger->dump());
        self::assertStringNotContainsString('binary-key-', $logger->dump());
    }

    public function testARevealDeniedByTheUiGateIsStillLoggedAsARevealRefusal(): void
    {
        // Audit F6: the UI gate is in front of the endpoint, so without this line an alert counting reveal refusals
        // per actor would miss the probe that cannot even open the UI.
        $kernel = $this->boot();
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client = $this->client($kernel, 'viewer');
        $client->request('POST', self::URL, ['_token' => 'whatever']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertStringContainsString(SecretRevealer::LOG_REFUSED, $logger->dump());
        self::assertStringContainsString(InstanceSettingsAccessListener::REASON_UI_GATE, $logger->dump());        // 1.15.1 (audit N2): the line names the account the gate refused, so the per-actor alert can group it.
        $refusal = null;
        foreach ($logger->records as $record) {
            if ($record['message'] === SecretRevealer::LOG_REFUSED) {
                $refusal = $record;
            }
        }
        self::assertNotNull($refusal);
        self::assertSame('warning', $refusal['level']);
        self::assertSame('viewer', $refusal['context']['actor']);
        self::assertSame('oauth.client_secret', $refusal['context']['key']);
    }

    public function testAnUnauthenticatedProbeCannotChooseTheNamesOrFloodTheWarnings(): void
    {
        // 1.15.1 (audit N3): undeclared names are logged as `unknown`, and past the limiter's budget the lines of
        // one caller drop to debug. Every call is still refused.
        $kernel = $this->boot(extensions: ['framework' => ['rate_limiter' => ['instance_settings_reveal' => ['policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 hour']]]]);
        for ($i = 0; $i < 4; ++$i) {
            $client = $this->client($kernel, null);
            $client->request('POST', '/admin/settings/sections/oauth/secrets/attacker_chosen/reveal', ['_token' => 'whatever']);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        }
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        $levels = [];
        foreach ($logger->records as $record) {
            if ($record['message'] === SecretRevealer::LOG_REFUSED) {
                $levels[] = $record['level'];
                // The bundle's line never carries the chosen name (the framework's own router line may, at info).
                self::assertSame(RevealRefusalLog::KEY_UNKNOWN, $record['context']['key']);
                self::assertStringNotContainsString('attacker_chosen', (string) json_encode($record['context']));
            }
        }
        self::assertSame(['warning', 'warning', 'debug', 'debug'], $levels);
    }

    public function testRevealerStatelessAcrossRequestsWithRotatedPermission(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $revealer = $this->container($kernel)->get(SecretRevealer::class);
        self::assertInstanceOf(SecretRevealer::class, $revealer);
        $client = $this->client($kernel, 'developer');
        $token  = $this->token($client->request('GET', '/admin/settings/sections/oauth'));
        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        // The same kernel, a different user: the earlier "yes" must not carry over.
        $admin = $this->client($kernel, 'admin');
        $admin->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(403, $admin->getResponse()->getStatusCode());
        $anon = $this->client($kernel, null);
        $anon->request('POST', self::URL, ['_token' => $token]);
        self::assertContains($anon->getResponse()->getStatusCode(), [401, 403]);
        self::assertStringNotContainsString(self::SECRET, (string) $anon->getResponse()->getContent());
    }
}
