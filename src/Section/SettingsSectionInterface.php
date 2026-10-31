<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

/**
 * A group of instance settings contributed by the host or by another bundle (mailer, SMS provider, Mercure hub, …).
 *
 * Every service implementing this interface is tagged `nowo_instance_settings.section` (autoconfiguration) and gets
 * its own page in the admin UI, its own entry in exports and typed getters (`<name>.<field>`) in
 * {@see InstanceSettingsInterface}. Implementations must be stateless (worker-safe):
 * extend {@see AbstractSettingsSection} for the defaults.
 */
interface SettingsSectionInterface
{
    /**
     * Stable section id (lower snake case, unique): key prefix, URL segment and export key. Never rename it once
     * published: stored values are keyed by it.
     */
    public function getName(): string;

    /**
     * Translation key of the section title (tab label).
     */
    public function getLabel(): string;

    /**
     * Translation domain of the section label, intro and field labels / help.
     */
    public function getTranslationDomain(): string;

    /**
     * Translation key of the optional intro shown under the section title.
     */
    public function getIntro(): ?string;

    /**
     * Optional UiKit icon kind / name of the section tab.
     */
    public function getIcon(): ?string;

    /**
     * Fields of the section, in form order.
     *
     * @return iterable<SettingField>
     */
    public function getFields(): iterable;
}
