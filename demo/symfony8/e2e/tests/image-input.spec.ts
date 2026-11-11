import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { sectionForm } from './helpers';

/**
 * InstanceSettings 1.12 — image fields as one card with UiKit 2.47's image input box. The demo `site.logo` field: the
 * stored image (or "No image") in the box, Replace / Remove / Undo buttons, the label once, the help under the box;
 * without JavaScript the plain file input and the remove checkbox.
 */
const card = (page: Page) => sectionForm(page).locator('.nowo-instance-settings-image-card').filter({ has: page.locator('[name="settings_section[logo][file]"]') });
const box = (page: Page) => card(page).locator('[data-nowo-ui-image-input]');

test.describe('Image input box', () => {
  test('one card per image field, label once, help under the box, Remove / Undo', async ({ page }) => {
    await page.goto('/admin/settings/sections/site');
    await expect(card(page)).toHaveCount(1);
    await expect(card(page).locator('label', { hasText: 'Logo' })).toHaveCount(1);
    await expect(card(page).locator('#settings_section_logo_file_help')).toBeVisible();
    await expect(page.locator('[data-testid="instance-settings-image-previews"]')).toHaveCount(0);
    await expect(page.locator('script[src$="/js/nowo-ui-image-input.js"]')).toHaveCount(1);
    await expect(card(page).getByRole('button', { name: /Choose image|Replace image/ })).toBeVisible();

    const state = await box(page).getAttribute('data-nowo-ui-image-input-state');
    if (state === 'stored') {
      // Remove ticks the remove checkbox and can be undone, all before saving.
      await card(page).getByRole('button', { name: 'Remove image' }).click();
      await expect(box(page)).toHaveAttribute('data-nowo-ui-image-input-state', /removed|empty|default/);
      await expect(card(page).locator('input[name="settings_section[logo][remove]"]')).toBeChecked();
      await card(page).getByRole('button', { name: 'Undo' }).click();
      await expect(box(page)).toHaveAttribute('data-nowo-ui-image-input-state', 'stored');
      await expect(card(page).locator('input[name="settings_section[logo][remove]"]')).not.toBeChecked();
    } else {
      await expect(box(page)).toHaveAttribute('data-nowo-ui-image-input-state', 'empty');
      await expect(card(page).getByRole('button', { name: 'Remove image' })).toHaveCount(0);
    }

    const axe = await new AxeBuilder({ page }).include('.nowo-instance-settings-image-card').analyze();
    expect(axe.violations).toEqual([]);
  });
});

test.describe('Image input box without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the plain file input and the remove checkbox are there', async ({ page }) => {
    await page.goto('/admin/settings/sections/site');
    await expect(card(page).locator('input[type="file"][name="settings_section[logo][file]"]')).toBeVisible();
    await expect(card(page).locator('[data-nowo-ui-image-input-replace]')).toBeHidden();
    if ((await box(page).getAttribute('data-nowo-ui-image-input-state')) === 'stored') {
      await expect(card(page).locator('input[name="settings_section[logo][remove]"]')).toBeVisible();
    }
  });
});
