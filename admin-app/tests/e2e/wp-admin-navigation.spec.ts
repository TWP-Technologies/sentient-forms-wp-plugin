import { expect, test } from '@playwright/test';
import { ensureSentientFormsSpa, loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('WordPress admin navigation escapes the Sentient SPA', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin flows.');

	test('visible WordPress admin links still navigate away from the plugin app', async ({ page }) => {
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/dashboard');

		const aboutWordPressLink = page
			.locator(`#wpadminbar a[href="${wpBaseUrl}/wp-admin/about.php"]`)
			.first();

		await expect(aboutWordPressLink).toBeVisible();

		await Promise.all([
			page.waitForURL(`${wpBaseUrl}/wp-admin/about.php`, { timeout: 10000 }),
			aboutWordPressLink.click()
		]);

		await expect(page).toHaveTitle(/About .* WordPress/i);
	});
});
