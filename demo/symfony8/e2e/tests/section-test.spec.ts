import { expect, test, type Page, type Route } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { sectionForm } from './helpers';

/*
 * InstanceSettings 1.18 (Beacon spec 238): the Test button of a section / field group checks the STORED values.
 *
 * - "demo" tests run against the demo server: the mailer section has a tester (App\Settings\MailerSettingsTester).
 * - "page" tests are self-contained (Playwright routes, no demo server): a section page with subtabs, a section tester
 *   and two group testers, to drive the group visibility, escaping, refusals and Turbo's page cache. The bundle script
 *   is read from the repository when the e2e folder sits in it, else from the running demo's public assets.
 */

const ORIGIN = 'http://section-test.test';
const SCRIPT_PATH = 'bundles/nowoinstancesettings/js/nowo-instance-settings-test.js';

async function testScript(page: Page): Promise<string> {
  const local = resolve(__dirname, '../../../../src/Resources/public/js/nowo-instance-settings-test.js');
  if (existsSync(local)) {
    return readFileSync(local, 'utf8');
  }
  const base = process.env.DEMO_BASE_URL || 'http://127.0.0.1:8079';
  const response = await page.request.get(`${base}/${SCRIPT_PATH}`);
  expect(response.ok()).toBeTruthy();

  return response.text();
}

test.describe('Section Test button (demo)', () => {
  test('sits beside Save, reports into a polite live region and keeps the last report', async ({ page }) => {
    await page.goto('/admin/settings/sections/mailer');
    const save = page.getByRole('button', { name: 'Save', exact: true });
    const button = page.getByRole('button', { name: 'Test', exact: true });
    await expect(button).toBeVisible();
    await expect(save).toBeVisible();
    // Beside Save: same row, right after it.
    expect(await button.evaluate((el) => el.closest('[data-nowo-instance-settings-test-buttons]')?.previousElementSibling?.textContent?.trim())).toBe('Save');

    const result = page.locator('[data-nowo-instance-settings-test-result]').first();
    await expect(result).toHaveAttribute('role', 'status');
    await expect(result).toHaveAttribute('aria-live', 'polite');
    await button.click();
    await expect(result.locator('.nowo-instance-settings-test__summary')).toContainText('Last test: Passed');
    await expect(result.locator('li')).toHaveCount(2);
    await expect(result.locator('li').first()).toContainText('Transport DSN');
    await expect(result.locator('li').first()).toContainText('Stored DSN uses the smtp transport.');
    expect(await page.content()).not.toContain('REDACTED-DEMO');

    await page.reload();
    await expect(page.locator('[data-nowo-instance-settings-test-result]').first()).toContainText('Last test: Passed');
  });

  test('a dirty form shows the stored-values hint and never blocks Save', async ({ page }) => {
    await page.goto('/admin/settings/sections/mailer');
    const hint = page.getByTestId('instance-settings-test-dirty');
    await expect(hint).toBeHidden();
    await sectionForm(page).getByLabel('Sender name').fill('Acme Ops (edited)');
    await expect(hint).toBeVisible();
    await expect(hint).toHaveText('Test uses the stored values: save first to test your changes.');
    await expect(page.getByRole('button', { name: 'Test', exact: true })).toBeEnabled();

    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await expect(page.getByTestId('instance-settings-test-dirty')).toBeHidden();
    await sectionForm(page).getByLabel('Sender name').fill('Acme Ops');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
  });

  test('a section without a tester has no Test button', async ({ page }) => {
    await page.goto('/admin/settings/sections/sms');
    await expect(page.locator('[data-nowo-instance-settings-test-root]')).toHaveCount(0);
    await expect(page.locator('script[data-nowo-instance-settings-test-script]')).toHaveCount(0);
  });
});

