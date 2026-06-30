import { expect, test, type Page } from '@playwright/test';
import {
	type ActionExecutionDebitRecord,
	type EntrySpamStatus,
	type GravityEntryNoteRecord,
	type WpAsyncMetadataJobRecord,
	configureGravityActionMapping,
	ensureCpsSeeded,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	findEntryIdByEmail,
	getActionExecutionDebitsByEntryId,
	getActionTemplateBaseCreditCost,
	getEntrySpamStatus,
	getGravityEntryNotes,
	getLatestActionExecutionDebitByEntryId,
	getLatestEntryId,
	getLatestWpAsyncMetadataJobByEntryId,
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

async function waitForSpamStatus(entryId: number, page: Page): Promise<EntrySpamStatus> {
	let lastStatus: EntrySpamStatus | null = null;

	for (let attempt = 0; attempt < 10; attempt += 1) {
		lastStatus = getEntrySpamStatus(entryId);
		if (lastStatus.status === 'spam' && lastStatus.is_spam) {
			return lastStatus;
		}
		runActionScheduler();
		await page.waitForTimeout(1000);
	}

	throw new Error(
		`Entry ${entryId} did not reach spam status. Last status: ${JSON.stringify(lastStatus)}`
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

function filterSummarySkipNotes(notes: GravityEntryNoteRecord[]): GravityEntryNoteRecord[] {
	return notes.filter((note) => /skip/i.test(note.value) && /summary/i.test(note.value));
}

async function waitForSpamSkipOutcome(
	entryId: number,
	page: Page
): Promise<{ summaryJob: WpAsyncMetadataJobRecord | null; skipNotes: GravityEntryNoteRecord[] }> {
	let summaryJob: WpAsyncMetadataJobRecord | null = null;
	let skipNotes: GravityEntryNoteRecord[] = [];

	for (let attempt = 0; attempt < 10; attempt += 1) {
		summaryJob = getLatestWpAsyncMetadataJobByEntryId(
			entryId,
			'entry_summary_v1',
			'sentient_forms_process_action'
		);
		skipNotes = filterSummarySkipNotes(getGravityEntryNotes(entryId));
		const summaryDebits = getActionExecutionDebitsByEntryId(entryId, 'entry_summary_v1');
		if (summaryDebits.length > 0) {
			throw new Error(
				`entry_summary_v1 should not debit for spam entry ${entryId}: ${JSON.stringify(summaryDebits)}`
			);
		}
		if (summaryJob?.status === 'skipped' || skipNotes.length > 0) {
			return { summaryJob, skipNotes };
		}
		runActionScheduler();
		await page.waitForTimeout(1000);
	}

	throw new Error(
		`No explicit skip evidence recorded for entry_summary_v1 on spam entry ${entryId}. Last job: ${JSON.stringify(summaryJob)}, notes: ${JSON.stringify(skipNotes)}`
	);
}

test.describe('Spam-gated summary chain @after-submission @spam-e2e @summary-e2e', () => {
	test.skip(
		!runLegacyCpsE2E,
		'Set SENTIENT_RUN_WP_E2E=1 and SENTIENT_RUN_LEGACY_CPS_E2E=1 to exercise legacy Gravity Forms + CPS chained credit flows.'
	);

	test.beforeEach(async ({ page }) => {
		if (runLegacyCpsE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('does not spend entry_summary_v1 credits when upstream spam detection marks the entry as spam', async ({
		page
	}) => {
		const token = String(Date.now());
		const formId = ensureGravityForm('Playwright QA Spam Summary Chain Form');
		configureGravityActionMapping({
			formId,
			actionId: 'spam_gate',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Playwright Spam Gate',
			localMappingId: 'map-spam-gate',
			hooks: ['gform_after_submission'],
			async: true,
			markAsSpam: true,
				executionPriority: 5,
				additionalSettings: {
					spam_result_display_mode: 'all_results',
					spam_indicators_display: 'detailed'
				}
		});
		configureGravityActionMapping({
			formId,
			actionId: 'summary_after_spam',
			centralActionId: 'entry_summary_v1',
			actionNameLabel: 'Playwright Summary After Spam Gate',
			localMappingId: 'map-summary-after-spam',
			hooks: ['gform_after_submission'],
			async: true,
			executionPriority: 15,
			dependencyIds: ['map-spam-gate'],
			triggerSources: {
				gform_after_submission: { type: 'mapping', mapping_id: 'map-spam-gate' }
			},
			skipOnUpstreamSpam: true,
			mergeWithExistingMappings: true
		});

		const proxyKey = ensureCpsSeeded();
		ensureCreditBalanceAtLeast(80);
		const spamBaseCreditCost = getActionTemplateBaseCreditCost('spam_detection_v1');
		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		const baselineEntryId = getLatestEntryId(formId);
		const email = `spam-summary-chain-${token}@example.test`;

		await submitGravityForm(page, formId, 'Playwright Bot', email);
		runActionScheduler();

		const entryId = await waitForNewEntryId(formId, baselineEntryId, email, page);
		expect(entryId).toBeGreaterThan(baselineEntryId);

		const spamStatus = await waitForSpamStatus(entryId, page);
		expect(spamStatus.classification).toBe('spam');

		const spamDebit = await waitForActionDebit(entryId, 'spam_detection_v1', page);
		expect(spamDebit.central_action_id).toBe('spam_detection_v1');
		expect(spamDebit.credits_delta).toBeLessThan(0);
		expect(spamDebit.hook).toBe('gform_after_submission');

		const spamDebitedCredits = Math.abs(spamDebit.credits_delta);
		expect(spamDebitedCredits).toBeGreaterThanOrEqual(spamBaseCreditCost);

		const skipOutcome = await waitForSpamSkipOutcome(entryId, page);
		expect(getActionExecutionDebitsByEntryId(entryId, 'entry_summary_v1')).toHaveLength(0);
		if (skipOutcome.summaryJob) {
			expect(skipOutcome.summaryJob.status).toBe('skipped');
			expect(skipOutcome.summaryJob.hook).toBe('sentient_forms_process_action');
			expect(skipOutcome.summaryJob.central_action_id).toBe('entry_summary_v1');
		}
		expect(skipOutcome.skipNotes.length > 0 || skipOutcome.summaryJob?.status === 'skipped').toBe(true);

		const balanceAfter = await fetchCreditBalance(page, proxyKey);
		expect(Math.round(balanceBefore - balanceAfter)).toBe(spamDebitedCredits);
	});
});
