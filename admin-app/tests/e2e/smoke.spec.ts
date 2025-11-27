import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('root renders @smoke', async ({ page }) => {
	const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
	expect(response?.status(), 'Root should respond').toBeLessThan(500);
	// Minimal smoke: ensure page is loaded; skip DOM structure assumptions.
	await page.waitForTimeout(500);
	await new AxeBuilder({ page }).analyze();
});
