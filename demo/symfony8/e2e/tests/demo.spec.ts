import { test, expect } from '@playwright/test';
import { sectionForm } from './helpers';

/** REQ-DEMO-013 — happy path of the settings UI (sections, secrets, import / export). Data: `make seed`. */
test.describe('Instance settings demo', () => {
  test('home page reads the settings', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('demo-values')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open the settings' })).toBeVisible();
  });

  test('index redirects to the first section and shows one tab per section', async ({ page }) => {
    await page.goto('/admin/settings');
    await expect(page).toHaveURL(/\/admin\/settings\/sections\/site$/);
    for (const tab of ['Site', 'Mailer', 'SMS']) {
      await expect(page.getByRole('link', { name: tab, exact: true }).first()).toBeVisible();
    }
  });

  test('saving a section applies on the next request', async ({ page }) => {
    await page.goto('/admin/settings/sections/site');
    const form = sectionForm(page);
    await form.getByLabel('Rows per page').fill('40');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();

    await page.goto('/');
    await expect(page.getByTestId('demo-page-size')).toHaveText('40');

    await page.goto('/admin/settings/sections/site');
    await sectionForm(page).getByLabel('Rows per page').fill('25');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
  });

  test('a stored secret is never rendered back and a blank field keeps it', async ({ page }) => {
    await page.goto('/admin/settings/sections/mailer');
    const html = await page.content();
    expect(html).not.toContain('REDACTED-DEMO');
    await expect(sectionForm(page).locator('input[type="password"]')).toHaveValue('');

    await sectionForm(page).getByLabel('Sender name').fill('Acme Ops');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.goto('/');
    await expect(page.getByText('stored (encrypted)')).toBeVisible();
  });

  test('an out-of-range value is refused with a field error', async ({ page }) => {
    await page.goto('/admin/settings/sections/site');
    await sectionForm(page).getByLabel('Rows per page').fill('900');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('This value should be between 5 and 200.')).toBeVisible();
  });

  test('export downloads a versioned envelope without secrets in clear', async ({ page }) => {
    await page.goto('/admin/settings/portability');
    const download = page.waitForEvent('download');
    await page.locator('form[name="portability_export"]').getByRole('button', { name: 'Download JSON' }).click();
    const file = await (await download).path();
    const { readFileSync } = await import('node:fs');
    const payload = JSON.parse(readFileSync(file, 'utf8'));
    expect(payload.schema).toBe('nowo-instance-settings');
    expect(payload.settings.site.name).toBeTruthy();
    expect(JSON.stringify(payload)).not.toContain('REDACTED-DEMO');
  });

  test('a forged CSRF token is refused', async ({ request }) => {
    const response = await request.post('/admin/settings/sections/site', { form: { 'settings_section[_token]': 'forged', 'settings_section[name]': 'Evil' } });
    expect(response.status()).toBe(422);
  });
});