function html(): string {
  return `<!doctype html><html><head><meta charset="utf-8"><title>section test</title></head><body>
<div id="nowo-instance-settings-test-calendar_oauth" data-nowo-instance-settings-test-root data-section="calendar_oauth" data-url="${ORIGIN}/test" data-token="section-token" data-running-label="Testing the stored values…" data-error-label="The test could not be run." hidden>
  <span data-nowo-instance-settings-test-buttons data-root="nowo-instance-settings-test-calendar_oauth">
    <button type="button" id="b-section" data-nowo-instance-settings-test-button data-root="nowo-instance-settings-test-calendar_oauth" data-group="" aria-controls="r-section">Test</button>
    <button type="button" id="b-google" data-nowo-instance-settings-test-button data-root="nowo-instance-settings-test-calendar_oauth" data-group="google" aria-controls="r-google">Test Google</button>
    <button type="button" id="b-microsoft" data-nowo-instance-settings-test-button data-root="nowo-instance-settings-test-calendar_oauth" data-group="microsoft" aria-controls="r-microsoft">Test Microsoft</button>
  </span>
  <p data-nowo-instance-settings-test-dirty data-testid="dirty" hidden>Test uses the stored values: save first to test your changes.</p>
  <div id="r-section" role="status" aria-live="polite" data-nowo-instance-settings-test-result data-group=""><p class="nowo-instance-settings-test__summary" data-status="ok">Last test: Passed (earlier)</p></div>
  <div id="r-google" role="status" aria-live="polite" data-nowo-instance-settings-test-result data-group="google"></div>
  <div id="r-microsoft" role="status" aria-live="polite" data-nowo-instance-settings-test-result data-group="microsoft"></div>
</div>
<form name="settings_section" method="post" action="${ORIGIN}/save">
  <div data-nowo-instance-settings-tabs>
    <div role="tablist">
      <button type="button" role="tab" aria-selected="true" data-nowo-instance-settings-group-tab="google">Google</button>
      <button type="button" role="tab" aria-selected="false" data-nowo-instance-settings-group-tab="microsoft">Microsoft</button>
    </div>
    <input id="client" name="settings_section[google_client_id]" value="client-1">
  </div>
  <div class="actions"><button type="submit" id="save">Save</button></div>
</form>
<script src="${ORIGIN}/test.js"></script>
</body></html>`;
}

async function openPage(page: Page, onTest: (route: Route) => Promise<void> | void): Promise<void> {
  const script = await testScript(page);
  await page.route(`${ORIGIN}/page`, (route) => route.fulfill({ contentType: 'text/html', body: html() }));
  await page.route(`${ORIGIN}/test.js`, (route) => route.fulfill({ contentType: 'text/javascript', body: script }));
  await page.route(`${ORIGIN}/test`, onTest);
  await page.route(`${ORIGIN}/save`, (route) => route.fulfill({ contentType: 'text/html', body: '<p id="saved">saved</p>' }));
  await page.goto(`${ORIGIN}/page`);
}

function report(group: string | null, label: string, status = 'ok'): string {
  return JSON.stringify({
    section: 'calendar_oauth',
    group,
    status,
    statusLabel: status === 'ok' ? 'Passed' : 'Failed',
    testedAt: '2026-11-14T10:00:00+00:00',
    summary: `Last test: ${status === 'ok' ? 'Passed' : 'Failed'} (2026-11-14 10:00 UTC, admin)`,
    checks: [{ status, statusLabel: status === 'ok' ? 'Passed' : 'Failed', label, hint: 'HTTP status 200' }],
  });
}

