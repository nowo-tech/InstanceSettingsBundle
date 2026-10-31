<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Nowo\UiKitBundle\View\Action;

/**
 * Optional interface of a settings section that adds actions next to the section submit button (for example
 * "Send a test e-mail" in a mailer section). A mutating action must be a POST UiKit {@see Action} that carries a
 * CSRF FormView (REQ-SEC-005) handled by a controller of the contributing bundle.
 */
interface SettingsSectionActionsInterface
{
    /**
     * Actions rendered after the submit button of the section form. Use the context to decide (user, request) and to
     * build the CSRF FormView of a POST action: create it **named** (`FormFactory::createNamed('…', …)`), since
     * `handleRequest()` never submits a form without a name.
     *
     * @return iterable<Action>
     */
    public function getActions(SectionActionContext $context): iterable;
}
