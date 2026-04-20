import { expect, test, type Page } from '@playwright/test';
import {
	type ActionExecutionDebitRecord,
	type WpAsyncMetadataJobRecord,
	configureGravityActionMapping,
	ensureCpsSeeded,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	findEntryIdByEmail,
	getEntryMeta,
	getEntrySpamStatus,
	getLatestActionExecutionDebitByEntryId,
	getLatestWpAsyncMetadataJobByEntryId,
	getLatestEntryId,
	requireWpRestHealthy,
	runActionScheduler,
	submitGravityForm
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';

const runLegacyCpsE2E =
	process.env.SENTIENT_RUN_WP_E2E === '1' &&
	process.env.SENTIENT_RUN_LEGACY_CPS_E2E === '1';

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
		if (record?.job_id) {
			return record;
		}
		runActionScheduler();
		await page.waitForTimeout(1000);
	}

	throw new Error(
		`WP async metadata job for ${centralActionId} not found on entry ${entryId}. Last record: ${JSON.stringify(record)}`
	);
}

function extractSummaryText(payload: unknown): string {
	if (typeof payload === 'string') {
		return payload.trim();
	}

	if (!payload || typeof payload !== 'object') {
		return '';
	}

	const record = payload as Record<string, unknown>;
	const resultData =
		record.result_data && typeof record.result_data === 'object'
			? (record.result_data as Record<string, unknown>)
			: null;
	const structuredOutput =
		resultData?.structured_output && typeof resultData.structured_output === 'object'
			? (resultData.structured_output as Record<string, unknown>)
			: null;

	const candidates = [
		record.result_summary,
		record.llm_output,
		record.text,
		resultData?.summary,
		resultData?.llm_output,
		structuredOutput?.summary,
		structuredOutput?.text
	];

	for (const candidate of candidates) {
		if (typeof candidate === 'string' && candidate.trim().length > 0) {
			return candidate.trim();
		}
	}

	return '';
}

async function waitForSummaryText(entryId: number, page: Page): Promise<string> {
	let lastPayload: unknown = null;

	for (let attempt = 0; attempt < 12; attempt += 1) {
		lastPayload = getEntryMeta(entryId, 'sentient_forms_last_response');
		const summaryText = extractSummaryText(lastPayload);
		if (summaryText.length > 0) {
			return summaryText;
		}
		runActionScheduler();
		await page.waitForTimeout(1000);
	}

	throw new Error(
		`Summary response not stored for entry ${entryId}. Last payload: ${JSON.stringify(lastPayload)}`
	);
}

test.describe('After-submission entry summary @after-submission @summary-e2e', () => {
	test.skip(
		!runLegacyCpsE2E,
		'Set SENTIENT_RUN_WP_E2E=1 and SENTIENT_RUN_LEGACY_CPS_E2E=1 to exercise legacy Gravity Forms + CPS summary credit flows.'
	);

	test.beforeEach(async ({ page }) => {
		if (runLegacyCpsE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('stores entry summary output and debits only entry_summary_v1 credits once', async ({ page }) => {
		const token = String(Date.now());
		const formId = ensureGravityForm('Playwright QA Summary Form', [
			{ type: 'text', id: 1, label: 'Name', isRequired: true },
			{ type: 'email', id: 2, label: 'Email', isRequired: true },
			{ type: 'textarea', id: 3, label: 'Project Details', isRequired: true }
		]);
		configureGravityActionMapping({
			formId,
			actionId: 'entry_summary',
			centralActionId: 'entry_summary_v1',
			actionNameLabel: 'Playwright Entry Summary',
			hooks: ['gform_after_submission'],
			async: true,
			executionPriority: 5,
			actionTypeIndicator: 'master'
		});

		const proxyKey = ensureCpsSeeded();
		ensureCreditBalanceAtLeast(50);
		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		const baselineEntryId = getLatestEntryId(formId);
		const email = `summary-${token}@example.test`;

		await submitGravityForm(page, formId, 'Playwright Summary Lead', email, {
			'3': 'Need pricing and onboarding details for a 50-seat rollout next month.'
		});
		runActionScheduler();

		const entryId = await waitForNewEntryId(formId, baselineEntryId, email, page);
		expect(entryId).toBeGreaterThan(baselineEntryId);

		const asyncJob = await waitForWpAsyncJob(entryId, 'entry_summary_v1', page);
		expect(asyncJob.central_action_id).toBe('entry_summary_v1');
		expect(asyncJob.hook).toBe('sentient_forms_process_action');
		expect((asyncJob.execution_request_id ?? '').length).toBeGreaterThan(0);

		const debitRecord = await waitForActionDebit(entryId, 'entry_summary_v1', page);
		expect(debitRecord.central_action_id).toBe('entry_summary_v1');
		expect(debitRecord.hook).toBe('gform_after_submission');
		expect(debitRecord.credits_delta).toBe(-8);

		const summaryText = await waitForSummaryText(entryId, page);
		expect(summaryText.length).toBeGreaterThan(0);
		expect(summaryText).toContain('Playwright Summary Lead');
		expect(summaryText).toContain('50-seat rollout');
		expect(summaryText).not.toContain('spam ::');

		const spamStatus = getEntrySpamStatus(entryId);
		expect(spamStatus.status).not.toBe('spam');
		expect(spamStatus.is_spam).not.toBe(true);
		expect(spamStatus.classification).toBeNull();

		expect(getEntryMeta(entryId, 'sentient_forms_spam_classification')).toBeNull();
		const balanceAfter = await fetchCreditBalance(page, proxyKey);
		expect(Math.round(balanceBefore - balanceAfter)).toBe(8);
	});
});
