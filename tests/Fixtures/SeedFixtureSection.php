<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SectionFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionNoticesInterface;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Section exposing env-like fallbacks and notices (what SmsKit / MailKit add in 1.2): host, port, secret token, switch.
 * Tests pass (or set) the fallbacks / notices on the instance (secrets generated at runtime).
 */
final class SeedFixtureSection extends AbstractSettingsSection implements SectionFallbackProviderInterface, SettingsSectionNoticesInterface
{
    /**
     * @param list<SettingFallback> $fallbacks
     * @param list<Label|string|TranslatableMessage> $notices
     */
    public function __construct(
        public array $fallbacks = [],
        public array $notices = [],
    ) {
    }

    public function getName(): string
    {
        return 'relay';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('host', null, 'relay.host');
        yield SettingField::int('port', 25, 'relay.port');
        yield SettingField::secret('token', 'relay.token');
        yield SettingField::bool('enabled', false, 'relay.enabled');
    }

    public function getFallbacks(): iterable
    {
        return $this->fallbacks;
    }

    public function getNotices(SectionActionContext $context): iterable
    {
        return $this->notices;
    }
}
