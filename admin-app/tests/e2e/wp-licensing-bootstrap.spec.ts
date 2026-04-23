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

	test('licensing bootstraps the free license on first open', async ({
		page
	}) => {
		ensureWpBaseUrlConfigured();
		prepareFreeLicenseBootstrapState();

		await requireWpRestHealthy(page);
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/licensing');

		await expect(page.getByRole('heading', { name: 'License management' })).toBeVisible();
		await expect(page.getByTestId('licensing-status-headline')).toContainText('Active and connected');
		await expect(page.getByTestId('licensing-status-badge')).toContainText('active');
		await expect(page.getByText('Tier: Free')).toBeVisible();

		expect(getProxyApiKey().trim().length).toBeGreaterThan(0);
	});
});
