import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { sectionForm } from './helpers';

/**
 * InstanceSettings 1.12 — colour field (SettingType::Color). The demo `site` section has `accent_color` (default
 * #2563eb) whose preset follows the Theme choice (light #1d4ed8, dark #93c5fd, auto #2563eb). The hex input is the
 * submitted value; the native swatch mirrors it both ways; "Use the preset colour" empties it.
 */
const hex = (page: Page) => sectionForm(page).locator('input[name="settings_section[accent_color]"]');
const swatch = (page: Page) => sectionForm(page).locator('#settings_section_accent_color_swatch');
const reset = (page: Page) => sectionForm(page).getByRole('button', { name: 'Use the preset colour' });

async function save(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText(/Settings saved\.|Nothing changed\./)).toBeVisible();
}

test.describe('Colour field', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/settings/sections/site');
    await sectionForm(page).getByLabel('Theme').selectOption('auto');
    await hex(page).fill('');
    await save(page);
  });

  test('swatch and hex stay in sync, reset follows the preset', async ({ page }) => {
    // The preset (auto) is saved as null: the hex input is empty, the swatch shows the preset.
    await expect(hex(page)).toHaveValue('');
    await expect(hex(page)).toHaveAttribute('placeholder', '#2563eb');
    await expect(swatch(page)).toBeVisible();
    await expect(swatch(page)).toHaveValue('#2563eb');
    await expect(reset(page)).toBeHidden();
    await expect(sectionForm(page).getByLabel('Accent colour')).toHaveCount(2); // swatch + hex, one label

    // Typing a colour updates the swatch.
    await hex(page).fill('#FF0000');
    await expect(swatch(page)).toHaveValue('#ff0000');
    await expect(reset(page)).toBeVisible();

    // Picking in the swatch writes the hex input.
    await swatch(page).fill('#00aa11');
    await expect(hex(page)).toHaveValue('#00aa11');

    // The preset follows the theme select live while the colour is empty.
    await reset(page).click();
    await expect(hex(page)).toHaveValue('');
    await expect(hex(page)).toBeFocused();
    await expect(swatch(page)).toHaveValue('#2563eb');
    await sectionForm(page).getByLabel('Theme').selectOption('dark');
    await expect(swatch(page)).toHaveValue('#93c5fd');
    await expect(hex(page)).toHaveAttribute('placeholder', '#93c5fd');

    const axe = await new AxeBuilder({ page }).include('form[name="settings_section"]').analyze();
    expect(axe.violations).toEqual([]);
  });

  test('saving the preset stores nothing, a custom colour is kept lower-cased', async ({ page }) => {
    await swatch(page).fill('#2563eb');
    await expect(hex(page)).toHaveValue('#2563eb');
    await save(page);
    await expect(hex(page)).toHaveValue('');

    await hex(page).fill('#ABCDEF');
    await save(page);
    await expect(hex(page)).toHaveValue('#abcdef');
    await expect(swatch(page)).toHaveValue('#abcdef');

    await hex(page).fill('red');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(sectionForm(page).getByText('This value is not a #rrggbb colour.')).toBeVisible();
    await expect(swatch(page)).toHaveValue('#2563eb');
  });
});

test.describe('Colour field without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the hex input works alone', async ({ page }) => {
    await page.goto('/admin/settings/sections/site');
    await expect(swatch(page)).toBeHidden();
    await expect(sectionForm(page).locator('[data-nowo-instance-settings-color-reset]')).toBeHidden();
    await hex(page).fill('#123456');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(hex(page)).toHaveValue('#123456');
    await hex(page).fill('');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(hex(page)).toHaveValue('');
  });
});
