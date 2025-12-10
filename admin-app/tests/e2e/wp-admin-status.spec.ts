import { expect, test } from '@playwright/test';
import { getFormStatusOption, setFormStatusOption } from './utils/wp-e2e-helpers'
import { requireWpRestHealthy } from './utils/wp-e2e-helpers'
import { installSentientCorsProxy } from './utils/cors-proxy';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';
const runSpaSmoke = process.env.SENTIENT_RUN_WP_SPA_SMOKE === '1';

test.describe('Admin status UI @admin-status', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise WordPress admin SPA.');
	test('exposes latest form status via stored option', async () => {
		const formId = 3;
		const message = `Playwright status banner ${Date.now()}`;
		setFormStatusOption(formId, 'error', message);

		const statusPayload = getFormStatusOption(formId);
		expect(statusPayload?.status).toBe('error');
		expect(statusPayload?.message).toBe(message);
	});

	test('lightweight SPA mount (optional)', async ({ page }) => {
		test.skip(!runSpaSmoke, 'Enable SENTIENT_RUN_WP_SPA_SMOKE=1 to exercise SPA mount.');
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/dashboard');
		await expect(page.locator('#sentient-forms-admin-app')).toBeVisible();
	});
});
