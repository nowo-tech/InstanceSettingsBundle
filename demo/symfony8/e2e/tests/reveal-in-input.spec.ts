import { expect, test, type Page, type Request as PwRequest } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/*
 * InstanceSettings 1.17 (web_ui.reveal_in_input): a stored revealable secret is LOCKED. The input is read-only; the
 * PasswordToggle eye shows the stored value IN that input (type=text, autofill / spellcheck / autocorrect off) through
 * the same reveal call and hides it again; "Replace the stored value" unlocks an empty input; the revealed value never
 * reaches a submitted form nor a FormData.
 *
 * Self-contained like reveal-toggle.spec.ts: Playwright routes serve the page and the reveal endpoint (no real
 * secret); the bundle script is read from the repository. Runs in Chromium and Firefox.
 */

const ORIGIN = 'http://reveal-in-input.test';
const SECRET = 'placeholder-stored-secret-5e2d';
const SCRIPT_PATH = 'bundles/nowoinstancesettings/js/nowo-instance-settings-reveal.js';
const INPUT = '#secret';
const EYE = '[data-nowo-password-toggle-target="button"]';
const REPLACE = 'input[name="settings_section[auth_token:replace]"]';
const MASKING = ['autocomplete', 'spellcheck', 'autocapitalize', 'autocorrect', 'data-1p-ignore', 'data-lpignore', 'data-bwignore'];

async function revealScript(page: Page): Promise<string> {
  const local = resolve(__dirname, '../../../../src/Resources/public/js/nowo-instance-settings-reveal.js');
  if (existsSync(local)) {
    return readFileSync(local, 'utf8');
  }
  const base = process.env.DEMO_BASE_URL || 'http://127.0.0.1:8079';
  const response = await page.request.get(`${base}/${SCRIPT_PATH}`);
  expect(response.ok()).toBeTruthy();

  return response.text();
}

const PAGE = `<!doctype html><html><head><meta charset="utf-8"><title>reveal in input</title></head><body>
<form name="settings_section" method="post" action="${ORIGIN}/save">
  <div data-nowo-instance-settings-reveal="auth_token" data-nowo-instance-settings-reveal-section="sms" data-nowo-instance-settings-locked="true">
    <nowo-password-toggle data-nowo-password-toggle class="form-password-toggle">
      <input type="password" id="secret" name="settings_section[auth_token][value]" autocomplete="new-password" placeholder="Saved: ••••">
      <span role="button" tabindex="0" data-nowo-password-toggle-target="button" aria-label="Show password">eye</span>
    </nowo-password-toggle>
    <input type="checkbox" name="settings_section[auth_token][clear]" value="1">
  </div>
  <div data-nowo-instance-settings-replace-for="auth_token">
    <label><input type="checkbox" name="settings_section[auth_token:replace]" value="1"> Replace the stored value</label>
  </div>
  <input type="text" name="settings_section[sender]" value="ACME">
  <input type="hidden" name="settings_section[_token]" value="form-token">
  <button type="submit" id="save">Save</button>
</form>
<div data-nowo-instance-settings-reveal-root data-section="sms" data-urls='{"auth_token":"${ORIGIN}/reveal/auth_token"}' data-token="reveal-token" data-copied-label="Copied" data-error-label="The secret could not be revealed." data-empty-label="No secret is stored for this field." data-on-toggle="true" data-toggle-show-label="Reveal stored value" data-toggle-hide-label="Hide stored value" data-in-input="true" data-shown-label="Stored value shown in the field." data-hidden-label="Stored value hidden.">
  <template data-nowo-instance-settings-reveal-template>
    <div class="nowo-instance-settings-reveal__controls">
      <button type="button" data-nowo-instance-settings-reveal-show>Reveal</button>
      <button type="button" data-nowo-instance-settings-reveal-copy hidden>Copy</button>
      <button type="button" data-nowo-instance-settings-reveal-hide hidden>Hide</button>
      <output data-nowo-instance-settings-reveal-output aria-live="polite" hidden></output>
      <span data-nowo-instance-settings-reveal-status role="status" aria-live="polite"></span>
    </div>
  </template>
</div>
<script src="${ORIGIN}/toggle-stand-in.js"></script>
<script src="${ORIGIN}/${SCRIPT_PATH}"></script>
</body></html>`;

/** Stand-in for PasswordToggle's host handler: unmasks the typed text (what the eye does on an unlocked row). */
const STAND_IN = `document.querySelectorAll('[data-nowo-password-toggle]').forEach(function (host) {
  host.addEventListener('click', function (event) {
    if (!event.target.closest('[data-nowo-password-toggle-target="button"]')) { return; }
    var input = host.querySelector('input');
    input.type = input.type === 'password' ? 'text' : 'password';
  });
});`;

type Setup = { reveals: PwRequest[]; saves: PwRequest[] };

