/*
 * InstanceSettingsBundle 1.18 — Test buttons of a settings section (settings/_section_test.html.twig).
 *
 * Plain script (no build, no dependency), loaded deferred with the request CSP nonce by the section page when the
 * section has a tester and the user may test it. Idempotent: loading it twice binds once. Clicks are bound by
 * delegation on the document; window.NowoInstanceSettingsTest.init() places the controls of roots added later.
 *
 * - Placement: the buttons go right after the Save button of the section form that follows the root (the form's own
 *   submit, or a submit outside it bound with form="…"); the root (unsaved-changes hint and result regions) goes right
 *   after the row that holds Save. Without a Save button the controls stay where they were rendered. The root is only
 *   shown once placed: without this script nothing is visible.
 * - Field groups: on a page with subtabs (web_ui.section_layout: tabs, upgraded by UiKit's tabs runtime) a group
 *   tester's button and result are shown only while its subtab is open; the section tester's always. Without subtabs
 *   every button is shown (each group button names its group).
 * - Test checks the STORED values: Save is never blocked and never waits. While the form has unsaved changes a hint says
 *   so (Test uses the stored values, save first); it never disables anything.
 * - A click POSTs to data-url with the section's CSRF token (data-token) and the group, one call at a time per button,
 *   and writes the answer into the button's result region (role="status", aria-live="polite") with textContent only:
 *   nothing from the server is ever parsed as HTML (1.24: the time of the summary goes in a <time datetime> element built
 *   with textContent). A refusal (rate limit, test already running) shows the server's
 *   translated message; anything else shows data-error-label.
 * - Turbo Drive's turbo:before-cache and pagehide clear every result region and the hint, and drop any call still in
 *   flight (its late answer is ignored), so neither Turbo's snapshot nor the back / forward cache shows a stale result.
 *   Turbo is not required: the script only listens for its events.
 */
