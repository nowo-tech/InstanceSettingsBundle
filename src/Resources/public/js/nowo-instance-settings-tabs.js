/*
 * InstanceSettingsBundle 1.14 — section subtabs (web_ui.section_layout: tabs, settings/_section_form_tabs.html.twig).
 *
 * Plain script (no build, no dependency), loaded deferred with the request CSP nonce by the tabs partial. Idempotent:
 * loading it twice binds once. The tabs themselves are UiKit's in-page tabs runtime (nowo-ui-tabs.js: keyboard,
 * ARIA, panels); this script only reflects the open tab in the URL as ?group=<code> (history.replaceState, no new
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
    var PARAMETER = 'group';

    function groupUrl(href, code) {
        var url = new URL(href);
        url.searchParams.set(PARAMETER, code);

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
        var next = groupUrl(window.location.href, code);
        if (next !== window.location.href) {
            window.history.replaceState(window.history.state, '', next);
        }
    }

    document.addEventListener('nowo-ui:tabs:change', onChange);
    window.NowoInstanceSettingsTabs = { groupUrl: groupUrl };
})();
