import { expect, test } from '@playwright/test';
import { ensurePlaywrightFixtures } from './utils/wp-fixtures';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';
import {
	configureGravityActionMapping,
	ensureGravityForm,
	getGravityActionSettings,
	requireWpRestHealthy
} from './utils/wp-e2e-helpers';
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
		const appReady = await page.evaluate(() => window.sentientFormsAppReady ?? null);
		expect(config).toBeTruthy();
		expect(appReady).toBe('ready');
		expect(Array.isArray(config?.formSources)).toBeTruthy();
		expect(config?.license?.status).toBeDefined();
		await expect(page.getByRole('heading', { name: 'Local workspace' })).toBeVisible();
		await expect(page.locator('nav a[data-nav-path="/dashboard"]')).toHaveClass(/sf-bg-slate-200/);

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

	test('keeps hash, rendered view, and active sidebar state aligned under rapid nav clicks', async ({
		page
	}) => {
		await openSentientForms(page, '/dashboard');

		const customActionsLink = page.locator('nav a[href="#/actions/custom"]');
		const dashboardLink = page.locator('nav a[href="#/dashboard"]');

		await customActionsLink.click();
		await page.waitForTimeout(120);
		await dashboardLink.click();
		await page.waitForTimeout(1000);

		const state = await page.evaluate(() => {
			const heading =
				document.querySelector('main h2')?.textContent?.trim() ??
				document.querySelector('main h1')?.textContent?.trim() ??
				null;
			const activeLinks = Array.from(document.querySelectorAll('nav a'))
				.filter((link) => link.className.includes('sf-bg-slate-200'))
				.map((link) => link.textContent?.trim() ?? '');
			return {
				hash: window.location.hash,
				heading,
				activeLinks
			};
		});

		expect(state.hash).toBe('#/dashboard');
		expect(state.heading).toBe('Local workspace');
		expect(state.activeLinks).toEqual(['Dashboard']);
	});

	test('replaces stale mappings by default and merges only when explicitly requested', async ({ page }) => {
		await requireWpRestHealthy(page);
		const formId = ensureGravityForm(`Playwright Mapping Isolation ${Date.now()}`);

		configureGravityActionMapping({
			formId,
			actionId: 'spam_gate',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Playwright Spam Gate',
			localMappingId: 'map-spam-gate',
			hooks: ['gform_after_submission'],
			async: true,
			markAsSpam: true
		});

		let settings = getGravityActionSettings(formId);
		expect(Object.keys(settings.actions ?? {})).toEqual(['map-spam-gate']);
		expect(settings['map-spam-gate']).toBeDefined();

		configureGravityActionMapping({
			formId,
			actionId: 'summary_only',
			centralActionId: 'entry_summary_v1',
			actionNameLabel: 'Playwright Summary Only',
			localMappingId: 'map-summary-only',
			hooks: ['gform_after_submission'],
			async: true
		});

		settings = getGravityActionSettings(formId);
		expect(Object.keys(settings.actions ?? {})).toEqual(['map-summary-only']);
		expect(settings['map-summary-only']).toBeDefined();
		expect(settings['map-spam-gate']).toBeUndefined();

		configureGravityActionMapping({
			formId,
			actionId: 'spam_gate_again',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Playwright Spam Gate Again',
			localMappingId: 'map-spam-gate-again',
			hooks: ['gform_after_submission'],
			async: true,
			markAsSpam: true,
			mergeWithExistingMappings: true
		});

		settings = getGravityActionSettings(formId);
		expect(Object.keys(settings.actions ?? {}).sort()).toEqual([
			'map-spam-gate-again',
			'map-summary-only'
		]);
		expect(settings['map-summary-only']).toBeDefined();
		expect(settings['map-spam-gate-again']).toBeDefined();
	});

	test('opens provider edit links outside the Svelte router', async ({ page }) => {
		await openSentientForms(page, `/actions/gravity_forms/${seededFormId}`);

		const link = page.getByTestId('actions-provider-edit-link');
		await expect(link).toHaveAttribute('data-sveltekit-reload', '');
		await expect(link).toHaveAttribute('rel', /external/);
		await expect(link).toHaveAttribute(
			'href',
			new RegExp(`admin\\.php\\?page=gf_edit_forms&id=${seededFormId}`)
		);

		await link.click();

		await expect(page).toHaveURL(
			new RegExp(`/wp-admin/admin\\.php\\?page=gf_edit_forms&id=${seededFormId}`)
		);
		expect(page.url()).not.toContain('sentient-forms-actions');
	});
	});