test.describe('Section Test button (page)', () => {
  test('places the buttons beside Save and shows only the open subtab\'s group button', async ({ page }) => {
    await openPage(page, (route) => route.fulfill({ contentType: 'application/json', body: report(null, 'Token') }));
    const root = page.locator('[data-nowo-instance-settings-test-root]');
    await expect(root).toBeVisible();
    expect(await page.locator('#save').evaluate((el) => el.nextElementSibling?.hasAttribute('data-nowo-instance-settings-test-buttons'))).toBe(true);
    expect(await page.locator('.actions').evaluate((el) => el.nextElementSibling?.hasAttribute('data-nowo-instance-settings-test-root'))).toBe(true);

    await expect(page.locator('#b-section')).toBeVisible();
    await expect(page.locator('#b-google')).toBeVisible();
    await expect(page.locator('#b-microsoft')).toBeHidden();
    await expect(page.locator('#r-microsoft')).toBeHidden();

    // UiKit's tabs runtime switches the subtab and dispatches nowo-ui:tabs:change.
    await page.evaluate(() => {
      const tabs = document.querySelectorAll('[role="tab"]');
      tabs[0].setAttribute('aria-selected', 'false');
      tabs[1].setAttribute('aria-selected', 'true');
      document.querySelector('[data-nowo-instance-settings-tabs]')?.dispatchEvent(new CustomEvent('nowo-ui:tabs:change', { bubbles: true, detail: { trigger: tabs[1] } }));
    });
    await expect(page.locator('#b-google')).toBeHidden();
    await expect(page.locator('#b-microsoft')).toBeVisible();
    await expect(page.locator('#b-section')).toBeVisible();
  });

  test('posts the token and the group, and writes the answer as text only', async ({ page }) => {
    let body = '';
    await openPage(page, (route) => {
      body = route.request().postData() || '';

      return route.fulfill({ contentType: 'application/json', body: report('google', '<img src=x onerror="window.__xss=1">', 'fail') });
    });
    await page.locator('#b-google').click();
    const result = page.locator('#r-google');
    await expect(result.locator('.nowo-instance-settings-test__summary')).toHaveText('Last test: Failed (2026-11-14 10:00 UTC, admin)');
    await expect(result.locator('li')).toHaveAttribute('data-status', 'fail');
    await expect(result.locator('.nowo-instance-settings-test__label')).toHaveText('<img src=x onerror="window.__xss=1">');
    await expect(result.locator('img')).toHaveCount(0);
    expect(await page.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBeUndefined();
    expect(body).toContain('section-token');
    expect(body).toContain('google');
    await expect(page.locator('#b-google')).toBeEnabled();
  });

  test('a refusal shows the server message, anything else the generic error', async ({ page }) => {
    let calls = 0;
    await openPage(page, (route) => {
      calls++;

      return calls === 1
        ? route.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ error: 'rate_limited', message: 'Too many tests. Try again in a few minutes.' }) })
        : route.fulfill({ status: 500, contentType: 'text/html', body: '<h1>Oops</h1>' });
    });
    await page.locator('#b-section').click();
    await expect(page.locator('#r-section')).toHaveText('Too many tests. Try again in a few minutes.');
    await page.locator('#b-section').click();
    await expect(page.locator('#r-section')).toHaveText('The test could not be run.');
  });

  test('the dirty hint appears on edits and never blocks Save', async ({ page }) => {
    await openPage(page, (route) => route.fulfill({ contentType: 'application/json', body: report(null, 'Token') }));
    await expect(page.getByTestId('dirty')).toBeHidden();
    await page.locator('#client').fill('client-2');
    await expect(page.getByTestId('dirty')).toBeVisible();
    await page.locator('#save').click();
    await expect(page.locator('#saved')).toBeVisible();
  });

  for (const event of ['turbo:before-cache', 'pagehide']) {
    test(`${event} clears the results, the hint and a call in flight`, async ({ page }) => {
      let release: () => void = () => undefined;
      const pending = new Promise<void>((done) => {
        release = done;
      });
      await openPage(page, async (route) => {
        await pending;
        await route.fulfill({ contentType: 'application/json', body: report(null, 'Late answer') });
      });
      await page.locator('#client').fill('client-3');
      await page.locator('#b-section').click();
      await expect(page.locator('#r-section')).toHaveText('Testing the stored values…');
      await expect(page.locator('#b-section')).toBeDisabled();

      await page.evaluate((name) => {
        if (name === 'pagehide') {
          window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        } else {
          document.dispatchEvent(new Event(name, { bubbles: true }));
        }
      }, event);
      // What Turbo would snapshot now: no result, no hint, the button usable.
      await expect(page.locator('#r-section')).toBeEmpty();
      await expect(page.locator('#r-google')).toBeEmpty();
      await expect(page.getByTestId('dirty')).toBeHidden();
      await expect(page.locator('#b-section')).toBeEnabled();

      // The answer of the dropped call never shows up.
      release();
      await page.waitForTimeout(300);
      await expect(page.locator('#r-section')).toBeEmpty();
    });
  }

  test('one call at a time per button', async ({ page }) => {
    let calls = 0;
    let release: () => void = () => undefined;
    const pending = new Promise<void>((done) => {
      release = done;
    });
    await openPage(page, async (route) => {
      calls++;
      await pending;
      await route.fulfill({ contentType: 'application/json', body: report(null, 'Token') });
    });
    await page.locator('#b-section').click();
    await page.locator('#b-section').dispatchEvent('click');
    await page.locator('#b-section').dispatchEvent('click');
    release();
    await expect(page.locator('#r-section .nowo-instance-settings-test__summary')).toBeVisible();
    expect(calls).toBe(1);
  });
});
