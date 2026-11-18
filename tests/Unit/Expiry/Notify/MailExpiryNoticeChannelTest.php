<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Expiry\Notify;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeLinks;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryRecipient;
use Nowo\InstanceSettingsBundle\Expiry\Notify\MailExpiryNoticeChannel;
use Nowo\InstanceSettingsBundle\Expiry\Notify\PermanentExpiryNoticeFailureException;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function dirname;
use function glob;
use function preg_match;

/**
 * 1.21 (KIT-88): the reminder e-mail — both templates in every shipped locale, the expiring / today / expired texts,
 * the link per recipient, the escaped label, the optional From, and never the secret.
 */
final class MailExpiryNoticeChannelTest extends TestCase
{
    /** A label that tries to smuggle markup and a header into the mail. */
    private const LABEL = "<img src=x onerror=alert(1)> API key\r\nBcc: x@evil.test";

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function locales(): iterable
    {
        yield 'en' => ['en', 'A stored credential expires soon', 'Open the settings'];
        yield 'es' => ['es', 'Una credencial guardada caduca pronto', 'Abrir la configuración'];
        yield 'de' => ['de', 'Ein gespeicherter Zugangsschlüssel läuft bald ab', 'Einstellungen öffnen'];
        yield 'fr' => ['fr', 'Un identifiant enregistré expire bientôt', 'Ouvrir les paramètres'];
        yield 'it' => ['it', 'Una credenziale salvata scade a breve', 'Apri le impostazioni'];
        yield 'nl' => ['nl', 'Een opgeslagen toegangsgegeven verloopt binnenkort', 'Instellingen openen'];
        yield 'pt' => ['pt', 'Uma credencial guardada expira em breve', 'Abrir as definições'];
    }

    #[DataProvider('locales')]
    public function testEveryShippedLocaleRendersBothParts(string $locale, string $heading, string $action): void
    {
        $mailer = $this->mailer();
        $this->channel($mailer)->send($this->event(7), new ExpiryRecipient('1', 'dev@example.test', $locale, true));

        $email = $mailer->only();
        self::assertStringContainsString($heading, (string) $email->getHtmlBody());
        self::assertStringContainsString($heading, (string) $email->getTextBody());
        self::assertStringContainsString($action, (string) $email->getHtmlBody());
        self::assertStringContainsString('2026-12-31', (string) $email->getSubject());
        self::assertStringContainsString('<html lang="' . $locale . '">', (string) $email->getHtmlBody());
        self::assertStringNotContainsString('secret_expiry_notice.', $email->getHtmlBody() . $email->getTextBody() . $email->getSubject(), 'every key is translated');
    }

    public function testExpiringTodayAndExpiredTexts(): void
    {
        $mailer    = $this->mailer();
        $channel   = $this->channel($mailer);
        $recipient = new ExpiryRecipient('1', 'dev@example.test', 'en', true);
        $channel->send($this->event(30), $recipient);
        $channel->send($this->event(0), $recipient);
        $channel->send(new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 0, -3), $recipient);

