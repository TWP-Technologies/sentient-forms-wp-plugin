import { expect, test } from '@playwright/test';
import { ensurePlaywrightFixtures } from './utils/wp-fixtures';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

let seededFormId: number;

test.beforeAll(() => {
	if (!runWpE2E) {
		return;
	}
	seededFormId = ensurePlaywrightFixtures();
});

async function openSentientForms(page: Parameters<typeof test>[0]['page'], hash = '/dashboard') {
	await loginToWpAdmin(page);
	await ensureSentientFormsSpa(page, hash);
	await requireWpRestHealthy(page);
}

test.describe('Sentient Forms admin actions', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise Gravity Forms admin flows.');
	test.beforeEach(async ({ page }) => {
		if (runWpE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});
	test('exposes runtime config for licensing/navigation', async ({ page }) => {
		await openSentientForms(page);
		const config = await page.evaluate(() => window.sentientFormsConfig);
		expect(config).toBeTruthy();
		expect(Array.isArray(config?.formSources)).toBeTruthy();
		expect(config?.license?.status).toBeDefined();

		await page.evaluate(() => {
			window.location.hash = '#/licensing';
		});
		await expect(page).toHaveURL(/#\/licensing$/);
	});

	test('REST endpoint returns seeded form actions', async ({ page }) => {
		await openSentientForms(page);
		const restNonce = await page.evaluate(() => window.sentientFormsConfig?.restNonce);
		const result = await page.evaluate(
			async ({ formId, nonce }) => {
				const url = `${window.location.origin}/index.php?rest_route=/sentient-forms/v1/gravity_forms/forms/${formId}/actions`;
				const response = await fetch(
					url,
					{
						headers: {
							'X-WP-Nonce': nonce ?? ''
						}
					}
				);

				const payload = response.ok ? await response.json() : await response.text();
				return { ok: response.ok, status: response.status, payload };
			},
			{ formId: seededFormId, nonce: restNonce }
		);

		if (!result.ok) {
			throw new Error(`REST request failed: ${result.status}: ${result.payload}`);
		}
		const data = result.payload as { action_name_label?: string }[];
		expect(Array.isArray(data)).toBeTruthy();
		expect(JSON.stringify(data)).toContain('Playwright Spam Detection');
	});
});
