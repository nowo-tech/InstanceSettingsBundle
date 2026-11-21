import { type Page } from '@playwright/test';

/** The Symfony Web Debug Toolbar is not part of the demo platform: hide it so it never appears in a capture. */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
  await new Promise((r) => setTimeout(r, 300));
}

/** The section form of the settings scaffold. */
export function sectionForm(page: Page) {
  return page.locator('form[name="settings_section"]');
}
