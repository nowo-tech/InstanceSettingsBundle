import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { sectionForm } from './helpers';

/**
 * InstanceSettings 1.3 — provider-dependent fields (`visible_when`, UiKit `visible-when` peer). The demo `sms`
 * section shows Account id / Auth token for Twilio and SMS Bridge, Bridge base URL for SMS Bridge only. Hidden rows
 * keep their values: switching provider, saving and switching back shows the stored values again.
 */
const row = (page: Page, name: string) => sectionForm(page).locator(`[data-nowo-ui-visible-when-field]:has([name="settings_section[${name}]"])`);

/** axe on the section form (rows shown and hidden); the card intro contrast belongs to the demo theme. */
async function expectNoAxeViolations(page: Page): Promise<void> {
  const axe = await new AxeBuilder({ page }).include('form[name="settings_section"]').analyze();
  expect(axe.violations).toEqual([]);
}

test.describe('Provider-dependent fields', () => {
  test('switching provider hides fields without losing their values', async ({ page }) => {
    await page.goto('/admin/settings/sections/sms');
    const form = sectionForm(page);
    await expect(page.getByTestId('instance-settings-section')).toHaveAttribute('data-controller', 'visible-when');

    // Twilio: account id visible, bridge URL hidden (hidden + inert + aria-hidden, input untouched).
    await form.getByLabel('Provider').selectOption('twilio');
    await form.getByLabel('Account id').fill('AC-E2E-KEEP');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await expect(row(page, 'account_id')).toBeVisible();
    await expect(row(page, 'bridge_url')).toBeHidden();
    await expect(row(page, 'bridge_url')).toHaveAttribute('aria-hidden', 'true');
    await expectNoAxeViolations(page);

    // Disabled: every provider row hidden; save keeps the hidden values.
    await sectionForm(page).getByLabel('Provider').selectOption('null');
    await expect(row(page, 'account_id')).toBeHidden();
    await expect(row(page, 'account_id')).toHaveAttribute('inert', '');
    await expectNoAxeViolations(page);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await expect(sectionForm(page).getByLabel('Provider')).toHaveValue('null');
    await expect(row(page, 'account_id')).toBeHidden();

    // Back to Twilio: the stored account id is still there.
    await sectionForm(page).getByLabel('Provider').selectOption('twilio');
    await expect(row(page, 'account_id')).toBeVisible();
    await expect(sectionForm(page).getByLabel('Account id')).toHaveValue('AC-E2E-KEEP');
    await page.reload();
    await expect(sectionForm(page).getByLabel('Account id')).toHaveValue('AC-E2E-KEEP');

    // SMS Bridge shows its URL row too.
    await sectionForm(page).getByLabel('Provider').selectOption('sms_bridge');
    await expect(row(page, 'bridge_url')).toBeVisible();
    await expectNoAxeViolations(page);

    // Restore the seeded provider.
    await sectionForm(page).getByLabel('Provider').selectOption('twilio');
    await sectionForm(page).getByLabel('Account id').fill('AC-DEMO-ACCOUNT');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
  });
});
