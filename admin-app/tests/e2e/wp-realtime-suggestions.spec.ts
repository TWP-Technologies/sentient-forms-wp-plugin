import { expect, test } from '@playwright/test';
import type { Page, Route } from '@playwright/test';
import {
	configureGravityActionMapping,
	ensureGravityForm,
	getGravityEntryFieldValue,
	getGravityFormFields,
	getLatestEntryId,
	type RealtimeSettings,
	requireWpRestHealthy,
	waitForPreviewInputs
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';
import { saveGreenlightScreenshot } from './utils/greenlight-artifacts';
import { loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';
const formTitle = `Playwright Realtime Suggestions ${Date.now()}`;
const fields = [
	{ type: 'text', id: 1, label: 'Issue summary', isRequired: true },
	{ type: 'text', id: 2, label: 'Current context', isRequired: false },
	{ type: 'hidden', id: 9, label: 'Sentient Forms Clarification Q&A', isRequired: false },
	{ type: 'page', id: 3, label: 'Page break' },
	{ type: 'textarea', id: 4, label: 'Mitigation details', isRequired: false }
];

let formId = 0;

function isSuggestRequestUrl(url: URL): boolean {
	const decodedSearch = decodeURIComponent(url.search);
	return url.pathname.includes('/actions/suggest') || decodedSearch.includes('/actions/suggest');
}

async function routeSuggestRequests(
	page: Page,
	handler: (route: Route) => Promise<void>
): Promise<void> {
	await page.route((url) => isSuggestRequestUrl(url), handler);
}

async function openRealtimeWidget(page: Page) {
	const widget = page.locator('.sentient-forms-realtime-widget');
	const toggle = widget.locator('[data-role="toggle"]');
	await expect(widget).toBeVisible();
	if ((await toggle.textContent())?.trim() === 'Show') {
		await toggle.click();
	}
	await expect(toggle).toHaveText('Hide');
	await expect(widget.locator('[data-role="body"]')).toBeVisible();
	return widget;
}

async function openRealtimePreview(
	page: Page,
	manualRefreshEnabled = true,
	realtimeOverrides: Partial<RealtimeSettings> = {}
) {
	if (!formId) {
		formId = ensureGravityForm(formTitle, fields);
	}

	await openRealtimePreviewForForm(page, formId, manualRefreshEnabled, realtimeOverrides);
}

async function openRealtimePreviewForForm(
	page: Page,
	targetFormId: number,
	manualRefreshEnabled = true,
	realtimeOverrides: Partial<RealtimeSettings> = {}
) {
	configureGravityActionMapping({
		formId: targetFormId,
		actionId: 'realtime_suggest',
		localMappingId: 'map_realtime_suggest',
		centralActionId: 'clarification_assistant_v1',
		actionNameLabel: 'Realtime Clarification',
		hooks: ['gform_validation'],
		async: false,
		rejectSubmission: false,
		markAsSpam: false,
		executionPriority: 1,
		actionTypeIndicator: 'master',
		executionMode: 'real_time',
		realtimeSettings: {
			checkpointFieldIds: ['1'],
			refreshMode: 'checkpoint',
			debounceMs: 300,
			cooldownMs: 1000,
			manualRefreshEnabled,
			...realtimeOverrides
		}
	});

	await loginToWpAdmin(page);
	await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${targetFormId}`, {
		waitUntil: 'domcontentloaded'
	});
	await waitForPreviewInputs(page, targetFormId);
	await expect(page.locator('.sentient-forms-realtime-widget')).toBeVisible();
	await expect(page.locator('.sentient-forms-realtime-widget [data-role="toggle"]')).toHaveText(
		'Show'
	);
	await expect(page.locator('.sentient-forms-realtime-widget [data-role="body"]')).toBeHidden();
}

test.describe('Gravity Forms realtime suggestions @realtime-suggestions', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise realtime suggestion flows.');

	test.beforeEach(async ({ page }) => {
		if (runWpE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('checkpoint gating + visible rendering + page-change suggestions + focus hotlink', async ({
		page
	}) => {
		const requests: Array<Record<string, unknown>> = [];

		await routeSuggestRequests(page, async (route) => {
			const request = route.request();
			const payload = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;
			requests.push(payload);
			const currentPage = Number(payload.current_page_index ?? 1);
			const responseBody =
				currentPage >= 2
					? {
							status: 'success',
							suggestions: [
								{
									suggestion_id: 'a4017ddc-c7f9-4850-9ef0-6d58f9ecf821',
									field_id: '4',
									severity: 'warning',
									message: 'Provide mitigation details on page 2.',
									jump_target_field_id: '4',
									is_suppressed: false
								}
							],
							meta: { execution_request_id: 'rt-page-2' }
						}
					: {
							status: 'success',
							suggestions: [
								{
									suggestion_id: '0a0a57f6-5437-4cf9-8d57-c9cf21af9695',
									field_id: '1',
									severity: 'warning',
									message: 'Please add specifics to your issue summary.',
									jump_target_field_id: '1',
									is_suppressed: false
								},
								{
									suggestion_id: '6a08bc82-8672-4797-b6fd-8940cd95ff6b',
									field_id: '4',
									severity: 'info',
									message: 'This hidden field should not render on page 1.',
									jump_target_field_id: '4',
									is_suppressed: false
								},
								{
									suggestion_id: '7f550875-c9d7-4ef6-b1ee-9600952d1110',
									field_id: '1',
									severity: 'info',
									message: 'Suppressed future-mitigated guidance.',
									jump_target_field_id: '1',
									is_suppressed: true,
									suppression_reason: 'mitigated_by_future_field'
								}
							],
							meta: {
								execution_request_id: 'rt-page-1',
								correlation_id: 'rt-page-1',
								credits_debited: 3
							}
						};

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(responseBody)
			});
		});

		await openRealtimePreview(page, true, {
			pageCheckpointsEnabled: true,
			pageCheckpointMode: 'include_pages',
			pageCheckpointPages: [2]
		});

		await page.fill('input[name="input_2"]', 'non-checkpoint text');
		await page.locator('input[name="input_1"]').click();
		await page.waitForTimeout(700);
		expect(requests.length).toBe(0);

		await page.fill('input[name="input_1"]', 'Need detailed help');
		await page.locator('input[name="input_2"]').click();

		await expect.poll(() => requests.length, { timeout: 4000 }).toBe(1);
		expect(requests[0]?.mapping_id).toBe('map_realtime_suggest');

		const widget = await openRealtimeWidget(page);
		await expect(widget).toContainText('Please add specifics to your issue summary.');
		await expect(widget).not.toContainText('This hidden field should not render on page 1.');
		await expect(widget).not.toContainText('Suppressed future-mitigated guidance.');
		await expect(widget).toContainText('Credits: 3');
		await expect(widget).not.toContainText('Run:');
		await expect(widget).not.toContainText('rt-page-1');

		const nextButton = page.locator('.gform_next_button').first();
		await expect(nextButton).toBeVisible();
		await nextButton.click();

		await expect(page.locator('textarea[name="input_4"]')).toBeVisible();
		await expect.poll(() => requests.length, { timeout: 4000 }).toBe(2);
		expect(requests[1]?.current_page_index).toBe(2);
		await openRealtimeWidget(page);
		await expect(widget).toContainText('Provide mitigation details on page 2.');

		const focusButton = widget.locator('.sentient-forms-realtime-widget__focus[data-field-id="4"]');
		await focusButton.click();
		await expect(page.locator('textarea[name="input_4"]')).toBeFocused();
	});

	test('manual refresh button respects mapping policy when disabled', async ({ page }) => {
		const requests: Array<Record<string, unknown>> = [];
		await routeSuggestRequests(page, async (route) => {
			const request = route.request();
			requests.push(JSON.parse(request.postData() ?? '{}') as Record<string, unknown>);
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'success',
					suggestions: [],
					meta: { execution_request_id: 'rt-manual-disabled' }
				})
			});
		});

		await openRealtimePreview(page, false);

		await page.fill('input[name="input_1"]', 'checkpoint trigger');
		await page.locator('input[name="input_2"]').click();
		await expect.poll(() => requests.length, { timeout: 4000 }).toBe(1);

		const widget = await openRealtimeWidget(page);
		await expect(widget.locator('.sentient-forms-realtime-widget__refresh')).toBeHidden();
		await page.waitForTimeout(700);
		expect(requests.length).toBe(1);
	});

	test('hidden-until-interaction keeps the realtime widget hidden until the visitor edits the form', async ({
		page
	}) => {
		const hiddenFormId = ensureGravityForm(`Playwright Realtime Hidden ${Date.now()}`, fields);
		await routeSuggestRequests(page, async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'success',
					suggestions: [],
					meta: { execution_request_id: 'rt-hidden-until-interaction' }
				})
			});
		});

		configureGravityActionMapping({
			formId: hiddenFormId,
			actionId: 'realtime_hidden',
			localMappingId: 'map_realtime_hidden',
			centralActionId: 'clarification_assistant_v1',
			actionNameLabel: 'Realtime Clarification',
			hooks: ['gform_validation'],
			async: false,
			rejectSubmission: false,
			markAsSpam: false,
			executionPriority: 1,
			actionTypeIndicator: 'master',
			executionMode: 'real_time',
			realtimeSettings: {
				checkpointFieldIds: ['1'],
				refreshMode: 'checkpoint',
				debounceMs: 300,
				cooldownMs: 1000,
				manualRefreshEnabled: true,
				initialPanelState: 'hidden_until_interaction'
			}
		});

		await loginToWpAdmin(page);
		await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${hiddenFormId}`, {
			waitUntil: 'domcontentloaded'
		});
		await waitForPreviewInputs(page, hiddenFormId);

		const widget = page.locator('.sentient-forms-realtime-widget');
		await expect(widget).toBeHidden();

		await page.fill('input[name="input_1"]', 'Need more guidance');
		await expect(widget).toBeVisible();
		await expect(widget.locator('[data-role="toggle"]')).toHaveText('Show');
	});

	test('virtual questions persist exact Q&A and can block next-page navigation', async ({
		page
	}) => {
		await routeSuggestRequests(page, async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'success',
					suggestions: [],
					virtual_questions: [
						{
							question_id: 'url-context',
							question: 'What page URL did this occur on?',
							reason: 'The site owner needs the exact page to reproduce the issue.',
							target_field_id: '1',
							required: true,
							answer_type: 'short_text'
						}
					],
					meta: { execution_request_id: 'rt-virtual-qna' }
				})
			});
		});

		await openRealtimePreview(page, true, {
			storageTargetFieldId: '9',
			blockingMode: 'require_answers'
		});

		await page.fill('input[name="input_1"]', 'The button is broken.');
		await page.locator('input[name="input_2"]').click();
		const widget = await openRealtimeWidget(page);
		await expect(widget).toContainText('What page URL did this occur on?');

		const nextButton = page.locator('.gform_next_button').first();
		await nextButton.click();
		await expect(page.locator('input[name="input_1"]')).toBeVisible();
		await expect(page.locator('textarea[name="input_4"]')).toBeHidden();
		await expect(widget.locator('.sentient-forms-realtime-widget__error')).toContainText(
			'Answer the required follow-up questions before continuing.'
		);

		await page
			.locator('[data-role="answer-question"][data-question-id="url-context"]')
			.fill('https://example.test/support');
		const stored = await page
			.locator('input[name="input_9"], textarea[name="input_9"]')
			.inputValue();
		const parsed = JSON.parse(stored) as {
			schema: string;
			mappings: Array<{ questions: Array<{ question: string; answer: string }> }>;
		};
		expect(parsed.schema).toBe('sentient_forms_realtime_clarification_qna.v1');
		expect(parsed.mappings[0]?.questions[0]?.question).toBe('What page URL did this occur on?');
		expect(parsed.mappings[0]?.questions[0]?.answer).toBe('https://example.test/support');

		await nextButton.click();
		await expect(page.locator('textarea[name="input_4"]')).toBeVisible();
	});

	test('virtual questions are submitted as native Gravity Forms data without manual storage setup', async ({
		page
	}) => {
		const autoStorageFormId = ensureGravityForm(`Playwright Realtime Auto Storage ${Date.now()}`, [
			{ type: 'text', id: 1, label: 'Issue summary', isRequired: true },
			{ type: 'text', id: 2, label: 'Current context', isRequired: false },
			{ type: 'page', id: 3, label: 'Page break' },
			{ type: 'textarea', id: 4, label: 'Mitigation details', isRequired: false }
		]);
		const baselineEntryId = getLatestEntryId(autoStorageFormId);

		await routeSuggestRequests(page, async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'success',
					suggestions: [],
					virtual_questions: [
						{
							question_id: 'preferred-contact-window',
							question: 'What is the best time for us to follow up?',
							reason: 'The site owner needs an actionable callback window.',
							target_field_id: '2',
							required: true,
							answer_type: 'short_text'
						}
					],
					meta: { execution_request_id: 'rt-auto-native-qna' }
				})
			});
		});

		await openRealtimePreviewForForm(page, autoStorageFormId, true, {
			blockingMode: 'require_answers'
		});

		const storageField = getGravityFormFields(autoStorageFormId).find(
			(field) =>
				field.type === 'hidden' &&
				field.inputName === 'sentient_forms_realtime_qna' &&
				field.label === 'Sentient Forms Realtime Q&A'
		);
		expect(storageField, 'auto-provisioned native Gravity Forms storage field').toBeTruthy();
		expect(storageField?.id).toMatch(/^\d+$/);
		if (!storageField) {
			throw new Error('Realtime storage field was not auto-provisioned.');
		}
		const storageFieldId = storageField.id;

		await page.fill('input[name="input_1"]', 'The signup flow needs help.');
		await page.locator('input[name="input_2"]').click();
		const widget = await openRealtimeWidget(page);
		await expect(widget).toContainText('What is the best time for us to follow up?');

		await page
			.locator('[data-role="answer-question"][data-question-id="preferred-contact-window"]')
			.fill('Weekdays after 2 PM Central');

		const storageInput = page.locator(`input[name="input_${storageFieldId}"]`);
		await expect(storageInput).toHaveValue(/sentient_forms_realtime_clarification_qna\.v1/);

		await page.locator('.gform_next_button').first().click();
		await expect(page.locator('textarea[name="input_4"]')).toBeVisible();
		await page.fill('textarea[name="input_4"]', 'Please route this to the implementation team.');
		await page.locator('input[type="submit"], button[type="submit"]').last().click();

		await expect
			.poll(() => getLatestEntryId(autoStorageFormId), { timeout: 8000 })
			.toBeGreaterThan(baselineEntryId);
		const entryId = getLatestEntryId(autoStorageFormId);
		const storedValue = getGravityEntryFieldValue(entryId, storageFieldId);
		expect(storedValue, 'native Gravity Forms entry field value').toBeTruthy();

		const parsed = JSON.parse(storedValue ?? '') as {
			schema: string;
			mappings: Array<{ questions: Array<{ question: string; answer: string }> }>;
		};
		expect(parsed.schema).toBe('sentient_forms_realtime_clarification_qna.v1');
		expect(parsed.mappings[0]?.questions[0]?.question).toBe(
			'What is the best time for us to follow up?'
		);
		expect(parsed.mappings[0]?.questions[0]?.answer).toBe('Weekdays after 2 PM Central');
	});

	test('429 suggest response is non-blocking and surfaces retry feedback', async ({ page }) => {
		await routeSuggestRequests(page, async (route) => {
			await route.fulfill({
				status: 429,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'Suggestion rate limit exceeded. Please wait and retry.' })
			});
		});

		await openRealtimePreview(page, true);

		await page.fill('input[name="input_1"]', 'checkpoint trigger');
		await page.locator('input[name="input_2"]').click();
		const widget = await openRealtimeWidget(page);
		await expect(widget.locator('.sentient-forms-realtime-widget__error')).toContainText(
			'Suggestion rate limit exceeded. Please wait and retry.'
		);

		const nextButton = page.locator('.gform_next_button').first();
		await expect(nextButton).toBeEnabled();
		await nextButton.click();
		await expect(page.locator('textarea[name="input_4"]')).toBeVisible();
	});

	test('Cloudflare challenge suggest response is non-blocking and visitor-safe', async ({
		page
	}) => {
		await routeSuggestRequests(page, async (route) => {
			await route.fulfill({
				status: 403,
				headers: {
					'content-type': 'text/html; charset=UTF-8',
					'cf-mitigated': 'challenge',
					'cf-ray': '89abc12345def678-ORD',
					server: 'cloudflare'
				},
				body: '<!doctype html><html><title>Just a moment...</title><body>Checking if the site connection is secure.</body></html>'
			});
		});

		await openRealtimePreview(page, true);

		await page.fill('input[name="input_1"]', 'checkpoint trigger');
		await page.locator('input[name="input_2"]').click();
		const widget = await openRealtimeWidget(page);
		const error = widget.locator('.sentient-forms-realtime-widget__error');
		await expect(error).toContainText(
			'this request reached the site security layer before WordPress could process it'
		);
		await expect(error).toContainText('You can keep filling out the form');
		await expect(error).not.toContainText('Cloudflare');
		await expect(error).not.toContainText('Ray');
		await expect(error).not.toContainText('89abc12345def678-ORD');

		await saveGreenlightScreenshot(widget, 'realtime-cloudflare-challenge-visitor-safe');

		const nextButton = page.locator('.gform_next_button').first();
		await expect(nextButton).toBeEnabled();
		await nextButton.click();
		await expect(page.locator('textarea[name="input_4"]')).toBeVisible();
	});

	test('expired realtime config fails open before sending a stale nonce request', async ({
		page
	}) => {
		let suggestRequests = 0;
		await routeSuggestRequests(page, async (route) => {
			suggestRequests += 1;
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ status: 'success', suggestions: [] })
			});
		});

		await openRealtimePreview(page, true);
		await page.evaluate((targetFormId) => {
			const runtime = (
				window as typeof window & {
					__sentientRealtimeSuggestionsRuntime?: {
						forms?: Record<string, { config?: { config_expires_at?: number } }>;
					};
				}
			).__sentientRealtimeSuggestionsRuntime;
			const formState = runtime?.forms?.[String(targetFormId)];
			if (!formState?.config) {
				throw new Error(`Realtime runtime state missing for form ${targetFormId}`);
			}
			formState.config.config_expires_at = Math.floor(Date.now() / 1000) - 60;
		}, formId);

		await page.fill('input[name="input_1"]', 'checkpoint trigger');
		await page.locator('input[name="input_2"]').click();
		const widget = await openRealtimeWidget(page);
		const error = widget.locator('.sentient-forms-realtime-widget__error');
		await expect(error).toContainText('cached page is using an expired security token');
		await expect(error).toContainText('Reload this page before trying again.');
		await page.waitForTimeout(400);
		expect(suggestRequests).toBe(0);

		await saveGreenlightScreenshot(widget, 'realtime-expired-config-fails-open');

		const nextButton = page.locator('.gform_next_button').first();
		await expect(nextButton).toBeEnabled();
		await nextButton.click();
		await expect(page.locator('textarea[name="input_4"]')).toBeVisible();
	});

	test('raw provider schema failures are sanitized and do not block form progress', async ({
		page
	}) => {
		await routeSuggestRequests(page, async (route) => {
			await route.fulfill({
				status: 502,
				contentType: 'application/json',
				body: JSON.stringify({
					message:
						'The provider response did not match the local action schema: virtual_questions is a required property of structured_output.'
				})
			});
		});

		await openRealtimePreview(page, true);

		await page.fill('input[name="input_1"]', 'checkpoint trigger');
		await page.locator('input[name="input_2"]').click();
		const widget = await openRealtimeWidget(page);
		const error = widget.locator('.sentient-forms-realtime-widget__error');
		await expect(error).toContainText('Suggestions are temporarily unavailable. Try again shortly.');
		await expect(error).not.toContainText('virtual_questions');
		await expect(error).not.toContainText('structured_output');
		await expect(error).not.toContainText('local action schema');

		const nextButton = page.locator('.gform_next_button').first();
		await expect(nextButton).toBeEnabled();
		await nextButton.click();
		await expect(page.locator('textarea[name="input_4"]')).toBeVisible();
	});
});
