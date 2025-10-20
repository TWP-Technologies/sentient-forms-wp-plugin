import { test, expect } from '@playwright/test';

test('dashboard renders', async ({ page }) => {
	await page.goto('/dashboard');
	await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
	await expect(page.getByText('License status', { exact: false })).toBeVisible();
});
