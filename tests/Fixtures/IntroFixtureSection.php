<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Section whose intro is given (parameterised intros).
 */
final class IntroFixtureSection extends AbstractSettingsSection
{
    public function __construct(
        private readonly string $name,
        private readonly Label|TranslatableMessage|string|null $intro,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getIntro(): Label|TranslatableMessage|string|null
    {
        return $this->intro;
    }

    public function getFields(): iterable
    {
        return [];
    }
}