        [$expiring, $today, $expired] = $mailer->sent;
        self::assertStringContainsString('expires on 2026-12-31 (in 30 day(s))', (string) $expiring->getTextBody());
        self::assertSame('<img src=x onerror=alert(1)> API key Bcc: x@evil.test expires on 2026-12-31', $expiring->getSubject(), 'no line break survives in the subject');
        self::assertStringContainsString('expires today (2026-12-31)', (string) $today->getTextBody());
        self::assertStringContainsString('expired on 2026-12-31 (3 day(s) ago)', (string) $expired->getTextBody());
        self::assertStringContainsString('A stored credential has expired', (string) $expired->getHtmlBody());
        self::assertSame('<img src=x onerror=alert(1)> API key Bcc: x@evil.test expired on 2026-12-31', $expired->getSubject());
        self::assertSame('auto-generated', $expired->getHeaders()->getHeaderBody('Auto-Submitted'));
        self::assertFalse($expired->getHeaders()->has('Bcc'));
        self::assertSame([], $expired->getFrom(), 'MailKit (or the mailer) sets the sender');
    }

    public function testTheLabelIsEscapedInTheHtmlPart(): void
    {
        $mailer = $this->mailer();
        $this->channel($mailer)->send($this->event(7), new ExpiryRecipient('1', 'dev@example.test', 'en', true));
        $html = (string) $mailer->only()->getHtmlBody();
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; API key', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertSame(1, preg_match('#<a href="https://beacon\.example\.test/admin/settings/sections/providers"#', $html));
        self::assertStringContainsString('Open the settings: https://beacon.example.test/admin/settings/sections/providers', (string) $mailer->only()->getTextBody());
    }

    public function testARecipientWhoCannotOpenTheSettingsGetsTheFallbackLinkOrNone(): void
    {
        $mailer = $this->mailer();
        $this->channel($mailer)->send($this->event(7), new ExpiryRecipient('2', 'manager@example.test', 'es'));
        self::assertStringContainsString('href="https://beacon.example.test/"', (string) $mailer->only()->getHtmlBody());

        $mailer = $this->mailer();
        $this->channel($mailer, fallbackRoute: null)->send($this->event(7), new ExpiryRecipient('2', 'manager@example.test', 'es'));
        self::assertStringNotContainsString('<a ', (string) $mailer->only()->getHtmlBody());
        self::assertStringNotContainsString('https://', (string) $mailer->only()->getTextBody());
        self::assertStringContainsString('Recibes este aviso', (string) $mailer->only()->getTextBody());
    }

    public function testTheConfiguredFromAndOnlyRecipientsWithAnAddress(): void
    {
        $mailer  = $this->mailer();
        $channel = $this->channel($mailer, from: 'Beacon <noreply@beacon.example.test>');
        self::assertSame('mail', $channel->name());
        self::assertTrue($channel->supports(new ExpiryRecipient('1', 'dev@example.test')));
        self::assertFalse($channel->supports(new ExpiryRecipient('3')));
        $channel->send($this->event(1), new ExpiryRecipient('1', 'dev@example.test'));
        self::assertSame('noreply@beacon.example.test', $mailer->only()->getFrom()[0]->getAddress());
        self::assertSame('Beacon', $mailer->only()->getFrom()[0]->getName());
        self::assertSame('dev@example.test', $mailer->only()->getTo()[0]->getAddress());
    }

    public function testAnAddressTheMailerRefusesIsAPermanentFailure(): void
    {
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new RfcComplianceException('Email "x" does not comply with addr-spec of RFC 2822.');
            }
        };
        try {
            $this->channel($mailer)->send($this->event(7), new ExpiryRecipient('1', 'dev@example.test'));
            self::fail('Expected a permanent failure');
        } catch (PermanentExpiryNoticeFailureException $e) {
            self::assertInstanceOf(RfcComplianceException::class, $e->getPrevious());
            self::assertStringNotContainsString('dev@example.test', $e->getMessage());
        }
    }

    public function testWithoutATranslatorTheKeysAreSent(): void
    {
        $mailer = $this->mailer();
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 4) . '/src/Resources/views', 'NowoInstanceSettingsBundle');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'name']);
        $twig->addExtension(new TranslationExtension());
        (new MailExpiryNoticeChannel($mailer, $twig, $this->links(), '@NowoInstanceSettingsBundle/expiry/notice'))->send($this->event(7), new ExpiryRecipient('1', 'dev@example.test'));
        self::assertSame('secret_expiry_notice.subject_expiring', $mailer->only()->getSubject());
    }

    private function event(int $daysLeft): SecretExpiryThresholdReachedEvent
    {
        return new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', $daysLeft === 30 ? 30 : ($daysLeft >= 7 ? 7 : $daysLeft), $daysLeft);
    }

    private function channel(MailerInterface $mailer, ?string $from = null, ?string $fallbackRoute = 'home'): MailExpiryNoticeChannel
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addLoader('array', new ArrayLoader());
        foreach (glob(dirname(__DIR__, 4) . '/src/Resources/translations/NowoInstanceSettingsBundle.*.yaml') ?: [] as $file) {
            $translator->addResource('yaml', $file, (string) preg_replace('/^.*\.([a-z]{2})\.yaml$/', '$1', $file), 'NowoInstanceSettingsBundle');
        }
        $translator->addResource('array', ['providers.api_key' => self::LABEL], 'en', 'app');

        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 4) . '/src/Resources/views', 'NowoInstanceSettingsBundle');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'name']);
        $twig->addExtension(new TranslationExtension($translator));

        return new MailExpiryNoticeChannel($mailer, $twig, $this->links($fallbackRoute), '@NowoInstanceSettingsBundle/expiry/notice', $translator, $from);
    }

    private function links(?string $fallbackRoute = 'home'): ExpiryNoticeLinks
    {
        $routes = new RouteCollection();
        $routes->add(InstanceSettingsRoutes::SECTION, new Route('/admin/settings/sections/{section}'));
        $routes->add('home', new Route('/'));

        return new ExpiryNoticeLinks(new UrlGenerator($routes, new RequestContext(host: 'beacon.example.test', scheme: 'https')), InstanceSettingsRoutes::SECTION, $fallbackRoute);
    }

    private function mailer(): RecordingMailer
    {
        return new RecordingMailer();
    }
}
