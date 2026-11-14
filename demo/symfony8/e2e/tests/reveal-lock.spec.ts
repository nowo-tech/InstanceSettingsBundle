import { test, expect, type Page } from '@playwright/test';
import { sectionForm } from './helpers';

/**
 * InstanceSettings 1.17 — web_ui.reveal_in_input on the demo server: the stored SMS auth token is LOCKED (read-only
 * input), the server ignores a value posted for it without "Replace the stored value", Replace and the clear box are
 * the only ways to change it, and unticking Replace locks it again. The demo has no PasswordToggle eye: the stored
 * value is read back with the Reveal button.
 */
const VALUE = '[name="settings_section[auth_token][value]"]';
const REPLACE = '[name="settings_section[auth_token:replace]"]';
const CLEAR = '[name="settings_section[auth_token][clear]"]';

async function save(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Settings saved.')).toBeVisible();
}

async function stored(page: Page): Promise<string> {
  await page.goto('/admin/settings/sections/sms');
  const row = sectionForm(page).locator('[data-nowo-instance-settings-reveal="auth_token"]');
  await row.locator('[data-nowo-instance-settings-reveal-show]').click();
  const output = row.locator('[data-nowo-instance-settings-reveal-output]');
  await expect(output).not.toHaveText('');
  const text = (await output.textContent()) ?? '';
  await row.locator('[data-nowo-instance-settings-reveal-hide]').click();

  return text;
}

test.describe('Locked stored secret (reveal_in_input)', () => {
  test('lock, tamper, replace, clear-then-type and re-lock', async ({ page }) => {
    await page.goto('/admin/settings/sections/sms');
    await sectionForm(page).getByLabel('Provider').selectOption('twilio');
    const input = sectionForm(page).locator(VALUE);

    // Start from no stored token: a normal input.
    if ((await sectionForm(page).locator(REPLACE).count()) > 0) {
      await sectionForm(page).locator(CLEAR).check();
      await save(page);
    }
    await expect(sectionForm(page).locator(REPLACE)).toHaveCount(0);
    await expect(input).toBeEditable();
    await input.fill('tw-lock-first');
    await save(page);

    // Stored: locked.
    await expect(sectionForm(page).locator(VALUE)).not.toBeEditable();
    await expect(sectionForm(page).locator(VALUE)).toHaveValue('');
    await expect(sectionForm(page).locator(REPLACE)).not.toBeChecked();

    // A tampered post (around every browser-side protection) changes nothing.
    const fields = await sectionForm(page).evaluate((form: HTMLFormElement) => {
      const out: Record<string, string> = {};
      for (const element of Array.from(form.elements) as HTMLInputElement[]) {
        if (!element.name || ((element.type === 'checkbox' || element.type === 'radio') && !element.checked)) {
          continue;
        }
        out[element.name] = element.value;
      }

      return { action: form.action || location.href, out };
    });
    fields.out['settings_section[auth_token][value]'] = 'tw-tampered';
    const response = await page.request.post(fields.action, { form: fields.out, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(await stored(page)).toBe('tw-lock-first');

    // Replace: unlocked, empty; untick locks again and drops what was typed.
    await sectionForm(page).locator(REPLACE).check();
    await expect(sectionForm(page).locator(VALUE)).toBeEditable();
    await sectionForm(page).locator(VALUE).fill('typed-then-dropped');
    await sectionForm(page).locator(REPLACE).uncheck();
    await expect(sectionForm(page).locator(VALUE)).not.toBeEditable();
    await expect(sectionForm(page).locator(VALUE)).toHaveValue('');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Nothing changed.')).toBeVisible();
    expect(await stored(page)).toBe('tw-lock-first');

    // Replace ticked: the typed value replaces the stored one.
    await sectionForm(page).locator(REPLACE).check();
    await sectionForm(page).locator(VALUE).fill('tw-lock-replaced');
    await save(page);
    expect(await stored(page)).toBe('tw-lock-replaced');

    // Clear, then type into the normal input that follows.
    await sectionForm(page).locator(CLEAR).check();
    await save(page);
    await expect(sectionForm(page).locator(REPLACE)).toHaveCount(0);
    await sectionForm(page).locator(VALUE).fill('tw-lock-after-clear');
    await save(page);
    expect(await stored(page)).toBe('tw-lock-after-clear');

    // Restore the demo: no stored token.
    await sectionForm(page).locator(CLEAR).check();
    await save(page);
  });
});
