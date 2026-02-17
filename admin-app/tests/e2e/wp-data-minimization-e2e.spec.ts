import { expect, test, type Page } from '@playwright/test';
import {
	configureGravityActionMapping,
	ensureCpsSeeded,
	ensureGravityForm,
	findEntryIdByEmail,
	getLatestAsyncExecutionJobByEntryId,
	getLatestEntryId,
	requireWpRestHealthy,
	type AsyncExecutionJobRecord
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';
import { loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

async function waitForAsyncJob(
	entryId: number,
	centralActionId: string,
	page: Page
): Promise<AsyncExecutionJobRecord> {
	let lastJob: AsyncExecutionJobRecord | null = null;

	for (let attempt = 0; attempt < 12; attempt += 1) {
		lastJob = getLatestAsyncExecutionJobByEntryId(entryId, centralActionId);
		if (lastJob) {
			return lastJob;
		}

		await page.waitForTimeout(1000);
	}

	throw new Error(
		`No async execution job found for entry ${entryId}. Last job: ${JSON.stringify(lastJob)}`
	);
}

test.describe('Data minimization e2e @data-minimization-e2e', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin + Gravity Forms.');

	test.beforeEach(async ({ page }) => {
		if (runWpE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('queues mapped fields only and propagates input manifest context', async ({ page }) => {
		const formId = ensureGravityForm('Playwright Data Minimization Form');
		const actionCode = 'spam_detection_v1';
		configureGravityActionMapping({
			formId,
			actionId: 'spam_analysis_data_min',
			centralActionId: actionCode,
			actionNameLabel: 'Data Minimization Mapping',
			hooks: ['gform_after_submission'],
			async: true,
			rejectSubmission: false,
			markAsSpam: false,
			executionPriority: 10,
			inputMapping: {
				mode: 'selected',
				fieldIds: ['2'],
				includeMetadata: false
			},
			batchSettings: {
				enabled: true,
				delaySeconds: 60,
				maxWaitSeconds: 43200
			}
		});

		ensureCpsSeeded();
		const baselineEntryId = getLatestEntryId(formId);
		const email = `minimized-${Date.now()}@example.test`;

		await loginToWpAdmin(page);
		await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${formId}`, { waitUntil: 'networkidle' });
		await page.fill('input[name="input_1"]', 'Payload Minimizer');
		await page.fill('input[name="input_2"]', email);

		await Promise.all([
			page.waitForSelector('.gform_confirmation_message, .gform_confirmation_wrapper', { timeout: 15000 }),
			page.click('input[type="submit"], button[type="submit"]')
		]);

		let entryId = baselineEntryId;
		for (let attempt = 0; attempt < 10; attempt += 1) {
			entryId = getLatestEntryId(formId);
			if (entryId > baselineEntryId) {
				break;
			}
			await page.waitForTimeout(1000);
		}

		if (entryId <= baselineEntryId) {
			entryId = findEntryIdByEmail(formId, email);
		}

		expect(entryId, 'entry should exist after submission').toBeGreaterThan(baselineEntryId);

		const job = await waitForAsyncJob(entryId, actionCode, page);
		expect(job.execution_request_id.length).toBeGreaterThan(0);

		const payload = job.form_data_payload as Record<string, unknown>;
		const entryPayload = (payload.entry ?? {}) as Record<string, unknown>;
		expect(payload.form).toBeUndefined();
		expect(entryPayload['2']).toBe(email);
		expect(entryPayload['1']).toBeUndefined();
		expect(Object.keys(entryPayload)).toEqual(['2']);

		const actionContext = job.action_context as Record<string, unknown>;
		const manifest = actionContext.input_manifest as Record<string, unknown> | undefined;
		expect(manifest).toBeTruthy();
		expect(manifest?.mapping_source).toBe('explicit_mapping');
		expect(manifest?.mode).toBe('selected');
		expect(manifest?.include_metadata).toBe(false);
		expect(manifest?.full_entry_sent).toBe(false);
		expect(manifest?.requested_field_ids).toEqual(['2']);
		expect(manifest?.applied_entry_keys).toEqual(['2']);
	});
});
