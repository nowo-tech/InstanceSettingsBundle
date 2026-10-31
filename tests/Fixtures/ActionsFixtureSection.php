<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\UiKitBundle\View\Action;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Section with an extra action that depends on its context (what an SMS kit adds: "Send a test SMS").
 */
final class ActionsFixtureSection extends AbstractSettingsSection implements SettingsSectionActionsInterface
{
    public function getName(): string
    {
        return 'sms';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::url('bridge_url', null, 'general.homepage', constraints: [new Regex(pattern: '#^https://#', message: 'HTTPS only.')]);
    }

    public function getActions(SectionActionContext $context): iterable
    {
        $user = $context->user instanceof UserInterface ? $context->user->getUserIdentifier() : 'anonymous';

        yield Action::view('/test-sms?section=' . $context->section . '&from=' . $context->request->getPathInfo(), 'Test as ' . $user);
    }
}