(function () {
    'use strict';

    if (window.NowoInstanceSettingsTest) {
        return;
    }

    var ROOT = '[data-nowo-instance-settings-test-root]';
    var BUTTONS = '[data-nowo-instance-settings-test-buttons]';
    var BUTTON = '[data-nowo-instance-settings-test-button]';
    var RESULT = '[data-nowo-instance-settings-test-result]';
    var DIRTY = '[data-nowo-instance-settings-test-dirty]';
    var TABS = '[data-nowo-instance-settings-tabs]';
    var TAB = '[data-nowo-instance-settings-group-tab]';
    var READY = 'data-nowo-instance-settings-test-ready';
    var FORM = 'data-nowo-instance-settings-test-form';
    var STATUSES = ['ok', 'fail', 'skipped'];
    // Calls in flight: {button, controller}. A call no longer listed here is dropped when it answers.
    var active = [];

    function rootOf(element) {
        var id = element ? element.getAttribute('data-root') : null;

        return id ? document.getElementById(id) : null;
    }

    // The section form the root was rendered for: the first form after it in document order.
    function formOf(root) {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            if (root.compareDocumentPosition(forms[i]) & Node.DOCUMENT_POSITION_FOLLOWING) {
                return forms[i];
            }
        }

        return null;
    }

    function saveOf(form) {
        if (!form) {
            return null;
        }
        var save = form.querySelector('button[type="submit"], input[type="submit"]');
        if (!save && form.id) {
            var outside = document.querySelectorAll('button[type="submit"][form], input[type="submit"][form]');
            for (var i = 0; i < outside.length; i++) {
                if (outside[i].getAttribute('form') === form.id) {
                    return outside[i];
                }
            }
        }

        return save;
    }

    // The open subtab of the form, or null when the form has no upgraded subtabs (every button is shown then).
    function openGroup(form) {
        var tabs = form ? form.querySelector(TABS) : null;
        if (!tabs) {
            return null;
        }
        var list = tabs.querySelector('[role="tablist"]');
        if (!list || list.hidden) {
            return null;
        }
        var selected = tabs.querySelector(TAB + '[aria-selected="true"]');

        return selected ? selected.getAttribute('data-nowo-instance-settings-group-tab') || '' : '';
    }

    // The form a placed root belongs to (marked by place(), so it survives Turbo's snapshot clone).
    function boundForm(root) {
        var forms = document.querySelectorAll('form[' + FORM + ']');
        for (var i = 0; i < forms.length; i++) {
            if (forms[i].getAttribute(FORM) === root.id) {
                return forms[i];
            }
        }

        return null;
    }

    function syncGroups(root) {
        var open = openGroup(boundForm(root));
        var items = document.querySelectorAll(BUTTON + ', ' + RESULT);
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var owner = item.matches(BUTTON) ? rootOf(item) : item.closest(ROOT);
            if (owner !== root) {
                continue;
            }
            var group = item.getAttribute('data-group') || '';
            item.hidden = open !== null && group !== '' && group !== open;
        }
    }

    function place(root) {
        if (root.getAttribute(READY) === 'true') {
            syncGroups(root);

            return;
        }
        var form = formOf(root);
        var save = saveOf(form);
        var buttons = root.querySelector(BUTTONS);
        if (save && buttons) {
            save.insertAdjacentElement('afterend', buttons);
            var row = save.parentElement;
            if (row && row !== form) {
                row.insertAdjacentElement('afterend', root);
            } else {
                save.insertAdjacentElement('afterend', root);
                root.insertAdjacentElement('beforebegin', buttons);
            }
        }
        if (form && root.id) {
            form.setAttribute(FORM, root.id);
        }
        root.setAttribute(READY, 'true');
        root.hidden = false;
        syncGroups(root);
    }

    function init(scope) {
        var roots = (scope || document).querySelectorAll(ROOT);
        for (var i = 0; i < roots.length; i++) {
            place(roots[i]);
        }
    }

    function clear(result) {
        while (result.firstChild) {
            result.removeChild(result.firstChild);
        }
    }

    function message(result, text, kind) {
        clear(result);
        var p = document.createElement('p');
        p.className = 'nowo-instance-settings-test__message';
        p.setAttribute('data-kind', kind);
        p.textContent = text;
        result.appendChild(p);
    }

    function statusOf(value) {
        return STATUSES.indexOf(value) === -1 ? 'skipped' : value;
    }

    function text(value) {
        return typeof value === 'string' ? value : '';
    }

    // 1.24: the time inside the summary becomes <time datetime="testedAt">, like the server-rendered summary; text
    // nodes only, nothing is parsed as HTML. A summary without that time (redacted) stays plain text.
    function summarize(summary, line, time, testedAt) {
        var at = time === '' || testedAt === '' ? -1 : line.indexOf(time);
        if (at === -1) {
            summary.textContent = line;
            return;
        }
        var tag = document.createElement('time');
        tag.setAttribute('datetime', testedAt);
        tag.textContent = time;
        summary.appendChild(document.createTextNode(line.slice(0, at)));
        summary.appendChild(tag);
        summary.appendChild(document.createTextNode(line.slice(at + time.length)));
    }

    function report(result, data) {
        clear(result);
        var summary = document.createElement('p');
        summary.className = 'nowo-instance-settings-test__summary';
        summary.setAttribute('data-status', statusOf(data.status));
        summarize(summary, text(data.summary), text(data.time), text(data.testedAt));
        result.appendChild(summary);
        var list = document.createElement('ul');
        list.className = 'nowo-instance-settings-test__checks';
        var checks = Array.isArray(data.checks) ? data.checks : [];
        for (var i = 0; i < checks.length; i++) {
            var check = checks[i] || {};
            var item = document.createElement('li');
            item.className = 'nowo-instance-settings-test__check';
            item.setAttribute('data-status', statusOf(check.status));
            var badge = document.createElement('span');
            badge.className = 'nowo-instance-settings-test__badge';
            badge.textContent = text(check.statusLabel);
            var label = document.createElement('span');
            label.className = 'nowo-instance-settings-test__label';
            label.textContent = text(check.label);
            item.appendChild(badge);
            item.appendChild(document.createTextNode(' '));
            item.appendChild(label);
            if (typeof check.hint === 'string' && check.hint !== '') {
                var hint = document.createElement('span');
                hint.className = 'nowo-instance-settings-test__hint';
                hint.textContent = check.hint;
                item.appendChild(document.createTextNode(' '));
                item.appendChild(hint);
            }
            list.appendChild(item);
        }
        result.appendChild(list);
    }

    function entryOf(button) {
        for (var i = 0; i < active.length; i++) {
            if (active[i].button === button) {
                return active[i];
            }
        }

        return null;
    }

    function finish(entry) {
        var index = active.indexOf(entry);
        if (index !== -1) {
            active.splice(index, 1);
        }
        entry.button.disabled = false;
        entry.button.removeAttribute('aria-busy');
        if (entry.result) {
            entry.result.removeAttribute('aria-busy');
        }
    }

    function run(button) {
        if (entryOf(button)) {
            return;
        }
        var root = rootOf(button);
        var result = document.getElementById(button.getAttribute('aria-controls') || '');
        if (!root || !result || typeof window.fetch !== 'function') {
            return;
        }
        var body = new FormData();
        body.append('_token', root.getAttribute('data-token') || '');
        var group = button.getAttribute('data-group') || '';
        if (group !== '') {
            body.append('group', group);
        }
        var entry = { button: button, result: result, controller: typeof AbortController === 'function' ? new AbortController() : null };
        active.push(entry);
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        result.setAttribute('aria-busy', 'true');
        message(result, root.getAttribute('data-running-label') || '', 'running');
        var errorLabel = root.getAttribute('data-error-label') || '';

        window.fetch(root.getAttribute('data-url') || '', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: entry.controller ? entry.controller.signal : undefined,
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (data) {
                if (active.indexOf(entry) === -1) {
                    return;
                }
                if (response.ok && data && Array.isArray(data.checks)) {
                    report(result, data);
                } else {
                    message(result, data && typeof data.message === 'string' && data.message !== '' ? data.message : errorLabel, 'error');
                }
                finish(entry);
            });
        }).catch(function () {
            if (active.indexOf(entry) === -1) {
                return;
            }
            message(result, errorLabel, 'error');
            finish(entry);
        });
    }

    // Turbo's snapshot and the back / forward cache never keep a result, the hint or a call in flight.
    function reset() {
        var calls = active.slice();
        active = [];
        for (var i = 0; i < calls.length; i++) {
            if (calls[i].controller) {
                calls[i].controller.abort();
            }
            finish(calls[i]);
        }
        var results = document.querySelectorAll(RESULT);
        for (var j = 0; j < results.length; j++) {
            clear(results[j]);
            results[j].removeAttribute('aria-busy');
        }
        var hints = document.querySelectorAll(DIRTY);
        for (var k = 0; k < hints.length; k++) {
            hints[k].hidden = true;
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest(BUTTON) : null;
        if (!button) {
            return;
        }
        event.preventDefault();
        run(button);
    });
    document.addEventListener('nowo-ui:tabs:change', function (event) {
        var form = event.target instanceof Element ? event.target.closest('form[' + FORM + ']') : null;
        var root = form ? document.getElementById(form.getAttribute(FORM) || '') : null;
        if (root) {
            syncGroups(root);
        }
    });
    // Unsaved changes: the hint says Test uses the stored values (it never blocks anything).
    function onEdit(event) {
        var form = event.target instanceof Element ? event.target.closest('form[' + FORM + ']') : null;
        var root = form ? document.getElementById(form.getAttribute(FORM) || '') : null;
        var hint = root ? root.querySelector(DIRTY) : null;
        if (hint) {
            hint.hidden = false;
        }
    }
    document.addEventListener('input', onEdit);
    document.addEventListener('change', onEdit);
    document.addEventListener('turbo:before-cache', reset);
    window.addEventListener('pagehide', reset);
    document.addEventListener('turbo:load', function () {
        init(document);
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
    // UiKit's tabs runtime may upgrade the subtabs after this script ran: sync once the page has loaded.
    window.addEventListener('load', function () {
        init(document);
    });

    window.NowoInstanceSettingsTest = { init: init, reset: reset };
})();
