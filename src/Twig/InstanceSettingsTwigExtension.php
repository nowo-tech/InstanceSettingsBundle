<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Twig;

use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `instance_setting('<section>.<field>')`: effective value of a plain setting in templates. Secrets are refused
 * (LogicException), so a template can never print one. `instance_setting_file('<section>.<field>')` (1.4): the stored
 * image of an image field ({@see StoredSettingFile}: `url()`, `url(192)`, `width`…)
 * or null.
 */
final class InstanceSettingsTwigExtension extends AbstractExtension
{
    /**
     * @param InstanceSettingsInterface $settings settings reader
     */
    public function __construct(
        private readonly InstanceSettingsInterface $settings,
    ) {
    }

    /**
     * Registers `instance_setting()` and `instance_setting_file()`.
     *
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('instance_setting', $this->settings->get(...)),
            new TwigFunction('instance_setting_file', $this->settings->getFile(...)),
        ];
    }
}
