import { expect, test } from '@playwright/test';
import {
	configureGravityActionMapping,
	createCustomAction,
	ensureCpsSeeded,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	getActionTemplateBaseCreditCost,
	getLatestEntryId,
	requireWpRestHealthy,
	waitForPreviewInputs
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';
import { loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runLegacyCpsE2E =
	process.env.SENTIENT_RUN_WP_E2E === '1' &&
	process.env.SENTIENT_RUN_LEGACY_CPS_E2E === '1';

test.describe('Gravity Forms validation spam gate @validation-block @spam-e2e', () => {
	test.skip(
		!runLegacyCpsE2E,
		'Set SENTIENT_RUN_WP_E2E=1 and SENTIENT_RUN_LEGACY_CPS_E2E=1 to exercise legacy Gravity Forms + CPS validation spam-gate flows.'
	);

	test.beforeEach(async ({ page }) => {
		if (runLegacyCpsE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('skips the dependent validation action when upstream spam detection marks the submission as spam', async ({
		page
	}) => {
		const token = String(Date.now());
		const customActionCode = `playwright_validation_skip_${token}`;
		const formId = ensureGravityForm('Playwright QA Validation Spam Gate Form', [
			{ type: 'text', id: 1, label: 'Name', isRequired: true },
			{ type: 'email', id: 2, label: 'Email', isRequired: true },
			{ type: 'textarea', id: 3, label: 'Project Details', isRequired: true }
		]);
		configureGravityActionMapping({
			formId,
			actionId: 'validation_spam_gate',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Validation Spam Gate',
			localMappingId: 'map-validation-spam-gate',
			hooks: ['gform_validation'],
			async: false,
			rejectSubmission: true,
			executionPriority: 5
		});
		configureGravityActionMapping({
			formId,
			actionId: 'validation_custom_check',
			centralActionId: customActionCode,
			actionNameLabel: 'Validation Custom Check',
			localMappingId: 'map-validation-custom-check',
			hooks: ['gform_validation'],
			async: false,
			executionPriority: 15,
			dependencyIds: ['map-validation-spam-gate'],
			triggerSources: {
				gform_validation: { type: 'mapping', mapping_id: 'map-validation-spam-gate' }
			},
			actionTypeIndicator: 'custom',
			skipOnUpstreamSpam: true,
			mergeWithExistingMappings: true
		});

		const proxyKey = ensureCpsSeeded();
		createCustomAction(customActionCode, `Playwright Validation Skip ${token}`);
		const spamCost = getActionTemplateBaseCreditCost('spam_detection_v1');
		ensureCreditBalanceAtLeast(spamCost + 50);
		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		const baselineEntryId = getLatestEntryId(formId);

		await loginToWpAdmin(page);
		await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${formId}`, { waitUntil: 'domcontentloaded' });
		await waitForPreviewInputs(page, formId);

		await page.fill('input[name="input_1"]', 'Validation Spam Tester');
		await page.fill('input[name="input_2"]', `validation-spam-${token}@example.test`);
		await page.fill(
			'textarea[name="input_3"]',
			'WIN BIG NOW!!! Claim your free crypto prize immediately at http://spam.test and reply with your banking details.'
		);
		await page.click('input[type="submit"], button[type="submit"]');

		expect(getLatestEntryId(formId)).toBeGreaterThan(baselineEntryId);
		await expect(
			page.locator('.gform_confirmation_message, .gform_confirmation_wrapper').first()
		).toBeVisible();

		const balanceAfterSpam = await fetchCreditBalance(page, proxyKey);
		expect(Math.round(balanceBefore - balanceAfterSpam)).toBe(spamCost);
	});
});
