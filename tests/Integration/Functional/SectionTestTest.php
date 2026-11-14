<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Repository\SettingRowReaderInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\SectionTest\LeakGuard;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestRunner;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStateRow;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStateStorageInterface;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStore;
use Nowo\InstanceSettingsBundle\SectionTest\TestStatus;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiringFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\CalendarOAuthFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\CalendarSectionTester;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\GoogleGroupTester;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\ProvidersSectionTester;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\SettingsTestedListener;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

use function array_replace_recursive;
use function hash;
use function json_decode;
use function json_encode;
use function preg_replace;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * 1.18 (Beacon spec 238, D-20261114-11): the Test button of a settings section, or of one field group of it, checks
 * that the STORED values connect; Save stays independent.
 */
final class SectionTestTest extends KernelTestCase
{
    /** Throwaway values, never real secrets. */
    private const GOOGLE_SECRET = 'throwawayzzfixtureone';

    private const MICROSOFT_SECRET = 'throwaway-zz-fixture-two';

    private const PAGE = '/admin/settings/sections/calendar_oauth';

    private const URL = '/admin/settings/sections/calendar_oauth/test';

    public function testTheSectionPageShowsTheSectionAndTheGroupButtonsWithTheirResultRegions(): void
    {
        $kernel  = $this->boot(['web_ui' => ['section_layout' => 'tabs']]);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', self::PAGE);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $root = $crawler->filter('[data-nowo-instance-settings-test-root]');
        self::assertCount(1, $root);
        self::assertSame('/admin/settings/sections/calendar_oauth/test', $root->attr('data-url'));
        self::assertNotSame('', (string) $root->attr('data-token'));
        self::assertNotNull($root->attr('hidden'), 'nothing is shown without the script');
        $buttons = $crawler->filter('[data-nowo-instance-settings-test-button]');
        self::assertCount(2, $buttons, 'the section tester and the Google group tester; no Microsoft tester');
        self::assertSame('', $buttons->eq(0)->attr('data-group'));
        self::assertSame('Test', trim($buttons->eq(0)->text()));
        self::assertSame('google', $buttons->eq(1)->attr('data-group'));
        self::assertSame('Test Google', trim($buttons->eq(1)->text()));
        self::assertSame('button', $buttons->eq(1)->attr('type'), 'never a submit: Save stays independent');
        $results = $crawler->filter('[data-nowo-instance-settings-test-result]');
        self::assertCount(2, $results);
        self::assertSame('polite', $results->eq(0)->attr('aria-live'));
        self::assertSame('status', $results->eq(0)->attr('role'));
        self::assertSame($results->eq(1)->attr('id'), $buttons->eq(1)->attr('aria-controls'));
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-test-dirty][hidden]'));
        self::assertSame('Test uses the stored values: save first to test your changes.', $crawler->filter('[data-nowo-instance-settings-test-dirty]')->text());
        self::assertCount(1, $crawler->filter('script[data-nowo-instance-settings-test-script]'));
        self::assertCount(1, $crawler->filter('link[data-nowo-instance-settings-styles]'));
        // The test token is the section's own CSRF token: the token of the section form is accepted as well.
        $client->request('POST', self::URL, ['_token' => (string) $crawler->filter('#settings_section__token')->attr('value')]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testASectionWithoutATesterAndAHostWithoutTestersAreUnchanged(): void
    {
        $with = $this->client($this->boot());
        $page = $with->request('GET', '/admin/settings/sections/mailer');
        self::assertCount(0, $page->filter('[data-nowo-instance-settings-test-root]'), 'mailer has no tester');
        self::assertCount(0, $page->filter('script[data-nowo-instance-settings-test-script]'));
        $withHtml = $this->normalize((string) $with->getResponse()->getContent());

        $kernel  = $this->bootKernel([], services: [CalendarOAuthFixtureSection::class => CalendarOAuthFixtureSection::class]);
        $without = $this->client($kernel);
        $without->request('GET', '/admin/settings/sections/mailer');
        self::assertSame($withHtml, $this->normalize((string) $without->getResponse()->getContent()), 'byte-identical page (CSRF token aside)');

        // No tester at all: the test route is not even mounted.
        $router = $this->container($kernel)->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertNull($router->getRouteCollection()->get(InstanceSettingsRoutes::TEST));
        $without->request('POST', '/admin/settings/sections/mailer/test');
        self::assertContains($without->getResponse()->getStatusCode(), [404, 405]);
    }

    public function testASectionTestRunsOnTheStoredValuesAndItsReportIsStoredAndShown(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('calendar_oauth', ['google_client_id' => 'client-1', 'google_client_secret' => self::GOOGLE_SECRET, 'microsoft_client_secret' => self::MICROSOFT_SECRET]);
        $client = $this->client($kernel);
        $token  = $this->token($client->request('GET', self::PAGE));

        $client->request('POST', self::URL, ['_token' => $token]);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->getCacheControlDirective('no-store'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $payload = $this->json($client);
        self::assertSame('calendar_oauth', $payload['section']);
        self::assertNull($payload['group']);
        self::assertSame('ok', $payload['status']);
        self::assertSame('Passed', $payload['statusLabel']);
        self::assertStringStartsWith('Last test: Passed (', (string) $payload['summary']);
        self::assertStringEndsWith(', admin)', (string) $payload['summary']);
        self::assertSame([
            ['status' => 'ok', 'statusLabel' => 'Passed', 'label' => 'Token endpoint', 'hint' => 'Fields mode,google_client_id,google_client_secret,microsoft_tenant,microsoft_client_secret of section'],
            ['status' => 'skipped', 'statusLabel' => 'Skipped', 'label' => 'Refresh token', 'hint' => null],
        ], $payload['checks']);

        // The event and the log line name the section, the status and the actor — never a value.
        $listener = $this->container($kernel)->get('app.tested_listener');
        self::assertInstanceOf(SettingsTestedListener::class, $listener);
        self::assertCount(1, $listener->events);
        self::assertSame('calendar_oauth', $listener->events[0]->section);
        self::assertNull($listener->events[0]->group);
        self::assertSame(TestStatus::Ok, $listener->events[0]->status);
        self::assertSame('admin', $listener->events[0]->actor);
        $logger = $this->logger($kernel);
        self::assertStringContainsString(SectionTestRunner::LOG_TESTED, $logger->dump());
        $this->assertNoSecret($logger->dump());

        // Shown under the button on the next page load; the stored values themselves never changed.
        $crawler = $client->request('GET', self::PAGE);
        $result  = $crawler->filter('[data-nowo-instance-settings-test-result]')->eq(0);
        self::assertSame('ok', $result->filter('.nowo-instance-settings-test__summary')->attr('data-status'));
        self::assertCount(2, $result->filter('li'));
        self::assertSame('ok', $result->filter('li')->eq(0)->attr('data-status'));
        self::assertSame('skipped', $result->filter('li')->eq(1)->attr('data-status'));
        self::assertSame('Token endpoint', $result->filter('.nowo-instance-settings-test__label')->eq(0)->text());
        self::assertSame('', trim($crawler->filter('[data-nowo-instance-settings-test-result]')->eq(1)->text()), 'the Google group was never tested');
        $this->assertNoSecret((string) $client->getResponse()->getContent());
        self::assertSame(self::GOOGLE_SECRET, $this->settings($kernel)->getSecret('calendar_oauth.google_client_secret'));
    }

    public function testAGroupTesterSeesItsGroupAndKeepsItsOwnLastReport(): void
    {
        $kernel = $this->boot(['web_ui' => ['section_layout' => 'tabs']]);
        $this->settings($kernel)->save('calendar_oauth', ['google_client_secret' => self::GOOGLE_SECRET]);
        $client = $this->client($kernel);
        $token  = $this->token($client->request('GET', self::PAGE));

        $client->request('POST', self::URL, ['_token' => $token, 'group' => 'google']);
        $payload = $this->json($client);
        self::assertSame('google', $payload['group']);
        self::assertIsArray($payload['checks']);
        self::assertIsArray($payload['checks'][0]);
        self::assertSame('Fields mode,google_client_id,google_client_secret of google', $payload['checks'][0]['hint'], 'the group tester gets the group\'s fields');

        $this->settings($kernel)->save('calendar_oauth', ['mode' => 'fail']);
        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame('fail', $this->json($client)['status']);

        $crawler = $client->request('GET', self::PAGE);
        $results = $crawler->filter('[data-nowo-instance-settings-test-result]');
        self::assertSame('fail', $results->eq(0)->filter('.nowo-instance-settings-test__summary')->attr('data-status'), 'the section report');
        self::assertSame('ok', $results->eq(1)->filter('.nowo-instance-settings-test__summary')->attr('data-status'), 'the Google report is its own');
        self::assertSame('HTTP status 401', $results->eq(0)->filter('.nowo-instance-settings-test__hint')->text());

        $listener = $this->container($kernel)->get('app.tested_listener');
        self::assertInstanceOf(SettingsTestedListener::class, $listener);
        self::assertSame(['google', null], [$listener->events[0]->group, $listener->events[1]->group]);
    }

    public function testAGroupOrSectionWithoutATesterIsNotFound(): void
    {
        $kernel = $this->boot();
        $client = $this->client($kernel);
        $token  = $this->token($client->request('GET', self::PAGE));

        foreach (['microsoft', 'nope', 'Google', 'google '] as $group) {
            $client->request('POST', self::URL, ['_token' => $token, 'group' => $group]);
            self::assertSame(404, $client->getResponse()->getStatusCode(), $group);
        }
        $client->request('POST', '/admin/settings/sections/mailer/test', ['_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('POST', '/admin/settings/sections/calendar_oauth2/test', ['_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('GET', self::URL);
        self::assertSame(405, $client->getResponse()->getStatusCode(), 'POST only');
    }

    public function testWithoutAValidCsrfTokenNothingRuns(): void
    {
        $kernel = $this->boot();
        $client = $this->client($kernel);
        $client->request('GET', self::PAGE);

        $client->request('POST', self::URL);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', self::URL, ['_token' => 'forged']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        // Another section's token is not this section's.
        $other = (string) $client->request('GET', '/admin/settings/sections/mailer')->filter('#settings_section__token')->attr('value');
        $client->request('POST', self::URL, ['_token' => $other]);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $listener = $this->container($kernel)->get('app.tested_listener');
        self::assertInstanceOf(SettingsTestedListener::class, $listener);
        self::assertSame([], $listener->events);
        self::assertSame([], $this->store($kernel)->lastReports('calendar_oauth'));
    }

    public function testTheSectionAreaRolesDecideByDefault(): void
    {
        $kernel = $this->boot(['security' => ['area_roles' => ['calendar_oauth' => ['ROLE_SUPER_ADMIN'], '_portability' => ['ROLE_SUPER_ADMIN']]]]);
        $super  = $this->client($kernel, 'super');
        $token  = $this->token($super->request('GET', self::PAGE));
        $super->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(200, $super->getResponse()->getStatusCode());

        $admin = $this->client($kernel, 'admin');
        $admin->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(403, $admin->getResponse()->getStatusCode(), 'no access to the section, no test');
        $viewer = $this->client($kernel, 'viewer');
        $viewer->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(403, $viewer->getResponse()->getStatusCode());
        $anonymous = $this->client($kernel, null);
        $anonymous->request('POST', self::URL, ['_token' => $token]);
        self::assertContains($anonymous->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testTestRolesNarrowWhoMayTest(): void
    {
        $kernel  = $this->boot(['security' => ['test_roles' => ['ROLE_SUPER_ADMIN']]]);
        $admin   = $this->client($kernel, 'admin');
        $crawler = $admin->request('GET', self::PAGE);
        self::assertSame(200, $admin->getResponse()->getStatusCode(), 'the admin still opens and saves the section');
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-test-root]'), 'but sees no Test button');
        $token = (string) $crawler->filter('#settings_section__token')->attr('value');
        $admin->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(403, $admin->getResponse()->getStatusCode(), 'and the endpoint refuses');

        $super = $this->client($kernel, 'super');
        $super->request('POST', self::URL, ['_token' => $this->token($super->request('GET', self::PAGE))]);
        self::assertSame(200, $super->getResponse()->getStatusCode());

        $off = $this->client($this->boot(['security' => ['test_roles' => []]]), 'super');
        self::assertCount(0, $off->request('GET', self::PAGE)->filter('[data-nowo-instance-settings-test-root]'), 'test_roles: [] switches every test off');
    }

    public function testTheBuiltInRateLimitRefusesTheEleventhTestOfAnActor(): void
    {
        // A frozen clock: the fixed window never ends in the middle of the test.
        $kernel = $this->boot(services: ['clock' => MockClock::class]);
        $client = $this->client($kernel);
        $token  = $this->token($client->request('GET', self::PAGE));
        for ($i = 0; $i < SectionTestRunner::DEFAULT_LIMIT; ++$i) {
            $client->request('POST', self::URL, ['_token' => $token, 'group' => $i % 2 === 0 ? 'google' : '']);
            self::assertSame(200, $client->getResponse()->getStatusCode(), 'test ' . $i);
        }

        $client->request('POST', self::URL, ['_token' => $token]);
        $response = $client->getResponse();
        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($response->headers->getCacheControlDirective('no-store'));
        self::assertNotNull($response->headers->get('Retry-After'));
        self::assertSame(['error' => 'rate_limited', 'message' => 'Too many tests. Try again in a few minutes.'], $this->json($client));
        self::assertStringContainsString(SectionTestRunner::REASON_RATE_LIMITED, $this->logger($kernel)->dump());

        // Another actor has its own window.
        $super = $this->client($kernel, 'super');
        $super->request('POST', self::URL, ['_token' => $this->token($super->request('GET', self::PAGE))]);
        self::assertSame(200, $super->getResponse()->getStatusCode());
    }

    public function testAConfiguredRateLimiterReplacesTheBuiltInOne(): void
    {
        $kernel = $this->boot(
            ['security' => ['test_rate_limiter' => 'limiter.instance_settings_test']],
            ['framework' => ['rate_limiter' => ['instance_settings_test' => ['policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour']]]],
        );
        $client = $this->client($kernel);
        $token  = $this->token($client->request('GET', self::PAGE));
        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->rows($kernel, SectionTestStore::RATES), 'no built-in window with a configured limiter');
    }

    public function testATestAlreadyRunningForTheActorAndSectionIsRefused(): void
    {
        $kernel = $this->boot();
        $client = $this->client($kernel);
        $token  = $this->token($client->request('GET', self::PAGE));
        // What a concurrent request of the same actor leaves while its tester runs.
        $actorKey = hash('sha256', 'test-secret' . "\2" . 'section_test' . "\0" . 'admin');
        $marker   = $this->store($kernel)->acquire($actorKey, 'calendar_oauth', new DateTimeImmutable(), SectionTestRunner::STALE_AFTER);
        self::assertInstanceOf(SectionTestStateRow::class, $marker);

        $client->request('POST', self::URL, ['_token' => $token, 'group' => 'google']);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame(['error' => 'busy', 'message' => 'A test of this section is already running.'], $this->json($client));

        // Another actor, or the same actor once the first test ended, may test.
        $super = $this->client($kernel, 'super');
        $super->request('POST', self::URL, ['_token' => $this->token($super->request('GET', self::PAGE))]);
        self::assertSame(200, $super->getResponse()->getStatusCode());
        $this->store($kernel)->release($marker);
        $client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->rows($kernel, SectionTestStore::LOCKS), 'the marker is removed after every test');
    }

    public function testATesterThatThrowsFailsWithAGenericHintAndALogLineWithoutTheSecret(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('calendar_oauth', ['mode' => 'throw', 'google_client_secret' => self::GOOGLE_SECRET]);
        $client = $this->client($kernel);
        $client->request('POST', self::URL, ['_token' => $this->token($client->request('GET', self::PAGE)), 'group' => 'google']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $payload = $this->json($client);
        self::assertSame('fail', $payload['status']);
        self::assertSame([['status' => 'fail', 'statusLabel' => 'Failed', 'label' => 'Test', 'hint' => 'The test could not complete. The details are in the server log.']], $payload['checks']);
        $this->assertNoSecret((string) $client->getResponse()->getContent());

        $logger = $this->logger($kernel);
        self::assertStringContainsString(SectionTestRunner::LOG_TESTER_FAILED, $logger->dump());
        self::assertStringContainsString('RuntimeException', $logger->dump());
        self::assertStringContainsString(LeakGuard::REDACTED, $logger->dump());
        $this->assertNoSecret($logger->dump());
        $this->assertNoSecret((string) json_encode($this->rows($kernel, SectionTestStore::REPORTS)));
    }

    public function testFreeTextInsteadOfATranslationKeyFailsLikeAThrowingTester(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('calendar_oauth', ['mode' => 'free_text']);
        $client = $this->client($kernel);
        $client->request('POST', self::URL, ['_token' => $this->token($client->request('GET', self::PAGE))]);

        $payload = $this->json($client);
        self::assertSame('fail', $payload['status']);
        self::assertStringNotContainsString('The token endpoint answered fine', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('InvalidArgumentException', $this->logger($kernel)->dump());
    }

    public function testTheLeakGuardRedactsEverySecretBeforeItIsShownStoredOrLogged(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('calendar_oauth', ['mode' => 'leak', 'google_client_secret' => self::GOOGLE_SECRET, 'microsoft_client_secret' => self::MICROSOFT_SECRET]);
        $client = $this->client($kernel);
        $client->request('POST', self::URL, ['_token' => $this->token($client->request('GET', self::PAGE)), 'group' => 'google']);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $payload = $this->json($client);
        self::assertSame('fail', $payload['status']);
        self::assertIsArray($payload['checks']);
        self::assertSame([
            ['status' => 'fail', 'statusLabel' => 'Failed', 'label' => 'Token endpoint', 'hint' => 'exact=[redacted] part=[redacted] safe=oauth2.googleapis.com code=401'],
            ['status' => 'fail', 'statusLabel' => 'Failed', 'label' => 'Check', 'hint' => 'Details were hidden because they matched a stored secret.'],
            ['status' => 'ok', 'statusLabel' => 'Passed', 'label' => 'Calendar scope', 'hint' => 'HTTP status 1'],
        ], $payload['checks']);
        $this->assertNoSecret((string) $client->getResponse()->getContent());

        // The stored last report never holds a value: neither the secret nor a slice of it.
        $stored = (string) json_encode($this->rows($kernel, SectionTestStore::REPORTS));
        $this->assertNoSecret($stored);
        self::assertStringNotContainsString(substr(self::GOOGLE_SECRET, 3, 7), $stored);
        $page = $client->request('GET', self::PAGE)->html();
        $this->assertNoSecret($page);

        $logger = $this->logger($kernel);
        self::assertStringContainsString(SectionTestRunner::LOG_REDACTED, $logger->dump());
        $this->assertNoSecret($logger->dump());
    }

    public function testATesterOverItsTimeBudgetIsLogged(): void
    {
        $kernel = $this->boot(services: ['clock' => MockClock::class]);
        $this->settings($kernel)->save('calendar_oauth', ['mode' => 'slow']);
        $client = $this->client($kernel);
        $client->request('POST', self::URL, ['_token' => $this->token($client->request('GET', self::PAGE))]);

        self::assertSame('ok', $this->json($client)['status'], 'the bundle cannot stop host code; it logs it');
        self::assertStringContainsString(SectionTestRunner::LOG_OVER_BUDGET, $this->logger($kernel)->dump());
    }

    public function testTheTestStateIsNeverExported(): void
    {
        $kernel = $this->boot();
        $client = $this->client($kernel);
        $client->request('POST', self::URL, ['_token' => $this->token($client->request('GET', self::PAGE))]);
        self::assertNotSame([], $this->rows($kernel, SectionTestStore::REPORTS));

        $portability = $this->container($kernel)->get(ConfigPortability::class);
        self::assertInstanceOf(ConfigPortability::class, $portability);
        $export = (string) json_encode($portability->export());
        self::assertStringNotContainsString('_section_test', $export);
    }

    public function testTheTestRowsStayOutOfTheSettingsReadsAndTheStorageIsConditional(): void
    {
        // Audit PU-L6: the reserved rows are never loaded with the settings, nor listed by the raw row reader.
        $kernel = $this->boot();
        $client = $this->client($kernel);
        $client->request('POST', self::URL, ['_token' => $this->token($client->request('GET', self::PAGE))]);
        self::assertNotSame([], $this->rows($kernel, SectionTestStore::REPORTS));
        self::assertNotSame([], $this->rows($kernel, SectionTestStore::RATES));

        $reader = $this->container($kernel)->get(SettingRowReaderInterface::class);
        self::assertInstanceOf(SettingRowReaderInterface::class, $reader);
        foreach ($reader->findAllRows() as $row) {
            self::assertStringStartsNotWith('_section_test', $row->section);
        }
        $repository = $this->container($kernel)->get(SettingValueRepositoryInterface::class);
        self::assertInstanceOf(SettingValueRepositoryInterface::class, $repository);
        foreach ($repository->findAll() as $row) {
            self::assertStringStartsNotWith('_section_test', $row->getSection());
        }

        // Audit PU-L2 / L3: a row changed or removed since it was read is never updated nor deleted.
        $storage = $this->container($kernel)->get(SectionTestStateStorageInterface::class);
        self::assertInstanceOf(SectionTestStateStorageInterface::class, $storage);
        $at  = new DateTimeImmutable('2026-11-14 10:00:00');
        $row = $storage->insert(SectionTestStore::LOCKS, 'probe', 'token-a', $at);
        self::assertInstanceOf(SectionTestStateRow::class, $row);
        self::assertNull($storage->insert(SectionTestStore::LOCKS, 'probe', 'token-b', $at), 'unique (section, name)');
        self::assertTrue($storage->update($row, 'token-c', $at->modify('+1 second')));
        self::assertFalse($storage->update($row, 'token-d', $at->modify('+2 seconds')), 'the row changed since it was read');
        self::assertFalse($storage->delete($row));
        $current = $storage->rows(SectionTestStore::LOCKS)[0];
        self::assertSame('token-c', $current->value);
        self::assertTrue($storage->delete($current));
        self::assertFalse($storage->delete($current), 'already gone');
        self::assertSame([], $storage->rows(SectionTestStore::LOCKS));
    }

    public function testSavingNeverWaitsForOrDependsOnATest(): void
    {
        $kernel = $this->boot();
        $this->settings($kernel)->save('calendar_oauth', ['mode' => 'throw']);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', self::PAGE);
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        $client->submit($form, ['settings_section[google_client_id]' => 'client-2']);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('client-2', $this->settings($kernel)->get('calendar_oauth.google_client_id'));
        $listener = $this->container($kernel)->get('app.tested_listener');
        self::assertInstanceOf(SettingsTestedListener::class, $listener);
        self::assertSame([], $listener->events, 'a save runs no test');
    }

    public function testTheTestButtonSharesThePageWithLockedAndExpiringSecrets(): void
    {
        // 1.17 locked stored secrets (reveal_in_input) and expiring secrets on the same section page as the Test button.
        $kernel = $this->bootKernel(
            ['web_ui' => ['reveal_on_toggle' => true, 'reveal_in_input' => true], 'security' => ['reveal_rate_limiter' => 'limiter.instance_settings_reveal'], 'expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC']],
            services: [ExpiringFixtureSection::class => ExpiringFixtureSection::class, ProvidersSectionTester::class => ProvidersSectionTester::class, 'logger' => RecordingLogger::class],
            extensions: ['framework' => ['rate_limiter' => ['instance_settings_reveal' => ['policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 hour']]]],
        );
        $this->settings($kernel)->save('providers', ['api_key' => self::GOOGLE_SECRET, 'api_key:expires_on' => (new DateTimeImmutable('today'))->modify('+5 days'), 'webhook_token' => self::MICROSOFT_SECRET]);
        $this->settings($kernel)->reset();
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/providers');
        $html    = (string) $client->getResponse()->getContent();

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-test-root]'));
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-locked="api_key"]'), 'the stored secret row stays locked');
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-locked="webhook_token"]'));
        self::assertStringContainsString('name="settings_section[api_key:expires_on]"', $html);
        self::assertStringContainsString('data-nowo-instance-settings-expiry="expiring"', $html);
        self::assertCount(1, $crawler->filter('link[data-nowo-instance-settings-styles]'), 'one stylesheet for every widget');
        $this->assertNoSecret($html);

        $client->request('POST', '/admin/settings/sections/providers/test', ['_token' => $this->token($crawler)]);
        self::assertSame('ok', $this->json($client)['status']);
        $this->assertNoSecret((string) $client->getResponse()->getContent());

        // Saving the untouched form still works next to the Test markup, and the test changed nothing stored.
        $client->submit($crawler->filter('form[name="settings_section"]')->form());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertSame(self::GOOGLE_SECRET, $this->settings($kernel)->getSecret('providers.api_key'));
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, array<string, mixed>> $extensions
     * @param array<string, string> $services
     */
    private function boot(array $config = [], array $extensions = [], array $services = []): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            array_replace_recursive([], $config),
            services: [
                CalendarOAuthFixtureSection::class => CalendarOAuthFixtureSection::class,
                CalendarSectionTester::class       => CalendarSectionTester::class,
                GoogleGroupTester::class           => GoogleGroupTester::class,
                'app.tested_listener'              => SettingsTestedListener::class,
                'logger'                           => RecordingLogger::class,
            ] + $services,
            extensions: $extensions,
        );
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('[data-nowo-instance-settings-test-root]')->attr('data-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array<string, mixed> $payload */
        return $payload;
    }

    private function logger(InstanceSettingsTestKernel $kernel): RecordingLogger
    {
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    private function store(InstanceSettingsTestKernel $kernel): SectionTestStore
    {
        $store = $this->container($kernel)->get(SectionTestStore::class);
        self::assertInstanceOf(SectionTestStore::class, $store);

        return $store;
    }

    /**
     * Raw rows of a reserved section, straight from the database.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(InstanceSettingsTestKernel $kernel, string $section): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection($kernel)->fetchAllAssociative('SELECT * FROM ' . SettingValue::TABLE . ' WHERE section = ?', [$section]);

        return $rows;
    }

    private function assertNoSecret(string $text): void
    {
        foreach ([self::GOOGLE_SECRET, self::MICROSOFT_SECRET] as $secret) {
            self::assertStringNotContainsString($secret, $text);
            self::assertFalse(LeakGuard::hits($text, [$secret]), 'no slice of a stored secret');
        }
    }

    private function normalize(string $html): string
    {
        return (string) preg_replace('/(value|data-token)="[^"]{20,}"/', '$1="TOKEN"', $html);
    }
}
