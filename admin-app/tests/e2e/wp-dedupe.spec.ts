import { expect, test } from '@playwright/test';
import {
	countActionExecutionDebitsByRequestId,
	configureGravityActionMapping,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	getLatestEntryId,
	getProxyApiKey,
	runActionScheduler,
	setExecutionRequestIdOverride,
	submitGravityForm,
	requireWpRestHealthy
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';

const runLegacyCpsE2E =
	process.env.SENTIENT_RUN_WP_E2E === '1' &&
	process.env.SENTIENT_RUN_LEGACY_CPS_E2E === '1';

test.describe('Duplicate execution guard @dedupe', () => {
	test.skip(
		!runLegacyCpsE2E,
		'Set SENTIENT_RUN_WP_E2E=1 and SENTIENT_RUN_LEGACY_CPS_E2E=1 to exercise legacy Gravity Forms + CPS dedupe flows.'
	);

	test.beforeEach(async ({ page }) => {
		if (runLegacyCpsE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

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

		try {
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
			expect(balanceAfterFirst - balanceAfterSecond).toBe(0);

			const debitCount = countActionExecutionDebitsByRequestId(fixedExecutionId);
			expect(debitCount).toBe(1);

			const latestEntryId = getLatestEntryId(formId);
			expect(latestEntryId).toBeGreaterThan(baselineEntryId);
		} finally {
			setExecutionRequestIdOverride(null);
		}

	});
});
