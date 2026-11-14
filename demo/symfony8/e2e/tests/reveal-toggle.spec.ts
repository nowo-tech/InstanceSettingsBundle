import { expect, test, type Page, type Request as PwRequest } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/*
 * InstanceSettings 1.16 (web_ui.reveal_on_toggle): the PasswordToggle eye of a revealable secret row reveals the STORED
 * secret through the existing reveal call (POST + CSRF token) into the row's read-only <output>, never into the input,
 * and the revealed value never reaches the submitted form.
 *
 * Self-contained: the page is served by Playwright routes (no demo server, no real secret). The bundle script is read
 * from the repository when the e2e folder sits in it, else from the running demo's public assets. The eye markup is
 * PasswordToggle's (nowo-tech/password-toggle-bundle `toggle_password_widget`): host + [data-nowo-password-toggle-target]
 * button; a stand-in handler on the host unmasks the input like the real one, so the test also proves it never runs in
 * this mode.
 */

const ORIGIN = 'http://reveal.test';
const SECRET = 'placeholder-stored-secret-91c4';
const SCRIPT_PATH = 'bundles/nowoinstancesettings/js/nowo-instance-settings-reveal.js';

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

function html(options: { onToggle: boolean; toggleOnly?: boolean }): string {
  const toggle = options.onToggle
    ? ' data-on-toggle="true" data-toggle-show-label="Reveal stored value" data-toggle-hide-label="Hide stored value"' +
      (options.toggleOnly ? ' data-toggle-only="true"' : '')
    : '';

  return `<!doctype html><html><head><meta charset="utf-8"><title>reveal</title></head><body>
<form name="settings_section" method="post" action="${ORIGIN}/save">
  <div data-nowo-instance-settings-reveal="auth_token" data-nowo-instance-settings-reveal-section="sms">
    <nowo-password-toggle data-nowo-password-toggle class="form-password-toggle">
      <input type="password" id="secret" name="settings_section[auth_token][value]" autocomplete="new-password">
      <span role="button" tabindex="0" data-nowo-password-toggle-target="button" aria-label="Show password">eye</span>
    </nowo-password-toggle>
    <input type="checkbox" name="settings_section[auth_token][clear]" value="1">
  </div>
  <input type="hidden" name="settings_section[_token]" value="form-token">
  <button type="submit" id="save">Save</button>
</form>
<div data-nowo-instance-settings-reveal-root data-section="sms" data-urls='{"auth_token":"${ORIGIN}/reveal/auth_token"}' data-token="reveal-token" data-copied-label="Copied" data-error-label="The secret could not be revealed." data-empty-label="No secret is stored for this field."${toggle}>
  <template data-nowo-instance-settings-reveal-template>
    <div class="nowo-instance-settings-reveal__controls">
      <button type="button" data-nowo-instance-settings-reveal-show>Reveal</button>
      <button type="button" data-nowo-instance-settings-reveal-copy hidden>Copy</button>
      <button type="button" data-nowo-instance-settings-reveal-hide hidden>Hide</button>
      <output data-nowo-instance-settings-reveal-output aria-live="polite" hidden></output>
    </div>
  </template>
</div>
<script src="${ORIGIN}/toggle-stand-in.js"></script>
<script src="${ORIGIN}/${SCRIPT_PATH}"></script>
</body></html>`;
}

/** Stand-in for PasswordToggle's host handler: unmasks the typed text (what the eye does without the option). */
const STAND_IN = `document.querySelectorAll('[data-nowo-password-toggle]').forEach(function (host) {
  host.addEventListener('click', function (event) {
    if (!event.target.closest('[data-nowo-password-toggle-target="button"]')) { return; }
    var input = host.querySelector('input');
    input.type = input.type === 'password' ? 'text' : 'password';
  });
});`;

type Setup = { reveals: PwRequest[]; saves: PwRequest[] };

