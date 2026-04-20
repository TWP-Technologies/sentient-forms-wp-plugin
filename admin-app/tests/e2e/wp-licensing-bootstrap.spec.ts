import { expect, test } from '@playwright/test';
import {
	ensureWpBaseUrlConfigured,
	prepareFreeLicenseBootstrapState,
	getProxyApiKey,
	requireWpRestHealthy
} from './utils/wp-e2e-helpers';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runLegacyCpsE2E =
	process.env.SENTIENT_RUN_WP_E2E === '1' &&
	process.env.SENTIENT_RUN_LEGACY_CPS_E2E === '1';

test.describe('Licensing bootstrap in real WP admin @licensing-wp', () => {
	test.skip(
		!runLegacyCpsE2E,
		'Set SENTIENT_RUN_WP_E2E=1 and SENTIENT_RUN_LEGACY_CPS_E2E=1 to exercise legacy CPS licensing bootstrap.'
	);

	test('dashboard auto-bootstraps the free license without visiting licensing first', async ({
		page
	}) => {
		ensureWpBaseUrlConfigured();
		prepareFreeLicenseBootstrapState();

		await requireWpRestHealthy(page);
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/dashboard');

		await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
		await expect(page.getByTestId('dashboard-license-summary')).toContainText('License active');
		await expect(page.getByTestId('dashboard-license-status')).toContainText('active');
		await expect(page.getByTestId('dashboard-tier-card')).toContainText('Free');

		expect(getProxyApiKey().trim().length).toBeGreaterThan(0);
	});
});
