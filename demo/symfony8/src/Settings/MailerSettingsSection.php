<?php

declare(strict_types=1);

namespace App\Settings;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What a mailer bundle contributes: sender, encrypted transport DSN, test recipient, plus an extra action.
 */
#[AsSettingsSection(priority: 20)]
final class MailerSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getName(): string
    {
        return 'mailer';
    }

    public function getIntro(): ?string
    {
        return 'mailer.intro';
    }

    public function getFields(): iterable
    {
        yield SettingField::email('from', 'noreply@example.test', 'mailer.from', required: true);
        yield SettingField::string('from_name', 'Acme Ops', 'mailer.from_name');
        yield SettingField::secret('dsn', 'mailer.dsn', 'mailer.dsn_help');
        yield SettingField::email('test_recipient', null, 'mailer.test_recipient');
    }

    public function getActions(): iterable
    {
        yield Action::view($this->urlGenerator->generate('demo_home'), new Label('mailer.preview', 'messages'));
    }
}
