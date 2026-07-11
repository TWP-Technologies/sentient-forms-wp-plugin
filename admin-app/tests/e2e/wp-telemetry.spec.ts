import { expect, test } from '@playwright/test';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('WordPress local diagnostic settings', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin telemetry flows.');
	test('toggles consent via wp-admin settings route', async ({ page }) => {
		await loginToWpAdmin(page);
		await requireWpRestHealthy(page);
		await ensureSentientFormsSpa(page, '/settings');
		const spaRoot = page.locator('#sentient-forms-admin-app');
		await expect(spaRoot.getByText('Allow local diagnostic events')).toBeVisible();
		await expect(spaRoot).toContainText('Nothing is sent off-site in this release.');

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
