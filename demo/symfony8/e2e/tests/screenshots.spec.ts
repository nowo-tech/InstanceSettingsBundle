import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { sectionForm, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Instance settings screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1000 } });

  test('settings-section — mailer section with an encrypted DSN (never rendered back)', async ({ page }) => {
    await page.goto('/admin/settings/sections/mailer');
    await expect(sectionForm(page)).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'settings-section.png'), fullPage: true });
  });

  test('settings-portability — versioned export with sealed secrets and an all-or-nothing import', async ({ page }) => {
    await page.goto('/admin/settings/portability');
    await expect(page.locator('form[name="portability_import"]')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'settings-portability.png'), fullPage: true });
  });
});
