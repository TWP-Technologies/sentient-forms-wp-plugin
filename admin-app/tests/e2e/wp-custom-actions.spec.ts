import { expect, test, type Page } from '@playwright/test';
import {
	type ActionExecutionDebitRecord,
	type GravityEntryNoteRecord,
	type WpAsyncMetadataJobRecord,
	configureGravityActionMapping,
	createCustomAction,
	ensureCpsSeeded,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	getActionTemplateBaseCreditCost,
	ensureWpBaseUrlConfigured,
	fetchCreditBalance,
	findEntryIdByEmail,
	getEntryMeta,
	getGravityEntryNotes,
	getLatestActionExecutionDebitByEntryId,
	getLatestEntryId,
	getLatestWpAsyncMetadataJobByEntryId,
	runActionScheduler,
	requireWpRestHealthy,
	submitGravityForm
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

async function waitForNewEntryId(
	formId: number,
	baselineEntryId: number,
	email: string,
	page: Page
): Promise<number> {
	let entryId = baselineEntryId;

	for (let attempt = 0; attempt < 10; attempt += 1) {
		entryId = getLatestEntryId(formId);
		if (entryId > baselineEntryId) {
			return entryId;
		}
		await page.waitForTimeout(1000);
	}

	return findEntryIdByEmail(formId, email);
}

async function waitForWpAsyncJob(
	entryId: number,
	centralActionId: string,
	page: Page,
	maxAttempts = 12
): Promise<WpAsyncMetadataJobRecord> {
	let record: WpAsyncMetadataJobRecord | null = null;

	for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
		record = getLatestWpAsyncMetadataJobByEntryId(
			entryId,
			centralActionId,
			'sentient_forms_process_action'
		);
		if (record?.job_id && record.status === 'success') {
			return record;
		}
		runActionScheduler();
		await page.waitForTimeout(1000);
	}

	throw new Error(
		`WP async metadata job for ${centralActionId} not found on entry ${entryId}. Last record: ${JSON.stringify(record)}`
	);
}

async function waitForActionDebit(
	entryId: number,
	centralActionId: string,
	page: Page,
	maxAttempts = 12
): Promise<ActionExecutionDebitRecord> {
	let record: ActionExecutionDebitRecord | null = null;

	for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
		record = getLatestActionExecutionDebitByEntryId(entryId, centralActionId);
		if (record?.execution_request_id) {
			return record;
		}
		runActionScheduler();
		await page.waitForTimeout(1000);
	}

	throw new Error(
		`Action debit for ${centralActionId} not found on entry ${entryId}. Last record: ${JSON.stringify(record)}`
	);
}

function filterSpamAnalysisNotes(notes: GravityEntryNoteRecord[]): GravityEntryNoteRecord[] {
	return notes.filter(
		(note) =>
			note.user_name === 'Sentient Forms AI' &&
			note.value.includes('Sentient Forms AI classified this entry as SPAM')
	);
}

async function waitForSpamAnalysisNotes(
	entryId: number,
	page: Page,
	maxAttempts = 10
): Promise<GravityEntryNoteRecord[]> {
	let notes: GravityEntryNoteRecord[] = [];

	for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
		notes = filterSpamAnalysisNotes(getGravityEntryNotes(entryId));
		if (notes.length > 0) {
			return notes;
		}
		runActionScheduler();
		await page.waitForTimeout(1000);
	}

	throw new Error(`Spam analysis note not found for entry ${entryId}. Last notes: ${JSON.stringify(notes)}`);
}

test.describe('Custom actions end-to-end @custom-actions', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise Gravity Forms + CPS.');

	test.beforeEach(async ({ page }) => {
		if (runWpE2E) {
			ensureWpBaseUrlConfigured();
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('executes custom action with template overrides', async ({ page }) => {
		const formId = ensureGravityForm('Playwright QA Form');
		const actionCode = `pw_custom_${Date.now()}`;
		const expectedDebit = getActionTemplateBaseCreditCost('spam_detection_v1');
		const token = String(Date.now());
		const email = `custom-${token}@example.test`;
		createCustomAction(actionCode, 'Playwright Custom Action');

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

		const proxyKey = ensureCpsSeeded();
		ensureCreditBalanceAtLeast(50);
		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		const baselineEntryId = getLatestEntryId(formId);

		await submitGravityForm(page, formId, 'Playwright Bot', email);
		runActionScheduler();

		const entryId = await waitForNewEntryId(formId, baselineEntryId, email, page);
		expect(entryId).toBeGreaterThan(baselineEntryId);

		const asyncJob = await waitForWpAsyncJob(entryId, actionCode, page);
		expect(asyncJob.central_action_id).toBe(actionCode);
		expect(asyncJob.hook).toBe('sentient_forms_process_action');
		expect(asyncJob.status).toBe('success');
		expect((asyncJob.execution_request_id ?? '').length).toBeGreaterThan(0);

		const debitRecord = await waitForActionDebit(entryId, actionCode, page);
		expect(debitRecord.central_action_id).toBe(actionCode);
		expect(debitRecord.action_template_code).toBe('spam_detection_v1');
		expect(debitRecord.hook).toBe('gform_after_submission');
		expect(debitRecord.credits_delta).toBe(-expectedDebit);

		const lastResponse = getEntryMeta(entryId, 'sentient_forms_last_response') as
			| {
					result_data?: {
						classification?: string;
						confidence?: number;
						justification?: string;
					};
			  }
			| null;
		expect(lastResponse?.result_data?.classification).toBe('spam');
		expect(lastResponse?.result_data?.confidence).toBe(0.99);
		expect(lastResponse?.result_data?.justification).toContain('local mock harness');

		const spamNotes = await waitForSpamAnalysisNotes(entryId, page);
		expect(spamNotes).toHaveLength(1);
		expect(spamNotes[0]?.value).toContain('Sentient Forms AI classified this entry as SPAM');
		expect(spamNotes[0]?.value).toContain('local mock harness');

		const balanceAfter = await fetchCreditBalance(page, proxyKey);
		expect(Math.round(balanceBefore - balanceAfter)).toBe(expectedDebit);
	});
});
