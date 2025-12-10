import { expect, test, type Page } from '@playwright/test';
import {
	EntrySpamStatus,
	configureGravityActionMapping,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	findEntryIdByEmail,
	getEntrySpamStatus,
	getLatestEntryId,
	getProxyApiKey,
	requireWpRestHealthy,
	runActionScheduler
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';
import { loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

async function waitForSpamStatus(entryId: number, page: Page): Promise<EntrySpamStatus> {
	let lastStatus: EntrySpamStatus | null = null;

	for (let attempt = 0; attempt < 8; attempt += 1) {
		lastStatus = getEntrySpamStatus(entryId);
		if (lastStatus.status === 'spam' && lastStatus.is_spam) {
			return lastStatus;
		}
		runActionScheduler();
		await page.waitForTimeout(1000);
	}

	throw new Error(`Entry ${entryId} did not reach spam status. Last status: ${JSON.stringify(lastStatus)}`);
}

test.describe('Gravity Forms spam e2e @spam-e2e', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin + Gravity Forms.');

	test.beforeEach(async ({ page }) => {
		if (runWpE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('marks spammy submission, records CPS result, and debits credits', async ({ page }) => {
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
		ensureCreditBalanceAtLeast(20);
		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		expect(balanceBefore).toBeGreaterThanOrEqual(10);

		const baselineEntryId = getLatestEntryId(formId);

		const email = `spam-${Date.now()}@example.test`;
		await loginToWpAdmin(page);
	await requireWpRestHealthy(page);
		await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${formId}`, { waitUntil: 'networkidle' });
		await page.fill('input[name="input_1"]', 'Playwright Bot');
		await page.fill('input[name="input_2"]', email);

		await Promise.all([
			page.waitForSelector('.gform_confirmation_message, .gform_confirmation_wrapper', { timeout: 15000 }),
			page.click('input[type="submit"], button[type="submit"]')
		]);

		runActionScheduler();

		let entryId = baselineEntryId;
		for (let attempt = 0; attempt < 10; attempt += 1) {
			entryId = getLatestEntryId(formId);
			if (entryId > baselineEntryId) {
				break;
			}
			await page.waitForTimeout(1000);
		}

		if (entryId <= baselineEntryId) {
			console.warn(`latestEntryId=${entryId} (<= baseline; attempting email search)`);
			entryId = findEntryIdByEmail(formId, email);
		}

		expect(entryId, 'entry should exist after submission').toBeGreaterThan(baselineEntryId);

		const status = await waitForSpamStatus(entryId, page);
		expect(status.status).toBe('spam');
		expect(status.is_spam).toBe(true);
		expect(status.classification).toBe('spam');

		const balanceAfter = await fetchCreditBalance(page, proxyKey);
		expect(balanceBefore - balanceAfter).toBe(10);
	});
});