async function open(page: Page, options: { status?: number; clock?: boolean } = {}): Promise<Setup> {
  const script = await revealScript(page);
  if (options.clock) {
    await page.clock.install();
  }
  const setup: Setup = { reveals: [], saves: [] };
  await page.route(`${ORIGIN}/**`, async (route) => {
    const url = route.request().url();
    if (url === `${ORIGIN}/page`) {
      return route.fulfill({ contentType: 'text/html', body: PAGE });
    }
    if (url.endsWith('/toggle-stand-in.js')) {
      return route.fulfill({ contentType: 'text/javascript', body: STAND_IN });
    }
    if (url.endsWith(SCRIPT_PATH)) {
      return route.fulfill({ contentType: 'text/javascript', body: script });
    }
    if (url.endsWith('/reveal/auth_token')) {
      setup.reveals.push(route.request());
      const status = options.status ?? 200;

      return route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(status === 200 ? { key: 'sms.auth_token', value: SECRET } : { message: 'Too Many Requests' }),
      });
    }
    if (url.endsWith('/save')) {
      setup.saves.push(route.request());

      return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>saved</title>' });
    }

    return route.abort();
  });
  await page.goto(`${ORIGIN}/page`);
  await expect(page.locator('[data-nowo-instance-settings-reveal-status]')).toHaveCount(1);

  return setup;
}

async function attributes(page: Page): Promise<Record<string, string | null>> {
  return page.locator(INPUT).evaluate((input, names) => {
    const out: Record<string, string | null> = {};
    for (const name of names) {
      out[name] = input.getAttribute(name);
    }

    return out;
  }, MASKING);
}

async function revealAndWait(page: Page): Promise<void> {
  await page.locator(EYE).click();
  await expect(page.locator(INPUT)).toHaveValue(SECRET);
}

