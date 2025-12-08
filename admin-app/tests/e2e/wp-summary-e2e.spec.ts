import { expect, test } from '@playwright/test';
import {
	configureGravityActionMapping,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	getLatestEntryId,
	getProxyApiKey,
	runActionScheduler,
	submitGravityForm,
	waitForEntryMeta
} from './utils/wp-e2e-helpers';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('After-submission spam async @after-submission @summary-e2e', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise Gravity Forms + CPS.');

	test('stores spam analysis meta and debits credits once', async ({ page }) => {
		const formId = ensureGravityForm('Playwright QA Form');
		configureGravityActionMapping({
			formId,
			actionId: 'spam_analysis',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Playwright Spam Async',
			hooks: ['gform_after_submission'],
			async: true,
			executionPriority: 5,
			actionTypeIndicator: 'master'
		});

		const proxyKey = getProxyApiKey();
		ensureCreditBalanceAtLeast(50);
		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		const baselineEntryId = getLatestEntryId(formId);

		await submitGravityForm(page, formId, 'Playwright Bot', `summary-${Date.now()}@example.test`);
		runActionScheduler();

		let entryId = baselineEntryId;
		for (let attempt = 0; attempt < 10; attempt += 1) {
			entryId = getLatestEntryId(formId);
			if (entryId > baselineEntryId) {
				break;
			}
			await page.waitForTimeout(1000);
		}
		expect(entryId).toBeGreaterThan(baselineEntryId);

		const meta = (await waitForEntryMeta(
			entryId,
			'_sentient_forms_spam_analysis',
			page,
			(value) => !!value
		)) as Record<string, unknown> | string;

		const metaObj = typeof meta === 'string' ? (() => { try { return JSON.parse(meta); } catch { return {}; } })() : meta;
		expect(metaObj).toBeTruthy();

		const classification =
			(metaObj as Record<string, unknown>)?.['classification'] ??
			(metaObj as Record<string, unknown>)?.['result']?.['classification'];
		expect(classification).toBeDefined();

		const balanceAfter = await fetchCreditBalance(page, proxyKey);
		expect(Math.round(balanceBefore - balanceAfter)).toBe(10);
	});
});
