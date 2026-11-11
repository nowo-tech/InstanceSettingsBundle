import { test, expect } from '@playwright/test';
import sharp from 'sharp';
import { sectionForm } from './helpers';

/**
 * InstanceSettings 1.8 — crop step on image fields (UiKit 2.43 image crop). The demo `site.logo` field crops to a 3:1
 * band (`withCrop(['aspect' => '3/1', 'max' => 1200])`, field limits 1200 × 400): a 900 × 600 picture is cropped in
 * the browser to 900 × 300, uploaded through the real section form, re-encoded by the server and shown as the stored
 * image in the field's own image input box (1.12). Runs in Chromium (WebP crops) and WebKit (WebP, or PNG / JPEG where the engine cannot encode WebP).
 */
test.describe('Image crop step', () => {
  test('a logo is cropped in the browser and saved through the section form', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/admin/settings/sections/site');
    const input = sectionForm(page).locator('input[name="settings_section[logo][file]"]');
    await expect(input).toHaveAttribute('data-nowo-ui-image-crop', '');
    await expect(input).toHaveAttribute('data-nowo-ui-image-crop-aspect', '3/1');
    await expect(input).toHaveAttribute('data-nowo-ui-image-crop-max', '1200');
    await expect(input).toHaveAttribute('data-nowo-ui-image-crop-max-bytes', '996147');
    await expect(page.locator('script[src$="/js/nowo-ui-image-crop.js"]')).toHaveCount(1);

    // A 900 × 600 picture, too tall for the 1200 × 400 field as it is (a colour of its own on every run, so the
    // re-encoded file is new and the save reports a change).
    const shade = () => Math.floor(Math.random() * 256);
    const picture = await sharp({ create: { width: 900, height: 600, channels: 3, background: { r: shade(), g: shade(), b: shade() } } })
      .png()
      .toBuffer();
    await input.setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: picture });

    const dialog = page.getByRole('dialog', { name: 'Crop the image' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(dialog).toBeHidden();

    // 1.12: the field's image input box shows the crop before anything is uploaded.
    const box = sectionForm(page).locator('#settings_section_logo [data-nowo-ui-image-input]');
    await expect(box).toHaveAttribute('data-nowo-ui-image-input-state', 'picked');
    await expect(box.locator('img')).toHaveAttribute('src', /^blob:/);

    // The input now holds the crop in place of the picked file.
    const cropped = await input.evaluate((element: HTMLInputElement) => {
      const file = element.files?.[0];
      return file ? { name: file.name, type: file.type, size: file.size } : null;
    });
    expect(cropped).not.toBeNull();
    expect(['image/webp', 'image/png', 'image/jpeg']).toContain(cropped?.type);
    expect(cropped?.name).toMatch(/^photo\.(webp|png|jpg)$/);
    expect(cropped?.size).toBeLessThanOrEqual(996147);

    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();

    // The server accepted the crop and stored a 3:1 image of the picture's width (never enlarged); the box (1.12)
    // shows it scaled down, so read the stored image's own size.
    await expect(sectionForm(page).locator('#settings_section_logo [data-nowo-ui-image-input]')).toHaveAttribute('data-nowo-ui-image-input-state', 'stored');
    const preview = sectionForm(page).locator('#settings_section_logo_file_preview');
    await expect(preview).toBeVisible();
    await preview.evaluate((img: HTMLImageElement) => img.decode());
    const size = await preview.evaluate((img: HTMLImageElement) => ({ width: img.naturalWidth, height: img.naturalHeight }));
    expect(size).toEqual({ width: 900, height: 300 });
    expect(errors).toEqual([]);
  });

  test('cancel keeps the input empty and uploads nothing', async ({ page }) => {
    await page.goto('/admin/settings/sections/site');
    const input = sectionForm(page).locator('input[name="settings_section[logo][file]"]');
    const picture = await sharp({ create: { width: 640, height: 480, channels: 3, background: { r: 20, g: 90, b: 160 } } })
      .jpeg()
      .toBuffer();
    await input.setInputFiles({ name: 'other.jpg', mimeType: 'image/jpeg', buffer: picture });

    const dialog = page.getByRole('dialog', { name: 'Crop the image' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(dialog).toBeHidden();
    expect(await input.evaluate((element: HTMLInputElement) => element.files?.length ?? 0)).toBe(0);
  });
});
