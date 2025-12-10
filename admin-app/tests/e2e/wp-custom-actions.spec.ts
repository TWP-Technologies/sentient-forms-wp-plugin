import { expect, test } from '@playwright/test';
import {
	configureGravityActionMapping,
	createCustomAction,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	getEntryMeta,
	getLatestEntryId,
	getProxyApiKey,
	runActionScheduler,
	requireWpRestHealthy,
	submitGravityForm,
	waitForEntryMeta
} from './utils/wp-e2e-helpers'
import { installSentientCorsProxy } from './utils/cors-proxy';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('Custom actions end-to-end @custom-actions', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise Gravity Forms + CPS.');
	test('executes custom action with template overrides', async ({ page }) => {
		const formId = ensureGravityForm('Playwright QA Form');
		const actionCode = `pw_custom_${Date.now()}`;
		const actionCost = 7;
		createCustomAction(actionCode, 'Playwright Custom Action', actionCost);

		configureGravityActionMapping({
			formId,
			actionId: 'spam_analysis',
			centralActionId: actionCode,
			actionNameLabel: 'Playwright Custom Action',
			hooks: ['gform_after_submission'],
			async: true,
			rejectSubmission: false,
			markAsSpam: true,
			executionPriority: 5,
			actionTypeIndicator: 'custom'
		});

		const proxyKey = getProxyApiKey();
		ensureCreditBalanceAtLeast(50);
		await requireWpRestHealthy(page);
		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		const baselineEntryId = getLatestEntryId(formId);

		await submitGravityForm(page, formId, 'Playwright Bot', `custom-${Date.now()}@example.test`);
		runActionScheduler();

		let entryId = baselineEntryId;
		for (let attempt = 0; attempt < 10; attempt += 1) {
			entryId = getLatestEntryId(formId);
			if (entryId > baselineEntryId) break;
			await page.waitForTimeout(1000);
		}
		expect(entryId).toBeGreaterThan(baselineEntryId);

		await waitForEntryMeta(
			entryId,
			'_sentient_forms_spam_analysis',
			page,
			(value) => !!value,
			15
		);

		const meta = getEntryMeta(entryId, '_sentient_forms_spam_analysis') as Record<string, unknown>;
		expect(meta).toBeTruthy();

		const balanceAfter = await fetchCreditBalance(page, proxyKey);
		expect(Math.round(balanceBefore - balanceAfter)).toBe(actionCost);
	});
});
