<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Convenience base of a settings section: label `<name>.label`, intro `<name>.intro` off, domain `messages`, no icon.
 * Override what you need; {@see self::getName()} and {@see self::getFields()} remain abstract.
 */
abstract class AbstractSettingsSection implements SettingsSectionInterface
{
    /**
     * Translation key `<name>.label`.
     */
    public function getLabel(): string
    {
        return $this->getName() . '.label';
    }

    /**
     * The application `messages` domain.
     */
    public function getTranslationDomain(): string
    {
        return 'messages';
    }

    /**
     * No intro.
     */
    public function getIntro(): Label|TranslatableMessage|string|null
    {
        return null;
    }

    /**
     * No icon.
     */
    public function getIcon(): ?string
    {
        return null;
    }
}
