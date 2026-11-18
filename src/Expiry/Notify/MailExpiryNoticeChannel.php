<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function abs;
use function preg_replace;
use function strtr;

/**
 * Mail channel of the default expiry notifier (1.21, KIT-88): one e-mail per recipient with an address, rendered from
 * `notify.mail_template` + `.html.twig` / `.txt.twig` (default `@NowoInstanceSettingsBundle/expiry/notice`, overridable
 * like every bundle template) in the recipient's locale, and sent through the application `MailerInterface`. With
 * nowo-tech/mail-kit-bundle installed that is MailKit's mailer: its runtime DSN, sender, brand and sent-mail log.
 *
 * The template gets the translated setting label, the date, the days left and the link, never the secret (the event
 * holds none). Stateless: the locale is passed to every translation, never set on a shared translator.
 */
final readonly class MailExpiryNoticeChannel implements ExpiryNoticeChannelInterface
{
    /** Channel name (sent-marker key). */
    public const NAME = 'mail';

    /**
     * @param string $template template path without the `.html.twig` / `.txt.twig` suffix
     * @param TranslatorInterface|null $translator null = the keys with their parameters (no translator)
     * @param string|null $from `From` address; null = the mailer's (MailKit fills it from its settings)
     */
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private ExpiryNoticeLinks $links,
        private string $template,
        private ?TranslatorInterface $translator = null,
        private ?string $from = null,
    ) {
    }

    /**
     * `mail`.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Recipients with an address.
     */
    public function supports(ExpiryRecipient $recipient): bool
    {
        return $recipient->email !== null;
    }

    /**
     * Renders and sends the e-mail.
     *
     * @throws PermanentExpiryNoticeFailureException when the mailer refuses the address (RFC)
     */
    public function send(SecretExpiryThresholdReachedEvent $event, ExpiryRecipient $recipient): void
    {
        $locale     = $recipient->locale;
        $credential = $this->trans($event->label, [], $event->translationDomain, $locale);
        $context    = [
            'credential'         => $credential,
            'expires_on'         => $event->expiresOn,
            'days_left'          => $event->daysLeft,
            'days_ago'           => abs($event->daysLeft),
            'expired'            => $event->isExpired(),
            'threshold'          => $event->threshold,
            'link'               => $this->links->link($event, $recipient, true),
            'locale'             => $locale,
            'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
        ];
        $subject = $this->trans($event->isExpired() ? 'secret_expiry_notice.subject_expired' : 'secret_expiry_notice.subject_expiring', [
            '%credential%' => $credential,
            '%date%'       => $event->expiresOn,
            '%days%'       => (string) $event->daysLeft,
        ], NowoInstanceSettingsBundle::TRANSLATION_DOMAIN, $locale);

        try {
            $this->mailer->send($this->message(
                (string) $recipient->email,
                // A label is host text: no line breaks in a header.
                (string) preg_replace('/[\r\n\t]+/', ' ', $subject),
                $this->twig->render($this->template . '.html.twig', $context),
                $this->twig->render($this->template . '.txt.twig', $context),
                $this->from,
            ));
        } catch (RfcComplianceException $e) {
            // An address the mailer refuses: no retry fixes it (the notifier keeps the marker and logs it).
            throw new PermanentExpiryNoticeFailureException('The reminder e-mail address is refused by the mailer (RFC).', 0, $e);
        }
    }

    /**
     * A new e-mail (never a shared one): auto-generated, optionally with the configured sender.
     */
    private function message(string $to, string $subject, string $html, string $text, ?string $from): Email
    {
        $headers = new Headers();
        $headers->addTextHeader('Auto-Submitted', 'auto-generated');
        if ($from !== null) {
            $headers->addMailboxListHeader('From', [Address::create($from)]);
        }

        return (new Email($headers))->to(new Address($to))->subject($subject)->html($html)->text($text);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters, string $domain, ?string $locale): string
    {
        return $this->translator instanceof TranslatorInterface ? $this->translator->trans($key, $parameters, $domain, $locale) : strtr($key, $parameters);
    }
}
