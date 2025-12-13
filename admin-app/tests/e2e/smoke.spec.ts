import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('root renders @smoke', async ({ page }) => {
	const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
	expect(response, 'Root should respond').toBeTruthy();
	expect(response?.ok(), `Root should return HTTP ${response?.status() ?? 'n/a'}`).toBeTruthy();
	await expect(page).toHaveTitle(/Sentient Forms/i);
	await new AxeBuilder({ page }).analyze();
});
