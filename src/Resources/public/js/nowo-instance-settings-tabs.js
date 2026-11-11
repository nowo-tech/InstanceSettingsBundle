/*
 * InstanceSettingsBundle 1.14 — section subtabs (web_ui.section_layout: tabs, settings/_section_form_tabs.html.twig).
 *
 * Plain script (no build, no dependency), loaded deferred with the request CSP nonce by the tabs partial. Idempotent:
 * loading it twice binds once. The tabs themselves are UiKit's in-page tabs runtime (nowo-ui-tabs.js: keyboard,
 * ARIA, panels); this script only reflects the open tab in the URL as ?settings_group=<code> (the name comes from the
 * root's data-nowo-instance-settings-group-parameter, audit MP-L1; history.replaceState, no new
 * history entry), so a reload, a shared link and the form post (the form has no action: it posts to the page URL, and
 * the redirect after a save keeps the group) come back to the same tab. Without it the tabs still work.
 */
(function () {
    'use strict';

    if (window.NowoInstanceSettingsTabs) {
        return;
    }

    var ROOT = '[data-nowo-instance-settings-tabs]';
    var TAB = 'data-nowo-instance-settings-group-tab';
    var PARAMETER = 'data-nowo-instance-settings-group-parameter';
    var DEFAULT_PARAMETER = 'settings_group';
    var VALID_PARAMETER = /^[A-Za-z_][A-Za-z0-9_]{0,63}$/;

    function parameterOf(root) {
        var name = root.getAttribute(PARAMETER);

        return name && VALID_PARAMETER.test(name) ? name : DEFAULT_PARAMETER;
    }

    function groupUrl(href, code, parameter) {
        var url = new URL(href);
        url.searchParams.set(parameter || DEFAULT_PARAMETER, code);

        return url.toString();
    }

    function onChange(event) {
        var root = event.target;
        var trigger = event.detail ? event.detail.trigger : null;
        if (!(root instanceof Element) || !root.matches(ROOT) || !(trigger instanceof Element)) {
            return;
        }
        var code = trigger.getAttribute(TAB);
        if (!code || !window.history || typeof window.history.replaceState !== 'function') {
            return;
        }
        var next = groupUrl(window.location.href, code, parameterOf(root));
        if (next !== window.location.href) {
            window.history.replaceState(window.history.state, '', next);
        }
    }

    document.addEventListener('nowo-ui:tabs:change', onChange);
    window.NowoInstanceSettingsTabs = { groupUrl: groupUrl };
})();