async function open(page: Page, options: { onToggle: boolean; toggleOnly?: boolean; status?: number }): Promise<Setup> {
  const script = await revealScript(page);
  const setup: Setup = { reveals: [], saves: [] };
  await page.route(`${ORIGIN}/**`, async (route) => {
    const url = route.request().url();
    if (url === `${ORIGIN}/page`) {
      return route.fulfill({ contentType: 'text/html', body: html(options) });
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
  await expect(page.locator('[data-nowo-instance-settings-reveal-output]')).toHaveCount(1);

  return setup;
}

const eye = (page: Page) => page.locator('[data-nowo-password-toggle-target="button"]');
const output = (page: Page) => page.locator('[data-nowo-instance-settings-reveal-output]');

test.describe('InstanceSettings reveal on toggle (1.16)', () => {
  test('the eye reveals the stored value outside the form and hides it again', async ({ page }) => {
    const setup = await open(page, { onToggle: true });
    await expect(eye(page)).toHaveAttribute('aria-pressed', 'false');
    await expect(eye(page)).toHaveAttribute('aria-label', 'Reveal stored value');

    await page.locator('#secret').fill('typed-new-value');
    await eye(page).click();
    await expect(output(page)).toHaveText(SECRET);
    await expect(eye(page)).toHaveAttribute('aria-pressed', 'true');
    await expect(eye(page)).toHaveAttribute('aria-label', 'Hide stored value');

    // The same call as the Reveal button: POST, the section's reveal token in the body.
    expect(setup.reveals).toHaveLength(1);
    expect(setup.reveals[0].method()).toBe('POST');
    expect(setup.reveals[0].postData()).toBe('_token=reveal-token');

    // The input is untouched (still masked, still the typed value) and the value is not form data.
    await expect(page.locator('#secret')).toHaveAttribute('type', 'password');
    await expect(page.locator('#secret')).toHaveValue('typed-new-value');
    const entries = await page.evaluate(() => {
      const form = document.querySelector('form[name="settings_section"]') as HTMLFormElement;

      return Array.from(new FormData(form).entries()).map(([k, v]) => `${k}=${String(v)}`);
    });
    expect(entries.join('\n')).not.toContain(SECRET);
    expect(await page.locator('form [name]').evaluateAll((els, s) => els.some((el) => (el as HTMLInputElement).value === s), SECRET)).toBe(false);

    // Second click hides it and clears the DOM.
    await eye(page).click();
    await expect(output(page)).toBeHidden();
    await expect(output(page)).toHaveText('');
    await expect(eye(page)).toHaveAttribute('aria-pressed', 'false');
    await expect(eye(page)).toHaveAttribute('aria-label', 'Reveal stored value');
    expect(setup.reveals).toHaveLength(1);
  });

  test('submitting after a reveal never sends the revealed value', async ({ page }) => {
    const setup = await open(page, { onToggle: true });
    await eye(page).click();
    await expect(output(page)).toHaveText(SECRET);

    await page.locator('#save').click();
    await expect(page).toHaveTitle('saved');
    expect(setup.saves).toHaveLength(1);
    const body = setup.saves[0].postData() ?? '';
    expect(body).toContain('settings_section%5B_token%5D=form-token');
    expect(body).not.toContain(SECRET);
    expect(body).not.toContain(encodeURIComponent(SECRET));
  });

  test('keyboard: Enter on the eye reveals, Space hides', async ({ page }) => {
    await open(page, { onToggle: true });
    await eye(page).focus();
    await page.keyboard.press('Enter');
    await expect(output(page)).toHaveText(SECRET);
    await page.keyboard.press(' ');
    await expect(output(page)).toHaveText('');
    await expect(eye(page)).toHaveAttribute('aria-pressed', 'false');
  });

  test('a refused reveal shows the Reveal button error and the eye stays hidden', async ({ page }) => {
    const setup = await open(page, { onToggle: true, status: 429 });
    await eye(page).click();
    await expect(output(page)).toHaveText('The secret could not be revealed.');
    await expect(eye(page)).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('#secret')).toHaveAttribute('type', 'password');
    expect(setup.reveals).toHaveLength(1);
  });

  test('the page being hidden drops the revealed value', async ({ page }) => {
    await open(page, { onToggle: true });
    await eye(page).click();
    await expect(output(page)).toHaveText(SECRET);
    await page.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await expect(output(page)).toHaveText('');
    await expect(eye(page)).toHaveAttribute('aria-pressed', 'false');
  });

  test('reveal_toggle_only hides the separate buttons; the Reveal button still works without it', async ({ page }) => {
    await open(page, { onToggle: true, toggleOnly: true });
    await expect(page.locator('[data-nowo-instance-settings-reveal-show]')).toBeHidden();
    await eye(page).click();
    await expect(output(page)).toHaveText(SECRET);
    await expect(page.locator('[data-nowo-instance-settings-reveal-hide]')).toBeHidden();
    await expect(page.locator('[data-nowo-instance-settings-reveal-copy]')).toBeHidden();
  });

  test('without the option the eye only unmasks the typed text', async ({ page }) => {
    const setup = await open(page, { onToggle: false });
    await expect(page.locator('[data-nowo-instance-settings-reveal-show]')).toBeVisible();
    await expect(eye(page)).not.toHaveAttribute('aria-pressed', /.*/);
    await eye(page).click();
    await expect(page.locator('#secret')).toHaveAttribute('type', 'text');
    expect(setup.reveals).toHaveLength(0);
    await expect(output(page)).toHaveText('');
  });
});
