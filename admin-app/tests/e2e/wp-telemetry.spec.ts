import { expect, test } from '@playwright/test';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('WordPress local diagnostic settings', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin diagnostic flows.');
	test('toggles local diagnostic events via wp-admin settings route', async ({ page }) => {
		await loginToWpAdmin(page);
		await requireWpRestHealthy(page);
		await ensureSentientFormsSpa(page, '/settings');
		const spaRoot = page.locator('#sentient-forms-admin-app');
		await expect(spaRoot.getByText('Enable local diagnostic events')).toBeVisible();
		await expect(spaRoot.getByText('Nothing is sent to Sentient Forms.')).toBeVisible();

		const toggle = spaRoot.locator('input[type="checkbox"]').first();
		await expect(toggle).toBeEnabled();
		if (await toggle.isChecked()) {
			await toggle.click();
			await expect(toggle).not.toBeChecked();
			await expect(spaRoot.getByText('Off', { exact: true })).toBeVisible();
		}
		await toggle.click();

		await expect(toggle).toBeChecked();
		await expect(spaRoot.getByText('On', { exact: true })).toBeVisible();
	});
});
