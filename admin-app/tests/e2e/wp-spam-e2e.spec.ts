import { expect, test, type Page } from '@playwright/test';
import {
	type ActionExecutionDebitRecord,
	type CapturedMailRecord,
	type EntrySpamStatus,
	type GravityEntryNoteRecord,
	clearCapturedMail,
	configureGravityActionMapping,
	ensureCpsSeeded,
	ensureCreditBalanceAtLeast,
	ensureGravityForm,
	fetchCreditBalance,
	findEntryIdByEmail,
	getCapturedMailRecords,
	getEntryMeta,
	getEntrySpamStatus,
	getGravityEntryNotes,
	getLatestActionExecutionDebitByEntryId,
	getLatestEntryId,
	requireWpRestHealthy,
	runActionScheduler,
	submitGravityForm
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

type NotificationConfig = {
	id: string;
	name: string;
	subject: string;
	to: string;
	message: string;
};

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

async function waitForCapturedMail(page: Page, predicate: (records: CapturedMailRecord[]) => boolean): Promise<CapturedMailRecord[]> {
	let lastRecords: CapturedMailRecord[] = [];

	for (let attempt = 0; attempt < 8; attempt += 1) {
		lastRecords = getCapturedMailRecords();
		if (predicate(lastRecords)) {
			return lastRecords;
		}
		runActionScheduler();
		await page.waitForTimeout(500);
	}

	throw new Error(`Captured mail predicate not satisfied. Last records: ${JSON.stringify(lastRecords)}`);
}

function buildNotification(config: NotificationConfig) {
	return {
		id: config.id,
		name: config.name,
		to: config.to,
		subject: config.subject,
		message: config.message,
		from: 'no-reply@example.test',
		fromName: 'Playwright Mail Capture',
		event: 'form_submission',
		messageFormat: 'html' as const,
		service: 'wordpress' as const
	};
}

function filterSpamAnalysisNotes(notes: GravityEntryNoteRecord[]): GravityEntryNoteRecord[] {
	return notes.filter(
		(note) =>
			note.user_name === 'Sentient Forms AI' &&
			note.value.includes('Sentient Forms AI classified this entry as SPAM')
	);
}

async function waitForSpamAnalysisNotes(entryId: number, page: Page): Promise<GravityEntryNoteRecord[]> {
	let lastNotes: GravityEntryNoteRecord[] = [];

	for (let attempt = 0; attempt < 8; attempt += 1) {
		lastNotes = filterSpamAnalysisNotes(getGravityEntryNotes(entryId));
		if (lastNotes.length > 0) {
			return lastNotes;
		}
		runActionScheduler();
		await page.waitForTimeout(750);
	}

	throw new Error(`Spam analysis note not found for entry ${entryId}. Last notes: ${JSON.stringify(lastNotes)}`);
}

test.describe('Gravity Forms spam e2e @spam-e2e', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin + Gravity Forms.');

	test.beforeEach(async ({ page }) => {
		if (runWpE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('captures Gravity Forms notifications when spam suppression is not active', async ({ page }) => {
		const token = String(Date.now());
		const notificationSubject = `Playwright Notification Control ${token}`;
		const formId = ensureGravityForm('Playwright QA Mail Control Form', undefined, {
			notifications: [
				buildNotification({
					id: 'playwright_control_notification',
					name: 'Playwright Control Notification',
					subject: notificationSubject,
					to: 'qa-control@example.test',
					message: 'Control notification for {Name:1} <{Email:2}>'
				})
			]
		});
		const baselineEntryId = getLatestEntryId(formId);
		const email = `control-${token}@example.test`;

		clearCapturedMail();
		await submitGravityForm(page, formId, 'Playwright Control', email);

		const entryId = await waitForNewEntryId(formId, baselineEntryId, email, page);
		expect(entryId, 'control entry should exist after submission').toBeGreaterThan(baselineEntryId);

		const mailRecords = await waitForCapturedMail(
			page,
			(records) => records.some((record) => record.subject === notificationSubject)
		);
		const matchingRecord = mailRecords.find((record) => record.subject === notificationSubject);

		expect(matchingRecord).toBeDefined();
		expect(matchingRecord?.to).toContain('qa-control@example.test');
		expect(matchingRecord?.message).toContain('Playwright Control');
		expect(matchingRecord?.message).toContain(email);
	});

	test('marks spammy submission, writes one structured spam note, suppresses notifications, and debits credits', async ({
		page
	}) => {
		const token = String(Date.now());
		const notificationSubject = `Playwright Spam Notification ${token}`;
		const formId = ensureGravityForm('Playwright QA Form', undefined, {
			notifications: [
				buildNotification({
					id: 'playwright_spam_notification',
					name: 'Playwright Spam Notification',
					subject: notificationSubject,
					to: 'qa-spam@example.test',
					message: 'Spam notification should be suppressed for {Email:2}'
				})
			]
		});
		configureGravityActionMapping({
			formId,
			actionId: 'spam_analysis',
			centralActionId: 'spam_detection_v1',
			actionNameLabel: 'Playwright Spam Detection',
			hooks: ['gform_after_submission'],
			async: true,
			rejectSubmission: false,
			markAsSpam: true,
			executionPriority: 10,
			additionalSettings: {
				spam_result_display_mode: 'entry_note',
				spam_indicators_display: 'detailed'
			}
		});
		const proxyKey = ensureCpsSeeded();
		ensureCreditBalanceAtLeast(20);
		const balanceBefore = await fetchCreditBalance(page, proxyKey);
		expect(balanceBefore).toBeGreaterThanOrEqual(10);

		const baselineEntryId = getLatestEntryId(formId);
		const email = `spam-${token}@example.test`;

		clearCapturedMail();
		await submitGravityForm(page, formId, 'Playwright Bot', email);
		runActionScheduler();

		const entryId = await waitForNewEntryId(formId, baselineEntryId, email, page);
		expect(entryId, 'entry should exist after submission').toBeGreaterThan(baselineEntryId);

		const status = await waitForSpamStatus(entryId, page);
		expect(status.status).toBe('spam');
		expect(status.is_spam).toBe(true);
		expect(status.classification).toBe('spam');

		const balanceAfter = await fetchCreditBalance(page, proxyKey);
		expect(balanceBefore - balanceAfter).toBe(10);

		let debitRecord: ActionExecutionDebitRecord | null = null;
		for (let attempt = 0; attempt < 20; attempt += 1) {
			debitRecord = getLatestActionExecutionDebitByEntryId(entryId, 'spam_detection_v1');
			if (debitRecord?.execution_request_id) {
				break;
			}
			runActionScheduler();
			await page.waitForTimeout(1000);
		}
		expect(debitRecord, 'action execution debit should exist for spam_detection_v1').not.toBeNull();
		expect(debitRecord?.central_action_id).toBe('spam_detection_v1');
		expect(debitRecord?.hook).toBe('gform_after_submission');
		expect((debitRecord?.execution_request_id ?? '').length).toBeGreaterThan(0);

		expect(String(getEntryMeta(entryId, 'sentient_forms_spam_classification') ?? '')).toBe('spam');
		const lastResponse = getEntryMeta(entryId, 'sentient_forms_last_response') as
			| {
					result_data?: {
						classification?: string;
						confidence?: number;
						justification?: string;
						indicators?: Array<{ type?: string; evidence?: string; weight?: string }>;
					};
			  }
			| null;
		expect(lastResponse?.result_data?.classification).toBe('spam');
		expect(lastResponse?.result_data?.confidence).toBe(0.99);
		expect(lastResponse?.result_data?.justification).toContain('local mock harness');
		expect(lastResponse?.result_data?.indicators?.length ?? 0).toBeGreaterThan(0);

		for (let attempt = 0; attempt < 3; attempt += 1) {
			runActionScheduler();
			await page.waitForTimeout(750);
		}

		const spamNotes = await waitForSpamAnalysisNotes(entryId, page);
		expect(spamNotes).toHaveLength(1);
		expect(spamNotes[0]?.value).toContain('Sentient Forms AI classified this entry as SPAM');
		expect(spamNotes[0]?.value).not.toContain('::');
		expect(spamNotes[0]?.value).not.toContain('{"classification"');
		expect(spamNotes[0]?.value).toContain('local mock harness');
		expect(spamNotes[0]?.value).toContain('Signals Detected:');
		expect(spamNotes[0]?.value).toContain('Promotional Language');

		const noteSections = spamNotes[0]?.value.split(/\n\s*\n/) ?? [];
		expect(noteSections.length).toBeGreaterThan(1);
		expect(noteSections[1]?.trim().length ?? 0).toBeGreaterThan(0);

		const mailRecords = getCapturedMailRecords();
		expect(mailRecords.some((record) => record.subject === notificationSubject)).toBe(false);
		expect(mailRecords).toHaveLength(0);
	});
});