test.describe('Reveal in input (locked stored secret)', () => {
  test('locked on load; the eye flips the same input and restores every attribute', async ({ page }) => {
    const setup = await open(page);
    const input = page.locator(INPUT);
    const before = await attributes(page);

    await expect(input).toHaveAttribute('readonly', '');
    await expect(input).toHaveAttribute('type', 'password');
    await expect(input).toHaveValue('');
    await expect(input).toHaveAttribute('placeholder', 'Saved: ••••');
    await expect(page.locator('[data-nowo-instance-settings-reveal-show]')).toBeHidden();
    await expect(page.locator(EYE)).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator(EYE)).toHaveAttribute('aria-label', 'Reveal stored value');
    await expect(input).not.toBeEditable();

    await revealAndWait(page);
    expect(setup.reveals).toHaveLength(1);
    expect(setup.reveals[0].postData()).toBe('_token=reveal-token');
    await expect(input).toHaveAttribute('type', 'text');
    await expect(input).toHaveAttribute('readonly', '');
    expect(await attributes(page)).toEqual({
      autocomplete: 'off',
      spellcheck: 'false',
      autocapitalize: 'off',
      autocorrect: 'off',
      'data-1p-ignore': 'true',
      'data-lpignore': 'true',
      'data-bwignore': 'true',
    });
    await expect(page.locator(EYE)).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator(EYE)).toHaveAttribute('aria-label', 'Hide stored value');
    await expect(page.locator('[data-nowo-instance-settings-reveal-status]')).toHaveText('Stored value shown in the field.');
    await expect(page.locator('[data-nowo-instance-settings-reveal-output]')).toBeHidden();
    await expect(page.locator('[data-nowo-instance-settings-reveal-output]')).toHaveText('');
    await expect(page.locator('body')).not.toContainText(SECRET);

    await page.locator(EYE).click();
    await expect(input).toHaveAttribute('type', 'password');
    await expect(input).toHaveValue('');
    expect(await attributes(page)).toEqual(before);
    await expect(page.locator(EYE)).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('[data-nowo-instance-settings-reveal-status]')).toHaveText('Stored value hidden.');
    expect(setup.reveals).toHaveLength(1);
  });

  test('neither FormData nor the submitted form carry the revealed value', async ({ page }) => {
    const setup = await open(page);
    await revealAndWait(page);

    const data = await page.locator('form').evaluate((form: HTMLFormElement) => Object.fromEntries(new FormData(form).entries()));
    expect(data['settings_section[auth_token][value]']).toBe('');
    expect(JSON.stringify(data)).not.toContain(SECRET);
    await expect(page.locator(INPUT)).toHaveValue(SECRET);

    await page.locator('#save').click();
    await expect.poll(() => setup.saves.length).toBe(1);
    const body = setup.saves[0].postData() ?? '';
    expect(body).not.toContain(SECRET);
    expect(new URLSearchParams(body).get('settings_section[auth_token][value]')).toBe('');
    expect(new URLSearchParams(body).get('settings_section[sender]')).toBe('ACME');
  });

  test('a tampered locked input is emptied on submit', async ({ page }) => {
    const setup = await open(page);
    await page.locator(INPUT).evaluate((input: HTMLInputElement) => {
      input.readOnly = false;
      input.value = 'tampered-value';
    });

    await page.locator('#save').click();
    await expect.poll(() => setup.saves.length).toBe(1);
    expect(new URLSearchParams(setup.saves[0].postData() ?? '').get('settings_section[auth_token][value]')).toBe('');
  });

  test('Replace unlocks an empty input, the edited value submits, unticking locks again', async ({ page }) => {
    const setup = await open(page);
    const input = page.locator(INPUT);
    await revealAndWait(page);

    await page.locator(REPLACE).check();
    await expect(input).not.toHaveAttribute('readonly', '');
    await expect(input).toHaveValue('');
    await expect(input).toHaveAttribute('type', 'password');
    await expect(input).toBeFocused();
    await expect(page.locator(EYE)).toHaveAttribute('aria-label', 'Show password');

    // Undo cannot bring the revealed value back into the now editable input.
    await page.keyboard.press('ControlOrMeta+z');
    await expect(input).toHaveValue('');

    // The eye is PasswordToggle's own again: it unmasks what is typed, no reveal call.
    await input.fill('typed-new-value');
    await page.locator(EYE).click();
    await expect(input).toHaveAttribute('type', 'text');
    expect(setup.reveals).toHaveLength(1);

    // Unticking locks the row again and drops what was typed.
    await page.locator(REPLACE).uncheck();
    await expect(input).toHaveAttribute('readonly', '');
    await expect(input).toHaveValue('');
    await expect(input).toHaveAttribute('type', 'password');
    await expect(page.locator(EYE)).toHaveAttribute('aria-label', 'Reveal stored value');
    await revealAndWait(page);
    expect(setup.reveals).toHaveLength(2);

    // Ticked again, a typed value is submitted as typed.
    await page.locator(REPLACE).check();
    await input.fill('replaced-value');
    await page.locator('#save').click();
    await expect.poll(() => setup.saves.length).toBe(1);
    const body = new URLSearchParams(setup.saves[0].postData() ?? '');
    expect(body.get('settings_section[auth_token][value]')).toBe('replaced-value');
    expect(body.get('settings_section[auth_token:replace]')).toBe('1');
    expect(setup.saves[0].postData()).not.toContain(SECRET);
  });

  test('undo after hiding never brings the value back', async ({ page }) => {
    await open(page);
    const input = page.locator(INPUT);
    await revealAndWait(page);
    await input.focus();
    await page.locator(EYE).click();
    await expect(input).toHaveValue('');
    await input.focus();
    await page.keyboard.press('ControlOrMeta+z');
    await page.keyboard.press('ControlOrMeta+z');
    await expect(input).toHaveValue('');
    await expect(input).toHaveAttribute('type', 'password');
  });

  test('auto-hide after 60 seconds', async ({ page }) => {
    await open(page, { clock: true });
    await revealAndWait(page);
    await page.clock.runFor(59_000);
    await expect(page.locator(INPUT)).toHaveValue(SECRET);
    await page.clock.runFor(2_000);
    await expect(page.locator(INPUT)).toHaveValue('');
    await expect(page.locator(INPUT)).toHaveAttribute('type', 'password');
  });

  for (const event of ['pagehide', 'turbo:before-cache', 'visibilitychange'] as const) {
    test(`${event} hides the value`, async ({ page }) => {
      await open(page);
      await revealAndWait(page);
      await page.evaluate((name) => {
        if (name === 'visibilitychange') {
          Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
          document.dispatchEvent(new Event('visibilitychange'));
        } else if (name === 'pagehide') {
          window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        } else {
          document.dispatchEvent(new Event(name));
        }
      }, event);
      await expect(page.locator(INPUT)).toHaveValue('');
      await expect(page.locator(INPUT)).toHaveAttribute('type', 'password');
      expect(await attributes(page)).toEqual({
        autocomplete: 'new-password',
        spellcheck: null,
        autocapitalize: null,
        autocorrect: null,
        'data-1p-ignore': null,
        'data-lpignore': null,
        'data-bwignore': null,
      });
    });
  }

  test('leaving for another page and coming back shows a locked, empty input', async ({ page }) => {
    await open(page);
    await revealAndWait(page);
    await page.goto(`${ORIGIN}/save`);
    await page.goBack();
    await expect(page.locator(INPUT)).toHaveValue('');
    await expect(page.locator(INPUT)).toHaveAttribute('type', 'password');
    await expect(page.locator('body')).not.toContainText(SECRET);
  });

  test('a refused reveal shows its message and leaves the input masked', async ({ page }) => {
    await open(page, { status: 429 });
    await page.locator(EYE).click();
    await expect(page.locator('[data-nowo-instance-settings-reveal-output]')).toHaveText('The secret could not be revealed.');
    await expect(page.locator(INPUT)).toHaveValue('');
    await expect(page.locator(INPUT)).toHaveAttribute('type', 'password');
    await expect(page.locator(EYE)).toHaveAttribute('aria-pressed', 'false');
  });
});
