import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('dashboard renders', async ({ page }) => {
	await page.goto('/dashboard');
	await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
	await expect(page.getByText('License status', { exact: false })).toBeVisible();
	await new AxeBuilder({ page }).analyze();
});
