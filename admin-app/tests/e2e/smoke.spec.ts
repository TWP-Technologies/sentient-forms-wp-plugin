import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('root renders @smoke', async ({ page }) => {
	const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
	expect(response, 'Root should respond').toBeTruthy();
	expect(response?.ok(), `Root should return HTTP ${response?.status() ?? 'n/a'}`).toBeTruthy();
	await expect(page).toHaveTitle(/Sentient Forms/i);
	await new AxeBuilder({ page }).analyze();
});

test('admin notice tray relocates injected WordPress notices @smoke', async ({ page }) => {
	await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
	await expect(page.locator('[data-sentient-admin-shell]')).toBeVisible();

	await page.evaluate(() => {
		const frame = document.querySelector('[data-sentient-admin-frame]');
		if (!frame) throw new Error('Sentient Forms admin frame was not rendered.');
		const notice = document.createElement('div');
		notice.className = 'notice notice-warning is-dismissible';
		notice.innerHTML = '<p>Injected WordPress notice</p>';
		frame.prepend(notice);
	});

	await expect(page.getByTestId('wp-notice-tray')).toBeVisible();
	await expect(page.locator('[data-sentient-admin-frame] .notice')).toHaveCount(0);
	await expect(page.getByTestId('wp-notice-tray-content')).toBeHidden();
	await page.getByTestId('wp-notice-tray-toggle').click();
	await expect(page.getByTestId('wp-notice-tray-content')).toContainText('Injected WordPress notice');
});
