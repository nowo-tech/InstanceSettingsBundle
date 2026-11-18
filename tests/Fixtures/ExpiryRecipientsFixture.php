<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryRecipient;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryRecipientProviderInterface;

/**
 * 1.21 host recipient provider: a developer (English), a manager who cannot open the settings (Spanish), a person
 * without an address, the developer listed twice (two roles), and the roles it was asked for.
 */
final class ExpiryRecipientsFixture implements ExpiryRecipientProviderInterface
{
    /** @var list<list<string>> roles of every call */
    public array $calls = [];

    public function expiryRecipients(array $roles): iterable
    {
        $this->calls[] = $roles;
        yield new ExpiryRecipient('1', 'dev@example.test', 'en');
        yield new ExpiryRecipient('2', 'manager@example.test', 'es', canOpenSettings: false);
        yield new ExpiryRecipient('3');
        yield new ExpiryRecipient('1', 'dev@example.test', 'en');
    }
}
