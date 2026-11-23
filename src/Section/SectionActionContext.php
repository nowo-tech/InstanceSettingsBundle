<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Symfony\Component\HttpFoundation\Request;

/**
 * Context handed to {@see SettingsSectionActionsInterface::getActions()}: the section being rendered, the current
 * request and the authenticated user (null when anonymous or without a firewall).
 */
final readonly class SectionActionContext
{
    /**
     * @param string $section section name
     * @param Request $request current request
     * @param object|null $user authenticated user of the main request firewall
     */
    public function __construct(
        public string $section,
        // @igor-ignore - Per-call value object built with new by SettingsSectionEmbedder for one section action; never a shared service, so it cannot keep a request across worker requests.
        public Request $request,
        public ?object $user = null,
    ) {
    }
}
