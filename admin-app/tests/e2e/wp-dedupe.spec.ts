import { expect, test } from '@playwright/test';
import {
	configureGravityActionMapping,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	getLatestEntryId,
	getProxyApiKey,
	runActionScheduler,
	setExecutionRequestIdOverride,
	submitGravityForm
} from './utils/wp-e2e-helpers';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('Duplicate execution guard @dedupe', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise Gravity Forms + CPS.');

	test('second run with same execution_request_id does not debit again', async ({ page }) => {
		const formId = ensureGravityForm('Playwright QA Form');
		configureGravityActionMapping({
			formId,
			actionId: 'spam_analysis',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Playwright Spam Detection',
			hooks: ['gform_after_submission'],
			async: true,
			rejectSubmission: false,
			markAsSpam: true,
			executionPriority: 10
		});

		const proxyKey = getProxyApiKey();
		ensureCreditBalanceAtLeast(40);
		const fixedExecutionId = `pw-dedupe-${Date.now()}`;
		setExecutionRequestIdOverride(fixedExecutionId);

		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		const baselineEntryId = getLatestEntryId(formId);

		await submitGravityForm(page, formId, 'Playwright Bot', `dedupe-${Date.now()}@example.test`);
		runActionScheduler();
		await page.waitForTimeout(2000);
		const balanceAfterFirst = await fetchCreditBalance(page, proxyKey);
		expect(balanceBefore - balanceAfterFirst).toBe(10);

		await submitGravityForm(page, formId, 'Playwright Bot', `dedupe-${Date.now()}@example.test`);
		runActionScheduler();
		await page.waitForTimeout(2000);
		const balanceAfterSecond = await fetchCreditBalance(page, proxyKey);
		expect(balanceAfterSecond).toBe(balanceAfterFirst);

		const latestEntryId = getLatestEntryId(formId);
		expect(latestEntryId).toBeGreaterThan(baselineEntryId);

		setExecutionRequestIdOverride(null);
	});
});
