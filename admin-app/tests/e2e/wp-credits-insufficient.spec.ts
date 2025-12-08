import { expect, test } from '@playwright/test';
import {
	configureGravityActionMapping,
	ensureGravityForm,
	fetchCreditBalance,
	getLatestEntryId,
	getProxyApiKey,
	setCreditBalance
} from './utils/wp-e2e-helpers';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';
import { loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('Gravity Forms credits @credits-insufficient', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise Gravity Forms + CPS.');

	test('blocks submission when credits are depleted and keeps balance unchanged', async ({ page }) => {
		const formId = ensureGravityForm('Playwright QA Form');
		configureGravityActionMapping({
			formId,
			actionId: 'spam_analysis',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Credits Guard',
			hooks: ['gform_validation'],
			async: false,
			rejectSubmission: true,
			markAsSpam: false,
			executionPriority: 5
		});

		const proxyKey = getProxyApiKey();
		const originalBalance = await fetchCreditBalance(page, proxyKey);
		const baselineEntryId = getLatestEntryId(formId);

		setCreditBalance(0);
		const drainedBalance = await fetchCreditBalance(page, proxyKey);
		expect(drainedBalance).toBeLessThanOrEqual(1);

		const email = `insufficient-${Date.now()}@example.test`;
		await loginToWpAdmin(page);
	await requireWpRestHealthy(page);
		await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${formId}`, { waitUntil: 'networkidle' });
		await page.fill('input[name="input_1"]', 'Playwright Bot');
		await page.fill('input[name="input_2"]', email);

		try {
			await Promise.all([
				page.waitForNavigation({ waitUntil: 'networkidle' }),
				page.click('input[type="submit"], button[type="submit"]')
			]);

			const validationText = await page
				.locator('.validation_error, .gform_validation_errors')
				.innerText()
				.catch(() => '');
			expect(validationText.toLowerCase()).toContain('problem with your submission');

			const latestEntryId = getLatestEntryId(formId);
			expect(latestEntryId).toBe(baselineEntryId);

			const balanceAfter = await fetchCreditBalance(page, proxyKey);
			expect(balanceAfter).toBeLessThanOrEqual(1);
		} finally {
			setCreditBalance(Math.max(originalBalance, 50));
		}
	});
});
