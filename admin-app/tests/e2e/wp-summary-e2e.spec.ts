import { expect, test } from '@playwright/test';
import {
	configureGravityActionMapping,
	ensureCpsSeeded,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	getEntryMeta,
	getLatestEntryId,
	runActionScheduler,
	submitGravityForm,
	waitForEntryMeta,
	requireWpRestHealthy
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('After-submission spam async @after-submission @summary-e2e', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise Gravity Forms + CPS.');

	test.beforeEach(async ({ page }) => {
		if (runWpE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('stores spam analysis meta and debits credits once', async ({ page }) => {
		const formId = ensureGravityForm('Playwright QA Form');
		configureGravityActionMapping({
			formId,
			actionId: 'spam_analysis',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Playwright Spam Async',
			hooks: ['gform_after_submission'],
			async: true,
			markAsSpam: true,
			executionPriority: 5,
			actionTypeIndicator: 'master'
		});

		const proxyKey = ensureCpsSeeded();
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

		// Wait for the async handler to store the spam classification meta
		const classification = await waitForEntryMeta(
			entryId,
			'sentient_forms_spam_classification',
			page,
			(value) => !!value
		);

		expect(classification).toBeTruthy();

		// Also verify the full CPS response is stored
		const lastResponse = getEntryMeta(entryId, 'sentient_forms_last_response');
		expect(lastResponse).toBeTruthy();

		const balanceAfter = await fetchCreditBalance(page, proxyKey);
		expect(Math.round(balanceBefore - balanceAfter)).toBe(10);
	});
});
