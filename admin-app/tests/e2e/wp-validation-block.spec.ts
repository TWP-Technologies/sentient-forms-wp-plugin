import { expect, test } from '@playwright/test';
import {
	configureGravityActionMapping,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	getLatestEntryId,
	getProxyApiKey,
	submitGravityForm
} from './utils/wp-e2e-helpers';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('Gravity Forms validation block @validation-block', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise Gravity Forms + CPS.');

	test('rejects submission during validation and debits once', async ({ page }) => {
		const formId = ensureGravityForm('Playwright QA Form');
		configureGravityActionMapping({
			formId,
			actionId: 'spam_analysis',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Validation Spam Block',
			hooks: ['gform_validation'],
			async: false,
			rejectSubmission: true,
			markAsSpam: false,
			executionPriority: 1
		});

		const proxyKey = getProxyApiKey();
		ensureCreditBalanceAtLeast(50);
		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		const baselineEntryId = getLatestEntryId(formId);

		await submitGravityForm(page, formId, 'Playwright Bot', `validation-${Date.now()}@example.test`);

		// stay on form page; no confirmation expected
		await page.waitForTimeout(2000);
		const latestEntryId = getLatestEntryId(formId);
		expect(latestEntryId).toBe(baselineEntryId);

		const balanceAfter = await fetchCreditBalance(page, proxyKey);
		expect(Math.round(balanceBefore - balanceAfter)).toBe(10);
	});
});
