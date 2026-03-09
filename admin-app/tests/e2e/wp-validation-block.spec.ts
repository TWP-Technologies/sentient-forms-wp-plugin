import { expect, test } from '@playwright/test';
import {
	configureGravityActionMapping,
	ensureCpsSeeded,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	getLatestEntryId,
	requireWpRestHealthy,
	waitForPreviewInputs
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';
import { loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('Gravity Forms content validation block @validation-block', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise Gravity Forms + CPS.');

	test.beforeEach(async ({ page }) => {
		if (runWpE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('blocks low-effort submissions and allows detailed submissions through the same action', async ({
		page
	}) => {
		const token = String(Date.now());
		const formId = ensureGravityForm('Playwright QA Content Validation Form', [
			{ type: 'text', id: 1, label: 'Name', isRequired: true },
			{ type: 'email', id: 2, label: 'Email', isRequired: true },
			{ type: 'textarea', id: 3, label: 'Project Details', isRequired: true }
		]);
		configureGravityActionMapping({
			formId,
			actionId: 'content_validation',
			centralActionId: 'content_validation_v1',
			actionNameLabel: 'Content Quality Validation',
			hooks: ['gform_validation'],
			async: false,
			executionPriority: 1
		});

		ensureCpsSeeded();
		ensureCreditBalanceAtLeast(50);
		const baselineEntryId = getLatestEntryId(formId);
		const email = `validation-${token}@example.test`;

		await loginToWpAdmin(page);
		await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${formId}`, { waitUntil: 'domcontentloaded' });
		await waitForPreviewInputs(page, formId);

		await page.fill('input[name="input_1"]', 'Validation Tester');
		await page.fill('input[name="input_2"]', email);
		await page.fill('textarea[name="input_3"]', 'short');
		await page.click('input[type="submit"], button[type="submit"]');

		const formValidationMessage = page
			.locator('.gform_validation_errors, .validation_error, .gform_validation_error')
			.first();
		const fieldValidationMessage = page
			.locator('.gfield_error .gfield_validation_message, .gfield_error .validation_message')
			.first();

		await expect(formValidationMessage).toContainText(/problem with your submission/i);
		await expect(fieldValidationMessage).toContainText(/tell us more/i);
		expect(getLatestEntryId(formId)).toBe(baselineEntryId);
		await expect(page.locator('.gform_confirmation_message, .gform_confirmation_wrapper')).toHaveCount(
			0
		);

		await page.fill(
			'textarea[name="input_3"]',
			'We need onboarding support, pricing guidance, and implementation planning for a 50-seat rollout next month.'
		);
		await Promise.all([
			page.click('input[type="submit"], button[type="submit"]'),
			page.waitForSelector('.gform_confirmation_message, .gform_confirmation_wrapper', {
				timeout: 15000
			})
		]);

		expect(getLatestEntryId(formId)).toBeGreaterThan(baselineEntryId);
		await expect(
			page.locator('.gform_confirmation_message, .gform_confirmation_wrapper').first()
		).toBeVisible();
		await expect(formValidationMessage).toHaveCount(0);
	});
});
