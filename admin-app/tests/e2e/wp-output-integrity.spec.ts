import { expect, test, type Page } from '@playwright/test';
import { ensureSentientFormsSpa, loginToWpAdmin, waitForSentientConfig } from './utils/wp-admin';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

type RawJsonResult = {
	label: string;
	url: string;
	status: number;
	contentType: string;
	text: string;
};

async function openSentientForms(page: Page, hash = '/actions'): Promise<void> {
	await loginToWpAdmin(page);
	await ensureSentientFormsSpa(page, hash);
	await waitForSentientConfig(page);
	await requireWpRestHealthy(page);
}

function expectCleanJson(result: RawJsonResult): void {
	expect(result.status, `${result.label} status for ${result.url}`).toBeLessThan(500);
	expect(result.text.length, `${result.label} response body should not be empty`).toBeGreaterThan(
		0
	);
	expect(result.text.charCodeAt(0), `${result.label} must not start with a UTF-8 BOM`).not.toBe(
		0xfeff
	);
	expect(
		result.text,
		`${result.label} should start with a JSON object or array, not stray output`
	).toMatch(/^\s*[\[{]/);
	expect(() => JSON.parse(result.text), `${result.label} should parse as JSON`).not.toThrow();
}

test.describe('WordPress JSON output integrity', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin JSON output.');

	test('returns clean JSON for wp-auth-check and Sentient Forms Actions page endpoints', async ({
		page
	}) => {
		await openSentientForms(page, '/actions');

		const results = await page.evaluate(async () => {
			const config = window.sentientFormsConfig;
			if (!config) {
				throw new Error('Sentient Forms runtime config missing.');
			}

			const wpWindow = window as Window & {
				ajaxurl?: string;
				heartbeatSettings?: { nonce?: string };
				pagenow?: string;
			};
			const apiBaseUrl = new URL(config.apiBaseUrl, window.location.origin).toString();
			const heartbeatNonce = wpWindow.heartbeatSettings?.nonce ?? '';
			const formSource = config.formSources?.[0]?.slug ?? 'gravity_forms';
			const endpoints = [
				{
					label: 'wp heartbeat auth check',
					url: wpWindow.ajaxurl ?? `${window.location.origin}/wp-admin/admin-ajax.php`,
					init: {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: new URLSearchParams({
							action: 'heartbeat',
							_nonce: heartbeatNonce,
							screen_id: wpWindow.pagenow ?? 'dashboard',
							has_focus: 'true'
						}).toString()
					}
				},
				{
					label: 'settings',
					url: new URL('settings', apiBaseUrl).toString(),
					init: { headers: { 'X-WP-Nonce': config.restNonce } }
				},
				{
					label: 'actions definitions',
					url: new URL('actions/definitions', apiBaseUrl).toString(),
					init: { headers: { 'X-WP-Nonce': config.restNonce } }
				},
				{
					label: 'forms overview',
					url: new URL(`${formSource}/forms/overview`, apiBaseUrl).toString(),
					init: { headers: { 'X-WP-Nonce': config.restNonce } }
				}
			];

			const rawResults: RawJsonResult[] = [];
			for (const endpoint of endpoints) {
				const response = await fetch(endpoint.url, {
					credentials: 'same-origin',
					...endpoint.init
				});
				rawResults.push({
					label: endpoint.label,
					url: response.url || endpoint.url,
					status: response.status,
					contentType: response.headers.get('content-type') ?? '',
					text: await response.text()
				});
			}

			return rawResults;
		});

		for (const result of results) {
			expectCleanJson(result);
		}
	});

	test('admin SPA pages do not surface JSON parse failures', async ({ page }) => {
		const jsonConsoleErrors: string[] = [];
		page.on('console', (message) => {
			if (
				message.type() === 'error' &&
				/(Unexpected token|not valid JSON|invalid JSON)/i.test(message.text())
			) {
				jsonConsoleErrors.push(message.text());
			}
		});

		await loginToWpAdmin(page);

		for (const hash of ['/dashboard', '/actions', '/settings', '/licensing', '/site-context']) {
			await ensureSentientFormsSpa(page, hash);
			await expect(page.getByText(/Unexpected token|not valid JSON|invalid JSON/i)).toHaveCount(0);
			await expect(page.getByText(/Unable to load .* data/i)).toHaveCount(0);
		}

		expect(jsonConsoleErrors, `JSON console errors: ${jsonConsoleErrors.join('\n')}`).toHaveLength(
			0
		);
	});
});
