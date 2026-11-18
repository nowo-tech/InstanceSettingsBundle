<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Mailer keeping the sent e-mails (1.21 expiry notifier tests).
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        TestCase::assertInstanceOf(Email::class, $message);
        $this->sent[] = $message;
    }

    public function only(): Email
    {
        TestCase::assertCount(1, $this->sent);

        return $this->sent[0];
    }
}
