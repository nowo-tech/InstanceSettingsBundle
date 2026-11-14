import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { sectionForm } from './helpers';

/**
 * InstanceSettings 1.17 — expiring secrets. The demo SMS auth token declares `expires: true`: a date row follows the
 * secret, a warning shows on the section and next to the field from 30 days before the date, and saving a new secret
 * with the date left as it was clears the date.
 */
const DATE = '[name="settings_section[auth_token:expires_on]"]';

function inDays(days: number): string {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

async function save(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Settings saved.')).toBeVisible();
}

test.describe('Expiring secrets', () => {
  test('the date row, the warnings and the new-secret rule', async ({ page }) => {
    await page.goto('/admin/settings/sections/sms');
    const form = sectionForm(page);
    await form.getByLabel('Provider').selectOption('twilio');

    // A new secret with a date five days ahead: both stored, warnings on the section and next to the field.
    const soon = inDays(5);
    await form.locator('[name="settings_section[auth_token][value]"]').fill('tw-e2e-expiring-token');
    await form.locator(DATE).fill(soon);
    await save(page);
    await expect(sectionForm(page).locator(DATE)).toHaveValue(soon);
    await expect(page.getByTestId('instance-settings-notice').filter({ hasText: 'Auth token' })).toContainText(`expires on ${soon}`);
    await expect(sectionForm(page).locator('[data-nowo-instance-settings-expiry-for="auth_token"]')).toHaveAttribute('data-nowo-instance-settings-expiry', 'expiring');
    await expect(sectionForm(page).locator('[data-nowo-instance-settings-expiry-for="auth_token"]')).toContainText(`Expires on ${soon}`);
    await expect(page.locator('body')).not.toContainText('tw-e2e-expiring-token');
    const axe = await new AxeBuilder({ page }).include('form[name="settings_section"]').analyze();
    expect(axe.violations).toEqual([]);

    // A date far ahead: no warning.
    await sectionForm(page).locator(DATE).fill(inDays(200));
    await save(page);
    await expect(page.getByTestId('instance-settings-notice').filter({ hasText: 'Auth token' })).toHaveCount(0);

    // A rotated secret with the date left as it was: the date is cleared. 1.17 reveal_in_input: the stored secret is
    // locked, so the new value needs "Replace the stored value".
    await sectionForm(page).locator('[name="settings_section[auth_token:replace]"]').check();
    await sectionForm(page).locator('[name="settings_section[auth_token][value]"]').fill('tw-e2e-rotated-token');
    await save(page);
    await expect(sectionForm(page).locator(DATE)).toHaveValue('');

    // Restore the demo: no stored token.
    await sectionForm(page).locator('[name="settings_section[auth_token][clear]"]').check();
    await save(page);
  });
});
