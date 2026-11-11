/*
 * InstanceSettingsBundle 1.12 — colour setting input (SettingColorType, Form/color.html.twig).
 *
 * Plain script (no build, no dependency), loaded deferred with the request CSP nonce by the section page when the
 * form has a colour row. Idempotent: loading it twice binds once. Binds by delegation on the document, so rows added
 * later work too; window.NowoInstanceSettingsColor.init(root) shows the swatch of rows inserted after load.
 *
 * - The hex text input is the submitted value. The native swatch has no name: it mirrors the hex input and writes
 *   into it when the user picks a colour (both ways in sync).
 * - Empty hex = follow the preset: the swatch shows the preset colour (data-preset, or data-preset-map[value of the
 *   data-preset-from field], followed live when that field changes).
 * - "Use the preset colour" empties the hex input and moves the focus back to it.
 * Without this script, swatch and reset stay hidden and the hex input works alone.
 */
(function () {
    'use strict';

    if (window.NowoInstanceSettingsColor) {
        return;
    }

    var ROOT = '[data-nowo-instance-settings-color]';
    var HEX = '[data-nowo-instance-settings-color-hex]';
    var SWATCH = '[data-nowo-instance-settings-color-swatch]';
    var RESET = '[data-nowo-instance-settings-color-reset]';
    var COLOR = /^#[0-9a-fA-F]{6}$/;
    var FALLBACK = '#000000';

    function parts(root) {
        return {
            hex: root.querySelector(HEX),
            swatch: root.querySelector(SWATCH),
            reset: root.querySelector(RESET),
        };
    }

    function presetMap(root) {
        try {
            var map = JSON.parse(root.getAttribute('data-preset-map') || '{}');

            return map && typeof map === 'object' ? map : {};
        } catch (e) {
            return {};
        }
    }

    // Value of the preset source field as the server compares it: checkbox '1' / '', radio group / select value.
    function sourceValue(form, name) {
        if (!form || !form.elements) {
            return null;
        }
        var item = form.elements.namedItem(name);
        if (!item) {
            return null;
        }
        if (typeof RadioNodeList !== 'undefined' && item instanceof RadioNodeList) {
            for (var i = 0; i < item.length; i++) {
                if (item[i].type === 'checkbox') {
                    return item[i].checked ? '1' : '';
                }
            }

            return item.value;
        }
        if (item.type === 'checkbox') {
            return item.checked ? '1' : '';
        }

        return typeof item.value === 'string' ? item.value : null;
    }

    function presetOf(root) {
        var from = root.getAttribute('data-preset-from');
        if (from) {
            var value = sourceValue(root.closest('form'), from);
            var map = presetMap(root);
            if (value !== null && Object.prototype.hasOwnProperty.call(map, value) && COLOR.test(map[value])) {
                return map[value].toLowerCase();
            }
        }
        var preset = root.getAttribute('data-preset');

        return preset && COLOR.test(preset) ? preset.toLowerCase() : null;
    }

    function sync(root) {
        var p = parts(root);
        if (!p.hex || !p.swatch) {
            return;
        }
        var value = p.hex.value.trim();
        var preset = presetOf(root);
        if (COLOR.test(value)) {
            p.swatch.value = value.toLowerCase();
        } else if (value === '') {
            p.swatch.value = preset || FALLBACK;
        }
        if (root.hasAttribute('data-preset-from')) {
            p.hex.placeholder = preset || '';
        }
        if (p.reset) {
            p.reset.hidden = value === '';
        }
        root.setAttribute('data-state', value === '' ? 'preset' : COLOR.test(value) ? 'custom' : 'invalid');
    }

    function init(scope) {
        var roots = (scope || document).querySelectorAll(ROOT);
        for (var i = 0; i < roots.length; i++) {
            var p = parts(roots[i]);
            if (p.swatch) {
                p.swatch.hidden = false;
            }
            sync(roots[i]);
        }
    }

    function onInput(event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var root = target.closest(ROOT);
        if (root) {
            var p = parts(root);
            if (target === p.swatch && p.hex) {
                p.hex.value = p.swatch.value.toLowerCase();
                p.hex.dispatchEvent(new Event('input', { bubbles: true }));
                if (event.type === 'change') {
                    p.hex.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            sync(root);

            return;
        }
        // A preset source field changed: refresh the colour rows that follow it.
        var name = target.getAttribute('name');
        var form = target.closest('form');
        if (!name || !form) {
            return;
        }
        var followers = form.querySelectorAll(ROOT + '[data-preset-from]');
        for (var i = 0; i < followers.length; i++) {
            if (followers[i].getAttribute('data-preset-from') === name) {
                sync(followers[i]);
            }
        }
    }

    function onClick(event) {
        var target = event.target instanceof Element ? event.target.closest(RESET) : null;
        var root = target ? target.closest(ROOT) : null;
        if (!root) {
            return;
        }
        var p = parts(root);
        if (!p.hex) {
            return;
        }
        p.hex.value = '';
        p.hex.dispatchEvent(new Event('input', { bubbles: true }));
        p.hex.dispatchEvent(new Event('change', { bubbles: true }));
        sync(root);
        p.hex.focus();
    }

    document.addEventListener('input', onInput);
    document.addEventListener('change', onInput);
    document.addEventListener('click', onClick);
    document.addEventListener('turbo:load', function () {
        init(document);
    });

    window.NowoInstanceSettingsColor = { init: init, sync: sync };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})();
